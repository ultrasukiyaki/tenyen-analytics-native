<?php

declare(strict_types=1);

use Tenyen\Analytics\Installer;

$root = dirname(__DIR__, 3);
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'セットアップが完了していません。']);
    exit;
}
$config = require $configFile;
if (!is_array($config)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'config.php must return an array.']);
    exit;
}
require_once $root . '/app/admin-auth.php';
tyaa_require_auth($config, true);

function mmdb_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mmdb_json(['ok' => false, 'message' => 'POST only.'], 405);
}
if (!tyaa_verify_csrf((string)($_POST['csrf'] ?? ''))) {
    mmdb_json(['ok' => false, 'message' => 'The login session has expired. Reload the administration page.'], 419);
}

$kind = (string)($_POST['kind'] ?? '');
$defaultFilename = $kind === 'city' ? 'GeoLite2-City.mmdb' : ($kind === 'asn' ? 'GeoLite2-ASN.mmdb' : '');
if ($defaultFilename === '') {
    mmdb_json(['ok' => false, 'message' => 'MMDBの種類が不正です。'], 400);
}

$uploadId = strtolower((string)($_POST['upload_id'] ?? ''));
if (!preg_match('/^[a-f0-9-]{16,64}$/', $uploadId)) {
    mmdb_json(['ok' => false, 'message' => 'アップロードIDが不正です。'], 400);
}

$index = filter_var($_POST['chunk_index'] ?? null, FILTER_VALIDATE_INT);
$totalChunks = filter_var($_POST['total_chunks'] ?? null, FILTER_VALIDATE_INT);
$totalSize = filter_var($_POST['total_size'] ?? null, FILTER_VALIDATE_INT);
$offset = filter_var($_POST['offset'] ?? null, FILTER_VALIDATE_INT);
if ($index === false || $totalChunks === false || $totalSize === false || $offset === false
    || $index < 0 || $totalChunks < 1 || $totalChunks > 1000 || $index >= $totalChunks
    || $totalSize < 1024 || $totalSize > 150 * 1024 * 1024 || $offset < 0 || $offset >= $totalSize) {
    mmdb_json(['ok' => false, 'message' => 'アップロード情報が不正です。'], 400);
}

$chunk = $_FILES['chunk'] ?? null;
if (!is_array($chunk) || (int)($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $code = is_array($chunk) ? (int)($chunk['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    mmdb_json(['ok' => false, 'message' => '分割ファイルを受信できませんでした（コード' . $code . '）。'], 400);
}
$chunkSize = (int)($chunk['size'] ?? 0);
if ($chunkSize < 1 || $chunkSize > 1024 * 1024) {
    mmdb_json(['ok' => false, 'message' => '分割サイズが不正です。'], 400);
}

$uploadDir = $root . '/storage/admin-upload';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0700, true) && !is_dir($uploadDir)) {
    mmdb_json(['ok' => false, 'message' => 'storage/admin-uploadを作成できません。'], 500);
}
if (!is_writable($uploadDir)) {
    mmdb_json(['ok' => false, 'message' => 'storage/admin-uploadへ書き込めません。'], 500);
}
foreach (glob($uploadDir . '/*.{part,json}', GLOB_BRACE) ?: [] as $stale) {
    if (is_file($stale) && filemtime($stale) !== false && filemtime($stale) < time() - 86400) {
        @unlink($stale);
    }
}

$key = hash('sha256', session_id() . '|' . $uploadId . '|' . $kind);
$partPath = $uploadDir . '/' . $key . '.part';
$statePath = $uploadDir . '/' . $key . '.json';
$state = ['next_index' => 0, 'bytes' => 0, 'total_size' => $totalSize, 'total_chunks' => $totalChunks, 'kind' => $kind];
if ($index > 0) {
    $decoded = is_file($statePath) ? json_decode((string)file_get_contents($statePath), true) : null;
    if (!is_array($decoded)) {
        mmdb_json(['ok' => false, 'message' => 'The upload state could not be restored. Start the upload again.'], 409);
    }
    $state = $decoded;
}
if ((int)$state['next_index'] !== $index || (int)$state['bytes'] !== $offset
    || (int)$state['total_size'] !== $totalSize || (int)$state['total_chunks'] !== $totalChunks
    || (string)$state['kind'] !== $kind) {
    mmdb_json(['ok' => false, 'message' => 'The chunk sequence does not match. Start the upload again.'], 409);
}

$source = fopen((string)$chunk['tmp_name'], 'rb');
$target = fopen($partPath, $index === 0 ? 'wb' : 'ab');
if ($source === false || $target === false) {
    if (is_resource($source)) fclose($source);
    if (is_resource($target)) fclose($target);
    mmdb_json(['ok' => false, 'message' => '一時ファイルを作成できません。'], 500);
}
if (!flock($target, LOCK_EX)) {
    fclose($source); fclose($target);
    mmdb_json(['ok' => false, 'message' => 'アップロードファイルをロックできません。'], 500);
}
$written = stream_copy_to_stream($source, $target);
fflush($target);
flock($target, LOCK_UN);
fclose($source);
fclose($target);
if ($written === false || $written !== $chunkSize) {
    mmdb_json(['ok' => false, 'message' => '分割ファイルの保存に失敗しました。'], 500);
}

$state['next_index'] = $index + 1;
$state['bytes'] = $offset + $chunkSize;
file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
if ($index + 1 !== $totalChunks) {
    mmdb_json(['ok' => true, 'complete' => false, 'received' => $state['bytes'], 'total' => $totalSize]);
}

if ((int)$state['bytes'] !== $totalSize || filesize($partPath) !== $totalSize) {
    @unlink($partPath); @unlink($statePath);
    mmdb_json(['ok' => false, 'message' => 'The received size does not match. Upload the file again.'], 400);
}

require_once $root . '/app/core/autoload.php';
$installer = new Installer($root);
$inspection = $installer->inspectMmdb($partPath, $kind);
if (!$inspection['ok']) {
    @unlink($partPath); @unlink($statePath);
    mmdb_json(['ok' => false, 'message' => $defaultFilename . '：' . $inspection['message']], 400);
}

$geo = $config['geoip'] ?? [];
$destination = (string)($kind === 'city' ? ($geo['city_database'] ?? '') : ($geo['asn_database'] ?? ''));
if ($destination === '') {
    $destination = $root . '/data/' . $defaultFilename;
}
$directory = dirname($destination);
if (!is_dir($directory) || !is_writable($directory)) {
    @unlink($partPath); @unlink($statePath);
    mmdb_json(['ok' => false, 'message' => '保存先へ書き込めません：' . $directory], 500);
}
if (!@rename($partPath, $destination)) {
    if (!@copy($partPath, $destination)) {
        @unlink($partPath); @unlink($statePath);
        mmdb_json(['ok' => false, 'message' => $defaultFilename . 'を保存できません。'], 500);
    }
    @unlink($partPath);
}
@chmod($destination, 0600);
@unlink($statePath);

mmdb_json([
    'ok' => true,
    'complete' => true,
    'kind' => $kind,
    'filename' => basename($destination),
    'type' => (string)$inspection['type'],
    'received' => $totalSize,
    'total' => $totalSize,
]);
