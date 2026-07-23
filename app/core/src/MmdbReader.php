<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

use RuntimeException;

/**
 * Small built-in MaxMind DB reader used when the optional official Composer
 * package is unavailable. It implements the MaxMind DB binary format needed
 * by GeoLite2 City and ASN databases so the Native package works without SSH
 * or Composer.
 */
final class MmdbReader
{
    private const METADATA_MARKER = "\xAB\xCD\xEFMaxMind.com";
    private const METADATA_MAX_SIZE = 131072;
    private const DATA_SEPARATOR_SIZE = 16;

    /** @var resource */
    private $handle;
    private int $fileSize;
    /** @var array<string,mixed> */
    private array $metadata;
    private MmdbDecoder $decoder;
    private int $nodeCount;
    private int $recordSize;
    private int $nodeByteSize;
    private int $searchTreeSize;
    private int $ipVersion;
    private int $ipv4Start = 0;

    public function __construct(private readonly string $database)
    {
        if ($database === '' || !is_file($database) || !is_readable($database)) {
            throw new MmdbInvalidDatabaseException('MMDB file does not exist or is not readable: ' . $database);
        }

        $handle = @fopen($database, 'rb');
        if ($handle === false) {
            throw new MmdbInvalidDatabaseException('Could not open MMDB file: ' . $database);
        }
        $this->handle = $handle;

        $stat = fstat($handle);
        if ($stat === false || !isset($stat['size'])) {
            throw new MmdbInvalidDatabaseException('Could not determine MMDB file size.');
        }
        $this->fileSize = (int)$stat['size'];

        $metadataOffset = $this->findMetadataOffset();
        $metadataDecoder = new MmdbDecoder($this->handle, $metadataOffset);
        [$metadata] = $metadataDecoder->decode($metadataOffset);
        if (!is_array($metadata)) {
            throw new MmdbInvalidDatabaseException('MMDB metadata is not a map.');
        }
        $this->metadata = $metadata;
        $this->nodeCount = self::positiveInt($metadata['node_count'] ?? null, 'node_count');
        $this->recordSize = self::positiveInt($metadata['record_size'] ?? null, 'record_size');
        $this->ipVersion = self::positiveInt($metadata['ip_version'] ?? null, 'ip_version');

        if (!in_array($this->recordSize, [24, 28, 32], true)) {
            throw new MmdbInvalidDatabaseException('Unsupported MMDB record size: ' . $this->recordSize);
        }
        if (!in_array($this->ipVersion, [4, 6], true)) {
            throw new MmdbInvalidDatabaseException('Unsupported MMDB IP version: ' . $this->ipVersion);
        }

        $this->nodeByteSize = intdiv($this->recordSize, 4);
        $this->searchTreeSize = $this->nodeCount * $this->nodeByteSize;
        $this->decoder = new MmdbDecoder(
            $this->handle,
            $this->searchTreeSize + self::DATA_SEPARATOR_SIZE
        );
        $this->ipv4Start = $this->findIpv4Start();
    }

    /** @return mixed */
    public function get(string $ip)
    {
        [$pointer] = $this->findAddress($ip);
        if ($pointer === 0) {
            return null;
        }

        $resolved = $pointer - $this->nodeCount + $this->searchTreeSize;
        if ($resolved < 0 || $resolved >= $this->fileSize) {
            throw new MmdbInvalidDatabaseException('MMDB search tree points outside the file.');
        }
        [$value] = $this->decoder->decode($resolved);
        return $value;
    }

