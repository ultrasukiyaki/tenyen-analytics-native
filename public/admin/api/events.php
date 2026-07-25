<?php

declare(strict_types=1);

use Tenyen\Analytics\OrganizationClassifier;

$root = dirname(__DIR__, 3);
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    tya_api_json(['ok' => false, 'message' => 'config.php is missing.'], 500);
}
$config = require $configFile;
if (!is_array($config)) {
    tya_api_json(['ok' => false, 'message' => 'config.php must return an array.'], 500);
}
require_once $root . '/app/admin-auth.php';
tyaa_require_auth($config, true);

function tya_api_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

function tya_api_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tya_api_safe_url(string $url): string
{
    $url = trim($url);
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

function tya_api_link(string $url, string $label): string
{
    $safe = tya_api_safe_url($url);
    return $safe === '' ? tya_api_h($label) : '<a class="out-link" target="_blank" rel="noopener noreferrer" href="' . tya_api_h($safe) . '">' . tya_api_h($label) . '</a>';
}

function tya_api_page_url(string $path, string $siteBaseUrl): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path)) {
        return tya_api_safe_url($path);
    }
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    return rtrim($siteBaseUrl, '/') . $path;
}

function tya_api_page_link(string $path, string $label, string $siteBaseUrl): string
{
    $label = trim($label) !== '' ? $label : $path;
    $url = tya_api_page_url($path, $siteBaseUrl);
    return $url === '' ? tya_api_h($label) : tya_api_link($url, $label);
}

function tya_api_referrer(string $url): string
{
    if ($url === '') {
        return 'Direct';
    }
    $host = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
    return tya_api_link($url, $host);
}

function tya_api_date(string $date): string
{
    $date = trim($date);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
        return '';
    }
    return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) ? $date : '';
}

