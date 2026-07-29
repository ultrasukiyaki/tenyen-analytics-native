<?php

declare(strict_types=1);

use Tenyen\Analytics\OrganizationClassifier;
use Tenyen\Analytics\SessionAnalytics;

$root = dirname(__DIR__, 3);
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit;
}
$config = require $configFile;
require_once $root . '/app/admin-auth.php';
tyaa_require_auth(is_array($config) ? $config : [], true);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$json = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
};
$csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!tyaa_verify_csrf($csrf)) {
    $json(['ok' => false, 'error' => 'csrf_failed', 'message' => 'Invalid CSRF token.'], 403);
}

$services = require $root . '/app/bootstrap.php';
$analytics = new SessionAnalytics($services['pdo']);
$translator = $services['translator'];
$timezone = new DateTimeZone((string)($config['app']['timezone'] ?? 'Asia/Tokyo'));
$utc = new DateTimeZone('UTC');
$siteUrl = rtrim((string)($config['app']['site_url'] ?? ''), '/');
$overrides = (array)($config['app']['organization_overrides'] ?? []);
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$t = static fn(string $key): string => $translator->get($key);
$local = static function (?string $value) use ($timezone, $utc): string {
    return $value ? (new DateTimeImmutable($value, $utc))->setTimezone($timezone)->format('Y-m-d H:i:s') : '—';
};
$duration = static function (int $milliseconds) use ($t): string {
    $seconds = max(0, (int)round($milliseconds / 1000));
    if ($seconds < 60) return $seconds . ' ' . $t('unit.seconds');
    $minutes = intdiv($seconds, 60);
    return $minutes < 60 ? $minutes . ' ' . $t('unit.minutes') . ' ' . ($seconds % 60) . ' ' . $t('unit.seconds')
        : intdiv($minutes, 60) . ' ' . $t('unit.hours') . ' ' . ($minutes % 60) . ' ' . $t('unit.minutes');
};
$safeLink = static function (string $path, string $label) use ($h, $siteUrl): string {
    $url = preg_match('~^https?://~i', $path) ? $path : rtrim($siteUrl, '/') . '/' . ltrim($path, '/');
    if (!filter_var($url, FILTER_VALIDATE_URL)) return $h($label !== '' ? $label : $path);
    return '<a class="out-link" href="' . $h($url) . '" target="_blank" rel="noopener noreferrer">' . $h($label !== '' ? $label : $path) . '</a>';
};
$summaryRows = static function (array $row) use ($h, $t, $local, $duration, $overrides): string {
    $classification = OrganizationClassifier::classify(isset($row['asn']) ? (int)$row['asn'] : null, (string)($row['asn_org'] ?? ''), (bool)($row['is_bot'] ?? false), $overrides);
    $values = [
        $t('sessions.start') => $local($row['session_start'] ?? null),
        $t('sessions.last_activity') => $local($row['last_activity'] ?? null),
        $t('sessions.visitor') => (string)($row['visitor_id'] ?? '—'),
        'PV' => (string)(int)($row['pageviews'] ?? 0),
        $t('sessions.estimated_duration') => $duration((int)($row['engaged_time_ms'] ?? 0)),
        $t('sessions.landing') => (string)($row['landing_page'] ?? '—'),
        $t('sessions.exit') => (string)($row['exit_page'] ?? '—'),
        $t('sessions.referrer') => (string)($row['referrer'] ?? 'Direct'),
        'Traffic channel' => (string)($row['traffic_channel'] ?? 'Unknown'),
        'Referrer domain' => (string)($row['referrer_domain'] ?? '—'),
        'UTM source / medium / campaign' => trim((string)($row['utm_source'] ?? '') . ' / ' . (string)($row['utm_medium'] ?? '') . ' / ' . (string)($row['utm_campaign'] ?? ''), ' /') ?: '—',
        $t('sessions.location') => (string)($row['country_name'] ?? '—'),
        'ASN / ' . $t('sessions.organization') => trim(((int)($row['asn'] ?? 0) ? 'AS' . (int)$row['asn'] . ' ' : '') . (string)($row['asn_org'] ?? '')) ?: '—',
        $t('sessions.category') => $t($classification['label_key']),
        $t('sessions.environment') => trim((string)($row['browser'] ?? '') . ' / ' . (string)($row['os'] ?? '') . ' / ' . (string)($row['device_type'] ?? ''), ' /') ?: '—',
        $t('sessions.actor') => !empty($row['is_bot']) ? 'Bot' : $t('sessions.human'),
    ];
    $html = '';
    foreach ($values as $label => $value) $html .= '<div><dt>' . $h($label) . '</dt><dd>' . $h($value) . '</dd></div>';
    return $html;
};