    /** @return array<string,mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return array{0:int,1:int} */
    private function findAddress(string $ip): array
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            throw new \InvalidArgumentException('Invalid IP address: ' . $ip);
        }
        $bytes = unpack('C*', $packed);
        if ($bytes === false) {
            throw new MmdbInvalidDatabaseException('Could not unpack IP address.');
        }

        $bitCount = count($bytes) * 8;
        if ($this->ipVersion === 4 && $bitCount === 128) {
            throw new \InvalidArgumentException('IPv6 address supplied to an IPv4-only MMDB.');
        }

        $node = ($this->ipVersion === 6 && $bitCount === 32) ? $this->ipv4Start : 0;
        $i = 0;
        for (; $i < $bitCount && $node < $this->nodeCount; $i++) {
            $byte = $bytes[intdiv($i, 8) + 1];
            $bit = ($byte >> (7 - ($i % 8))) & 1;
            $node = $this->readNode($node, $bit);
        }

        if ($node === $this->nodeCount) {
            return [0, $i];
        }
        if ($node > $this->nodeCount) {
            return [$node, $i];
        }
        throw new MmdbInvalidDatabaseException('MMDB tree ended before reaching a leaf.');
    }

    private function findIpv4Start(): int
    {
        if ($this->ipVersion === 4) {
            return 0;
        }
        $node = 0;
        for ($i = 0; $i < 96 && $node < $this->nodeCount; $i++) {
            $node = $this->readNode($node, 0);
        }
        return $node;
    }

    private function readNode(int $nodeNumber, int $side): int
    {
        $base = $nodeNumber * $this->nodeByteSize;
        if ($this->recordSize === 24) {
            $bytes = MmdbDecoder::read($this->handle, $base + $side * 3, 3);
            return self::bigEndianInt($bytes);
        }
        if ($this->recordSize === 28) {
            $bytes = MmdbDecoder::read($this->handle, $base, 7);
            if ($side === 0) {
                return ((ord($bytes[3]) & 0xF0) << 20) | self::bigEndianInt(substr($bytes, 0, 3));
            }
            return ((ord($bytes[3]) & 0x0F) << 24) | self::bigEndianInt(substr($bytes, 4, 3));
        }
        $bytes = MmdbDecoder::read($this->handle, $base + $side * 4, 4);
        return self::bigEndianInt($bytes);
    }

    private function findMetadataOffset(): int
    {
        $markerLength = strlen(self::METADATA_MARKER);
        $minimum = max(0, $this->fileSize - self::METADATA_MAX_SIZE);
        for ($offset = $this->fileSize - $markerLength; $offset >= $minimum; $offset--) {
            if (fseek($this->handle, $offset) !== 0) {
                break;
            }
            $candidate = fread($this->handle, $markerLength);
            if ($candidate === self::METADATA_MARKER) {
                return $offset + $markerLength;
            }
        }
        throw new MmdbInvalidDatabaseException('Could not find MMDB metadata marker.');
    }

    private static function positiveInt(mixed $value, string $name): int
    {
        if (!is_int($value) && !is_numeric($value)) {
            throw new MmdbInvalidDatabaseException('MMDB metadata field is invalid: ' . $name);
        }
        $value = (int)$value;
        if ($value <= 0) {
            throw new MmdbInvalidDatabaseException('MMDB metadata field must be positive: ' . $name);
        }
        return $value;
    }

    private static function bigEndianInt(string $bytes): int
    {
        $value = 0;
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }
        return $value;
    }
}

final class MmdbDecoder
{
    private const TYPE_EXTENDED = 0;
    private const TYPE_POINTER = 1;
    private const TYPE_UTF8 = 2;
    private const TYPE_DOUBLE = 3;
    private const TYPE_BYTES = 4;
    private const TYPE_UINT16 = 5;
    private const TYPE_UINT32 = 6;
    private const TYPE_MAP = 7;
    private const TYPE_INT32 = 8;
    private const TYPE_UINT64 = 9;
    private const TYPE_UINT128 = 10;
    private const TYPE_ARRAY = 11;
    private const TYPE_BOOLEAN = 14;
    private const TYPE_FLOAT = 15;

    /** @var resource */
    private $stream;

    /** @param resource $stream */
    public function __construct($stream, private readonly int $pointerBase)
    {
        $this->stream = $stream;
    }

    /** @return array{0:mixed,1:int} */
    public function decode(int $offset): array
    {
        $control = ord(self::read($this->stream, $offset, 1));
        $offset++;
        $type = $control >> 5;

        if ($type === self::TYPE_POINTER) {
            return $this->decodePointer($control, $offset);
        }

        if ($type === self::TYPE_EXTENDED) {
            $type = ord(self::read($this->stream, $offset, 1)) + 7;
            $offset++;
            if ($type < 8) {
                throw new MmdbInvalidDatabaseException('Invalid MMDB extended data type.');
            }
        }

        [$size, $offset] = $this->decodeSize($control & 0x1F, $offset);
        return $this->decodeValue($type, $size, $offset);
    }

    /** @return array{0:mixed,1:int} */
    private function decodePointer(int $control, int $offset): array
    {
        $pointerSize = (($control >> 3) & 0x03) + 1;
        $bytes = self::read($this->stream, $offset, $pointerSize);
        $nextOffset = $offset + $pointerSize;
        $high = $control & 0x07;

        if ($pointerSize === 1) {
            $pointer = ($high << 8) | ord($bytes[0]);
            $base = 0;
        } elseif ($pointerSize === 2) {
            $pointer = ($high << 16) | self::bytesToInt($bytes);
            $base = 2048;
        } elseif ($pointerSize === 3) {
            $pointer = ($high << 24) | self::bytesToInt($bytes);
            $base = 526336;
        } else {
            $pointer = self::bytesToInt($bytes);
            $base = 0;
        }

        [$value] = $this->decode($this->pointerBase + $base + $pointer);
        return [$value, $nextOffset];
    }

    /** @return array{0:int,1:int} */
    private function decodeSize(int $size, int $offset): array
    {
        if ($size < 29) {
            return [$size, $offset];
        }
        if ($size === 29) {
            return [29 + ord(self::read($this->stream, $offset, 1)), $offset + 1];
        }
        if ($size === 30) {
            return [285 + self::bytesToInt(self::read($this->stream, $offset, 2)), $offset + 2];
        }
        return [65821 + self::bytesToInt(self::read($this->stream, $offset, 3)), $offset + 3];
    }

