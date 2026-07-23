<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

final class RateLimiter
{
    public function __construct(
        private readonly string $directory,
        private readonly int $limit = 120,
        private readonly int $windowSeconds = 60
    ) {
    }

    public function allow(string $key): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return true;
        }

        $file = $this->directory . '/' . hash('sha256', $key) . '.json';
        $now = time();
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return true;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }
            $raw = stream_get_contents($handle);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($data) || ($data['start'] ?? 0) + $this->windowSeconds <= $now) {
                $data = ['start' => $now, 'count' => 0];
            }
            $data['count']++;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_UNESCAPED_SLASHES));
            fflush($handle);
            return $data['count'] <= $this->limit;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