try {
    $action = (string)($_GET['action'] ?? 'list');
    if ($action === 'detail') {
        $detail = $analytics->getSessionDetail((string)($_GET['session_id'] ?? ''));
        if ($detail === null) $json(['ok' => false, 'error' => 'not_found', 'message' => $t('sessions.missing')], 404);
        ob_start(); ?>
        <div class="journey-dialog-head"><h2><?= $h($t('sessions.detail')) ?></h2><button type="button" class="button secondary" data-journey-close><?= $h($t('common.close')) ?></button></div>
        <dl class="journey-summary"><?= $summaryRows($detail['summary']) ?></dl>
        <?php if (($detail['summary']['visitor_id'] ?? '') !== ''): ?><button class="button secondary" data-visitor-id="<?= $h($detail['summary']['visitor_id']) ?>"><?= $h($t('sessions.visitor_history')) ?></button><?php endif; ?>
        <h3><?= $h($t('sessions.journey')) ?></h3><ol class="journey-steps">
        <?php foreach ($detail['events'] as $event): ?><li><div class="journey-step-head"><time><?= $h($local($event['occurred_at'])) ?></time><strong><?= $h($event['event_type']) ?><?= $event['event_name'] ? ': ' . $h($event['event_name']) : '' ?></strong></div>
        <div class="journey-long"><?= $safeLink((string)$event['path'], (string)$event['page_title']) ?><br><code><?= $h($event['path']) ?></code></div>
        <?php if ($event['referrer']): ?><div class="journey-long"><?= $h($t('sessions.referrer')) ?>: <?= $h($event['referrer']) ?></div><?php endif; ?>
        <?php if ($event['target_url']): ?><div class="journey-long"><?= $h($t('sessions.target')) ?>: <?= $h($event['target_url']) ?></div><?php endif; ?>
        <?php if ($event['event_metadata']): ?><div class="journey-long"><code><?= $h($event['event_metadata']) ?></code></div><?php endif; ?>
        <?php if ((int)$event['duration_ms'] || (int)$event['scroll_depth']): ?><small><?= $h($t('sessions.engagement')) ?>: <?= $h($duration((int)$event['duration_ms'])) ?> / <?= (int)$event['scroll_depth'] ?>%</small><?php endif; ?>
        </li><?php endforeach; ?></ol>
        <?php $json(['ok' => true, 'html' => (string)ob_get_clean()]);
    }
    if ($action === 'visitor') {
        $visitor = $analytics->getVisitorSummary((string)($_GET['visitor_id'] ?? ''));
        if ($visitor === null) $json(['ok' => false, 'error' => 'not_found', 'message' => $t('visitors.missing')], 404);
        ob_start(); ?>
        <div class="journey-dialog-head"><h2><?= $h($t('visitors.detail')) ?></h2><button type="button" class="button secondary" data-journey-close><?= $h($t('common.close')) ?></button></div>
        <p class="notice"><?= $h($t('visitors.privacy_notice')) ?></p>
        <dl class="journey-summary">
          <div><dt><?= $h($t('visitors.first_seen')) ?></dt><dd><?= $h($local($visitor['summary']['first_seen'])) ?></dd></div>
          <div><dt><?= $h($t('visitors.last_seen')) ?></dt><dd><?= $h($local($visitor['summary']['last_seen'])) ?></dd></div>
          <div><dt><?= $h($t('visitors.sessions')) ?></dt><dd><?= (int)$visitor['summary']['session_count'] ?></dd></div>
          <div><dt><?= $h($t('visitors.total_pv')) ?></dt><dd><?= (int)$visitor['summary']['total_pageviews'] ?></dd></div>
          <div><dt><?= $h($t('sessions.environment')) ?></dt><dd><?= $h(trim($visitor['summary']['browser'] . ' / ' . $visitor['summary']['os'] . ' / ' . $visitor['summary']['device_type'], ' /') ?: '—') ?></dd></div>
          <div><dt><?= $h($t('sessions.location')) ?></dt><dd><?= $h($visitor['summary']['country_name'] ?: '—') ?></dd></div>
        </dl>
        <?php
        $landings=[];
        foreach($visitor['sessions'] as $session){$landing=(string)($session['landing_page']??'');if($landing!=='')$landings[$landing]=($landings[$landing]??0)+1;}
        arsort($landings);
        ?>
        <div class="journey-summary">
          <div><h3><?= $h($t('visitors.common_landings')) ?></h3><ol><?php foreach(array_slice($landings,0,10,true) as $path=>$hits): ?><li class="journey-long"><?= $h($path) ?> (<?= $hits ?>)</li><?php endforeach; ?></ol></div>
          <div><h3><?= $h($t('visitors.common_content')) ?></h3><ol><?php foreach($visitor['common_content'] as $item): ?><li class="journey-long"><?= $h($item['page_title']?:$item['path']) ?> (<?= (int)$item['hits'] ?>)</li><?php endforeach; ?></ol></div>
          <div><h3><?= $h($t('visitors.common_referrers')) ?></h3><ol><?php foreach($visitor['common_referrers'] as $item): ?><li class="journey-long"><?= $h($item['referrer']) ?> (<?= (int)$item['hits'] ?>)</li><?php endforeach; ?></ol></div>
        </div>
        <h3><?= $h($t('visitors.history')) ?></h3><div class="table-wrap"><table><thead><tr><th><?= $h($t('sessions.start')) ?></th><th>PV</th><th><?= $h($t('sessions.landing')) ?></th><th><?= $h($t('common.details')) ?></th></tr></thead><tbody>
        <?php foreach ($visitor['sessions'] as $row): ?><tr><td><?= $h($local($row['session_start'])) ?></td><td><?= (int)$row['pageviews'] ?></td><td class="journey-long"><?= $h($row['landing_page'] ?: '—') ?></td><td><button class="button secondary" data-session-id="<?= $h($row['session_id']) ?>"><?= $h($t('common.details')) ?></button></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php $json(['ok' => true, 'html' => (string)ob_get_clean()]);
    }

    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
    $filters = $_GET;
    $filters['actor'] = in_array($_GET['actor'] ?? 'human', ['human', 'bot', 'all'], true) ? $_GET['actor'] : 'human';
    $filters['start_utc'] = $from !== '' ? (new DateTimeImmutable($from, $timezone))->setTimezone($utc)->format('Y-m-d H:i:s') : null;
    $filters['end_utc'] = $to !== '' ? (new DateTimeImmutable($to, $timezone))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s') : null;
    $result = $analytics->listSessions($filters);
    ob_start(); ?>
    <div class="table-wrap"><table><thead><tr><th><?= $h($t('sessions.start')) ?></th><th><?= $h($t('sessions.visitor')) ?></th><th>PV</th><th><?= $h($t('sessions.estimated_duration')) ?></th><th><?= $h($t('sessions.landing')) ?></th><th><?= $h($t('sessions.exit')) ?></th><th><?= $h($t('sessions.referrer')) ?></th><th><?= $h($t('sessions.location')) ?></th><th>ASN / <?= $h($t('sessions.organization')) ?></th><th><?= $h($t('sessions.environment')) ?></th><th><?= $h($t('sessions.actor')) ?></th><th><?= $h($t('common.details')) ?></th></tr></thead><tbody>
    <?php if (!$result['items']): ?><tr><td colspan="12"><?= $h($t('sessions.empty')) ?></td></tr><?php endif; ?>
    <?php foreach ($result['items'] as $row): $classification=OrganizationClassifier::classify(isset($row['asn'])?(int)$row['asn']:null,(string)$row['asn_org'],(bool)$row['is_bot'],$overrides); ?><tr><td><?= $h($local($row['session_start'])) ?><br><small><?= $h($local($row['last_activity'])) ?></small></td><td class="journey-long"><button class="link-button" data-visitor-id="<?= $h($row['visitor_id']) ?>"><?= $h($row['visitor_id'] ?: '—') ?></button><?php if($row['visitor_id']): ?><br><button class="button secondary" data-edit-annotation data-entity-type="visitor" data-entity-key="<?= $h($row['visitor_id']) ?>" data-original="<?= $h($row['visitor_id']) ?>"><?= $h($t('common.edit')) ?></button><?php endif; ?></td><td><?= (int)$row['pageviews'] ?></td><td><?= $h($duration((int)$row['engaged_time_ms'])) ?></td><td class="journey-long"><?= $h($row['landing_page'] ?: '—') ?></td><td class="journey-long"><?= $h($row['exit_page'] ?: '—') ?></td><td class="journey-long"><?= $h($row['referrer'] ?: 'Direct') ?></td><td><?= $h($row['country_name'] ?: '—') ?></td><td class="journey-long"><?= $h(trim(((int)$row['asn']?'AS'.(int)$row['asn'].' ':'').$row['asn_org']) ?: '—') ?><br><small><?= $h($t($classification['label_key'])) ?></small></td><td><?= $h(trim($row['browser'] . ' / ' . $row['os'] . ' / ' . $row['device_type'], ' /') ?: '—') ?></td><td><?= !empty($row['is_bot']) ? 'Bot' : $h($t('sessions.human')) ?></td><td><button class="button secondary" data-session-id="<?= $h($row['session_id']) ?>"><?= $h($t('common.details')) ?></button></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <nav class="journey-pagination" aria-label="<?= $h($t('sessions.pagination')) ?>"><?php if ($result['page'] > 1): ?><button data-session-page="<?= $result['page'] - 1 ?>">‹</button><?php endif; ?><span><?= $result['page'] ?> / <?= $result['pages'] ?></span><?php if ($result['page'] < $result['pages']): ?><button data-session-page="<?= $result['page'] + 1 ?>">›</button><?php endif; ?></nav>
    <?php $json(['ok' => true, 'html' => (string)ob_get_clean(), 'total' => $result['total'], 'page' => $result['page'], 'pages' => $result['pages']]);
} catch (InvalidArgumentException $e) {
    $json(['ok' => false, 'error' => 'invalid_filter', 'message' => $t('sessions.invalid_filter')], 400);
} catch (Throwable $e) {
    error_log('[Tenyen Analytics sessions] ' . $e->getMessage());
    $json(['ok' => false, 'error' => 'server_error', 'message' => $t('sessions.server_error')], 500);
}