    /** @return array{0:mixed,1:int} */
    private function decodeValue(int $type, int $size, int $offset): array
    {
        if ($type === self::TYPE_MAP) {
            $map = [];
            for ($i = 0; $i < $size; $i++) {
                [$key, $offset] = $this->decode($offset);
                if (!is_string($key) && !is_int($key)) {
                    throw new MmdbInvalidDatabaseException('MMDB map key is not a scalar.');
                }
                [$value, $offset] = $this->decode($offset);
                $map[(string)$key] = $value;
            }
            return [$map, $offset];
        }

        if ($type === self::TYPE_ARRAY) {
            $array = [];
            for ($i = 0; $i < $size; $i++) {
                [$value, $offset] = $this->decode($offset);
                $array[] = $value;
            }
            return [$array, $offset];
        }

        if ($type === self::TYPE_BOOLEAN) {
            if ($size > 1) {
                throw new MmdbInvalidDatabaseException('Invalid MMDB boolean size.');
            }
            return [$size === 1, $offset];
        }

        $bytes = self::read($this->stream, $offset, $size);
        $nextOffset = $offset + $size;

        return match ($type) {
            self::TYPE_UTF8 => [$bytes, $nextOffset],
            self::TYPE_BYTES => [$bytes, $nextOffset],
            self::TYPE_UINT16, self::TYPE_UINT32, self::TYPE_UINT64 => [self::unsignedInteger($bytes), $nextOffset],
            self::TYPE_UINT128 => [self::unsigned128($bytes), $nextOffset],
            self::TYPE_INT32 => [self::signedInteger($bytes), $nextOffset],
            self::TYPE_DOUBLE => [self::decodeDouble($bytes), $nextOffset],
            self::TYPE_FLOAT => [self::decodeFloat($bytes), $nextOffset],
            default => throw new MmdbInvalidDatabaseException('Unsupported MMDB data type: ' . $type),
        };
    }

    /** @param resource $stream */
    public static function read($stream, int $offset, int $length): string
    {
        if ($length === 0) {
            return '';
        }
        if ($offset < 0 || $length < 0 || fseek($stream, $offset) !== 0) {
            throw new MmdbInvalidDatabaseException('Could not seek in MMDB file.');
        }
        $value = fread($stream, $length);
        if ($value === false || strlen($value) !== $length) {
            throw new MmdbInvalidDatabaseException('Unexpected end of MMDB file.');
        }
        return $value;
    }

    private static function bytesToInt(string $bytes): int
    {
        $value = 0;
        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }
        return $value;
    }

    private static function unsignedInteger(string $bytes): int|string
    {
        if ($bytes === '') {
            return 0;
        }
        $value = 0;
        $overflow = false;
        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $byte = ord($bytes[$i]);
            if ($value > intdiv(PHP_INT_MAX - $byte, 256)) {
                $overflow = true;
                break;
            }
            $value = $value * 256 + $byte;
        }
        if (!$overflow) {
            return $value;
        }
        return self::decimalFromBytes($bytes);
    }

    private static function signedInteger(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }
        $unsigned = self::bytesToInt($bytes);
        $bits = strlen($bytes) * 8;
        $sign = 1 << ($bits - 1);
        return ($unsigned & $sign) !== 0 ? $unsigned - (1 << $bits) : $unsigned;
    }

    private static function unsigned128(string $bytes): string|int
    {
        if (strlen($bytes) <= PHP_INT_SIZE) {
            return self::unsignedInteger($bytes);
        }
        return self::decimalFromBytes($bytes);
    }

    private static function decimalFromBytes(string $bytes): string
    {
        $digits = [0];
        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $carry = ord($bytes[$i]);
            foreach ($digits as $index => $digit) {
                $value = $digit * 256 + $carry;
                $digits[$index] = $value % 10;
                $carry = intdiv($value, 10);
            }
            while ($carry > 0) {
                $digits[] = $carry % 10;
                $carry = intdiv($carry, 10);
            }
        }
        return implode('', array_reverse($digits));
    }

    private static function decodeDouble(string $bytes): float
    {
        if (strlen($bytes) !== 8) {
            throw new MmdbInvalidDatabaseException('Invalid MMDB double size.');
        }
        $value = unpack('Evalue', $bytes);
        if ($value === false) {
            throw new MmdbInvalidDatabaseException('Could not decode MMDB double.');
        }
        return (float)$value['value'];
    }

    private static function decodeFloat(string $bytes): float
    {
        if (strlen($bytes) !== 4) {
            throw new MmdbInvalidDatabaseException('Invalid MMDB float size.');
        }
        $value = unpack('Gvalue', $bytes);
        if ($value === false) {
            throw new MmdbInvalidDatabaseException('Could not decode MMDB float.');
        }
        return (float)$value['value'];
    }
}

final class MmdbInvalidDatabaseException extends RuntimeException
{
}