function tya_api_date_utc(string $date, DateTimeZone $timezone, bool $exclusive): string
{
    $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    if (!$value instanceof DateTimeImmutable) {
        return '';
    }
    if ($exclusive) {
        $value = $value->modify('+1 day');
    }
    return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function tya_api_like(string $value): string
{
    return '%' . strtr($value, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
}

function tya_api_badge(array $classification): string
{
    return '<span class="badge badge--' . tya_api_h($classification['category']) . '" title="' . tya_api_h($classification['reason'] . ' / 確度 ' . $classification['confidence'] . '%') . '">' . tya_api_h($classification['icon'] . ' ' . $classification['label']) . '</span>';
}

function tya_api_duration(int $milliseconds): string
{
    $seconds = max(0, (int)round($milliseconds / 1000));
    if ($seconds < 60) {
        return $seconds . '秒';
    }
    $minutes = intdiv($seconds, 60);
    $remaining = $seconds % 60;
    if ($minutes < 60) {
        return $minutes . '分' . str_pad((string)$remaining, 2, '0', STR_PAD_LEFT) . '秒';
    }
    return intdiv($minutes, 60) . '時間' . ($minutes % 60) . '分';
}

function tya_api_pagination(int $current, int $pages): string
{
    if ($pages <= 1) {
        return '';
    }
    $numbers = [1, $pages];
    for ($number = max(1, $current - 2); $number <= min($pages, $current + 2); $number++) {
        $numbers[] = $number;
    }
    $numbers = array_values(array_unique($numbers));
    sort($numbers);
    $html = '<nav class="tya-history-pagination" aria-label="アクセス履歴ページング">';
    if ($current > 1) {
        $html .= '<a href="#" data-history-page="' . ($current - 1) . '">‹ 前へ</a>';
    }
    $previous = 0;
    foreach ($numbers as $number) {
        if ($previous !== 0 && $number > $previous + 1) {
            $html .= '<span class="ellipsis">…</span>';
        }
        $html .= $number === $current
            ? '<span class="current" aria-current="page">' . $number . '</span>'
            : '<a href="#" data-history-page="' . $number . '">' . $number . '</a>';
        $previous = $number;
    }
    if ($current < $pages) {
        $html .= '<a href="#" data-history-page="' . ($current + 1) . '">次へ ›</a>';
    }
    return $html . '</nav>';
}

$services = require $root . '/app/bootstrap.php';
$pdo = $services['pdo'];
$crypto = $services['crypto'];

$timezone = new DateTimeZone((string)($config['app']['timezone'] ?? 'Asia/Tokyo'));
$utc = new DateTimeZone('UTC');
$siteBaseUrl = rtrim((string)($config['app']['site_url'] ?? ''), '/');
if ($siteBaseUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $siteBaseUrl = $scheme . '://' . $host;
}

$query = trim((string)($_GET['q'] ?? ''));
$query = function_exists('mb_substr') ? mb_substr($query, 0, 255) : substr($query, 0, 255);
$event = (string)($_GET['event'] ?? 'all');
if (!in_array($event, ['all', 'pageview', 'engagement', 'external_click', 'download'], true)) {
    $event = 'all';
}
$actor = (string)($_GET['actor'] ?? 'human');
if (!in_array($actor, ['all', 'human', 'bot'], true)) {
    $actor = 'human';
}
$dateFrom = tya_api_date((string)($_GET['from'] ?? ''));
$dateTo = tya_api_date((string)($_GET['to'] ?? ''));
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$cleanExact = static function (mixed $value): string {
    $value = trim(strip_tags((string)$value));
    return function_exists('mb_substr') ? mb_substr($value, 0, 128) : substr($value, 0, 128);
};
$country = $cleanExact($_GET['country'] ?? '');
$browser = $cleanExact($_GET['browser'] ?? '');
$os = $cleanExact($_GET['os'] ?? '');
$device = $cleanExact($_GET['device'] ?? '');
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [25, 50, 100], true)) {
    $perPage = 25;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$order = strtolower((string)($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

$where = ['1 = 1'];
$params = [];
if ($event !== 'all') {
    $where[] = 'event_type = ?';
    $params[] = $event;
}
if ($actor === 'human') {
    $where[] = 'is_bot = 0';
} elseif ($actor === 'bot') {
    $where[] = 'is_bot = 1';
}
if ($dateFrom !== '') {
    $where[] = 'occurred_at >= ?';
    $params[] = tya_api_date_utc($dateFrom, $timezone, false);
}
if ($dateTo !== '') {
    $where[] = 'occurred_at < ?';
    $params[] = tya_api_date_utc($dateTo, $timezone, true);
}
foreach ([['country_name', $country], ['browser', $browser], ['os', $os], ['device_type', $device]] as [$column, $value]) {
    if ($value !== '') {
        $where[] = $column . ' = ?';
        $params[] = $value;
    }
}
if ($query !== '') {
    $or = [];
    $like = tya_api_like($query);
    foreach (['event_type', 'visitor_id', 'session_id', 'country_code', 'country_name', 'region', 'city', 'asn_org', 'path', 'page_title', 'referrer', 'target_url', 'user_agent', 'browser', 'os', 'device_type', 'language', 'timezone'] as $column) {
        $or[] = $column . " LIKE ? ESCAPE '='";
        $params[] = $like;
    }
    $or[] = "CAST(asn AS CHAR) LIKE ? ESCAPE '='";
    $params[] = $like;
    if (filter_var($query, FILTER_VALIDATE_IP) !== false) {
        $or[] = 'ip_hash = ?';
        $params[] = $crypto->hashIp($query);
    }
    if (preg_match('/^AS\s*(\d{1,10})$/i', $query, $matches)) {
        $or[] = 'asn = ?';
        $params[] = (int)$matches[1];
    }
    $where[] = '(' . implode(' OR ', $or) . ')';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM tya_events ' . $whereSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$listStmt = $pdo->prepare('SELECT * FROM tya_events ' . $whereSql . ' ORDER BY event_id ' . $order . ' LIMIT ' . $perPage . ' OFFSET ' . $offset);
$listStmt->execute($params);
$rows = $listStmt->fetchAll();
$overrides = (array)($config['app']['organization_overrides'] ?? []);

ob_start();
?>
<div class="tya-history-table-wrap">
<table class="tya-history-table">
<thead><tr>
<th data-col="datetime">日時</th><th data-col="event">種別</th><th data-col="ip">IP</th><th data-col="location">地域</th><th data-col="organization">ASN／法人候補</th><th data-col="page">ページ</th><th data-col="referrer">参照元</th><th data-col="environment">環境</th><th data-col="details">詳細</th>
</tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="9"><div class="tya-history-empty"><?= tya_api_h($services['translator']->get('history.empty')) ?></div></td></tr><?php endif; ?>
<?php foreach ($rows as $row):
    $ip = $crypto->decryptIp($row['ip_encrypted'] ?? '');
    $localTime = (new DateTimeImmutable((string)$row['occurred_at'], $utc))->setTimezone($timezone)->format('Y-m-d H:i:s');
    $location = implode(' / ', array_filter([$row['country_name'], $row['region'], $row['city']]));
    $asnText = trim(($row['asn'] ? 'AS' . (int)$row['asn'] . ' ' : '') . (string)$row['asn_org']);
    $classification = OrganizationClassifier::classify($row['asn'] !== null ? (int)$row['asn'] : null, (string)$row['asn_org'], (bool)$row['is_bot'], $overrides);
    $environment = trim((string)$row['browser'] . ' / ' . (string)$row['os'] . ' / ' . (string)$row['device_type'], ' /');
?>
<tr>
<td data-col="datetime" title="<?= tya_api_h($localTime) ?>"><span class="tya-history-cell-primary"><?= tya_api_h($localTime) ?></span></td>
<td data-col="event"><span class="tya-history-cell-primary"><?= tya_api_h($row['event_type']) ?></span><?php if ((int)$row['is_bot']): ?><span class="tya-history-cell-secondary">Bot</span><?php endif; ?></td>
<td data-col="ip" class="tya-history-ip" title="<?= tya_api_h($ip ?: '―') ?>"><?= tya_api_h($ip ?: '―') ?></td>
<td data-col="location" title="<?= tya_api_h($location ?: '―') ?>"><?= tya_api_h($location ?: '―') ?></td>
<td data-col="organization" class="tya-history-org" title="<?= tya_api_h($asnText ?: '―') ?>"><?= tya_api_badge($classification) ?><span class="tya-history-cell-secondary"><?= tya_api_h($asnText ?: '―') ?></span></td>
<td data-col="page" class="tya-history-page" title="<?= tya_api_h($row['page_title']) ?>"><span class="tya-history-cell-primary"><?= tya_api_page_link((string)$row['path'], (string)$row['page_title'], $siteBaseUrl) ?></span><span class="tya-history-cell-secondary"><code><?= tya_api_h($row['path']) ?></code></span></td>
<td data-col="referrer" class="tya-history-referrer" title="<?= tya_api_h($row['referrer']) ?>"><?= tya_api_referrer((string)$row['referrer']) ?></td>
<td data-col="environment" class="tya-history-environment" title="<?= tya_api_h($environment) ?>"><?= tya_api_h($environment ?: '―') ?></td>
<td data-col="details"><button type="button" class="button secondary" data-history-detail aria-expanded="false">詳細</button></td>
</tr>
<tr class="tya-history-detail-row" hidden><td colspan="9"><div class="tya-history-detail-grid">
<dl><dt>判定</dt><dd><?= tya_api_h($classification['reason'] . ' (' . $classification['confidence'] . '%)') ?></dd></dl>
<dl><dt>IP</dt><dd><code><?= tya_api_h($ip ?: '―') ?></code></dd></dl>
<dl><dt>地域</dt><dd><?= tya_api_h($location ?: '―') ?></dd></dl>
<dl><dt>ASN／組織</dt><dd><?= tya_api_h($asnText ?: '―') ?></dd></dl>
<dl><dt>滞在</dt><dd><?= tya_api_h(tya_api_duration((int)$row['duration_ms'])) ?></dd></dl>
<dl><dt>スクロール</dt><dd><?= tya_api_h((int)$row['scroll_depth']) ?>%</dd></dl>
<dl><dt>セッション</dt><dd><code><?= tya_api_h($row['session_id']) ?></code><?php if ($row['session_id'] !== ''): ?> <a href="?view=sessions&amp;session=<?= rawurlencode((string)$row['session_id']) ?>">セッション詳細を表示</a><?php endif; ?></dd></dl>
<dl><dt>訪問者</dt><dd><code><?= tya_api_h($row['visitor_id']) ?></code><?php if ($row['visitor_id'] !== ''): ?> <a href="?view=sessions&amp;visitor=<?= rawurlencode((string)$row['visitor_id']) ?>">訪問者の詳細を表示</a><?php endif; ?></dd></dl>
<dl><dt>画面</dt><dd><?= tya_api_h(trim((string)$row['screen'] . ' / ' . (string)$row['viewport'], ' /') ?: '―') ?></dd></dl>
<dl><dt>User-Agent</dt><dd><?= tya_api_h($row['user_agent']) ?></dd></dl>
<dl><dt>完全な参照元</dt><dd><?= $row['referrer'] !== '' ? tya_api_link((string)$row['referrer'], (string)$row['referrer']) : 'Direct' ?></dd></dl>
<?php if ((string)$row['target_url'] !== ''): ?><dl><dt>対象URL</dt><dd><?= tya_api_link((string)$row['target_url'], (string)$row['target_url']) ?></dd></dl><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php
$tableHtml = (string)ob_get_clean();
$first = $total === 0 ? 0 : $offset + 1;
$last = min($offset + $perPage, $total);
$rangeHtml = '<span class="tya-history-range-text">' . number_format($first) . '–' . number_format($last) . '件 / 全' . number_format($total) . '件</span>' . tya_api_pagination($page, $pages);

tya_api_json([
    'ok' => true,
    'table_html' => $tableHtml,
    'range_html' => $rangeHtml,
    'total' => $total,
    'page' => $page,
    'pages' => $pages,
    'generated_at' => (new DateTimeImmutable('now', $timezone))->format('H:i:s'),
]);
