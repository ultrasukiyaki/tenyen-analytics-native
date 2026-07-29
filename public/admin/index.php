<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!is_file($root . '/config.php')) {
    header('Location: ../install/', true, 302);
    exit;
}
$config = require $root . '/config.php';
if (!is_array($config)) {
    throw new RuntimeException('config.php must return an array.');
}
require_once $root . '/app/admin-auth.php';
tyaa_require_auth($config, false);
$services = require $root . '/app/bootstrap.php';
require_once $root . '/app/admin-views.php';
$translator = $services['translator'];

$views = [
    'dashboard' => ['icon'=>'▦','label'=>$translator->get('nav.dashboard')],
    'realtime' => ['icon'=>'●','label'=>$translator->get('nav.realtime')],
    'history' => ['icon'=>'≡','label'=>$translator->get('nav.history')],
    'sessions' => ['icon'=>'⇢','label'=>$translator->get('nav.sessions')],
    'events' => ['icon'=>'●','label'=>$translator->get('nav.events')],
    'campaigns' => ['icon'=>'◎','label'=>$translator->get('nav.campaigns')],
    'content' => ['icon'=>'▤','label'=>$translator->get('nav.content')],
    'referrers' => ['icon'=>'↗','label'=>$translator->get('nav.referrers')],
    'organizations' => ['icon'=>'◎','label'=>$translator->get('nav.organizations')],
    'metadata' => ['icon'=>'★','label'=>$translator->get('nav.metadata')],
    'audience' => ['icon'=>'◉','label'=>$translator->get('nav.audience')],
    'engagement' => ['icon'=>'⌁','label'=>$translator->get('nav.engagement')],
    'system' => ['icon'=>'⚙','label'=>$translator->get('nav.system')],
    'settings' => ['icon'=>'⚒','label'=>$translator->get('nav.settings')],
];
$view = preg_replace('/[^a-z]/', '', (string)($_GET['view'] ?? 'dashboard')) ?: 'dashboard';
if (!isset($views[$view])) $view = 'dashboard';
$initial = tyaav_render($view, $services, $_GET);
$app = $services['config']['app'] ?? [];
$siteHost = (string)(parse_url((string)($app['site_url'] ?? ''), PHP_URL_HOST) ?: 'Native Site');
$payload = ['view'=>$view] + $initial;
$jsConfig = [
    'locale' => $translator->browserLocale(),
    'csrf' => tyaa_csrf_token(),
    'strings' => $translator->subset([
        'common.loading', 'common.ready', 'common.failed_view', 'common.no_data', 'common.retry',
        'common.search', 'common.close', 'common.details',
        'common.save', 'common.cancel', 'common.delete', 'common.edit', 'common.confirm_delete',
        'metadata.saved', 'metadata.failed', 'metadata.watch', 'metadata.unwatch',
        'metadata.alias', 'metadata.note', 'metadata.tags', 'metadata.create_tag',
        'saved_views.name', 'saved_views.description', 'saved_views.save_current',
        'history.open', 'history.close', 'history.load_failed', 'history.count', 'history.updated',
        'upload.select', 'upload.completed', 'upload.failed', 'upload.reloading',
        'chart.no_data',
        'sessions.count',
    ]),
];
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!doctype html><html lang="<?= tyaav_h($translator->htmlLang()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= tyaav_h($initial['title']) ?> | Tenyen Analytics</title>
<link rel="stylesheet" href="admin-app.css?v=0.6.2"><link rel="stylesheet" href="admin-history.css?v=0.6.2"><link rel="stylesheet" href="admin-sessions.css?v=0.6.2"></head><body>
<div class="app-shell" data-app-shell>
<header class="topbar"><button class="menu-toggle" type="button" data-menu-toggle aria-label="Menu">☰</button><a class="brand" href="?view=dashboard" data-view-link="dashboard"><span class="brand-mark">T</span><span><b>Tenyen Analytics</b><small>v0.6.2</small></span></a><div class="topbar-site"><?= tyaav_h($siteHost) ?></div><div class="topbar-status" data-global-status><?= tyaav_h($translator->get('common.ready')) ?></div><a class="logout-link" href="?logout=1"><?= tyaav_h($translator->get('auth.logout')) ?></a></header>
<aside class="sidebar" data-sidebar><nav><?php foreach($views as $key=>$item): ?><a href="?view=<?= tyaav_h($key) ?>" data-view-link="<?= tyaav_h($key) ?>" class="<?= $view===$key?'active':'' ?>"><span class="nav-icon"><?= tyaav_h($item['icon']) ?></span><span><?= tyaav_h($item['label']) ?></span></a><?php endforeach; ?></nav></aside>
<main class="main"><div class="view-loading" data-view-loading hidden><span class="spinner"></span><span><?= tyaav_h($translator->get('common.loading')) ?></span></div><div class="view-error" data-view-error hidden></div><div class="view-content" data-view-content><?= $initial['html'] ?></div><footer class="product-footer">Tenyen Analytics v0.6.2 — <a href="https://www.10yendama.com/" target="_blank" rel="noopener noreferrer">Powered by 10yendama.com</a> — © <?= date('Y') > 2026 ? '2026–' . date('Y') : '2026' ?> 10yendama.com</footer></main>
</div>
<script>window.TYA_ADMIN_CONFIG=<?= json_encode($jsConfig, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>
<script type="application/json" id="tya-initial-state"><?= json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="admin-charts.js?v=0.6.2" defer></script><script src="admin-history.js?v=0.6.2" defer></script><script src="admin-sessions.js?v=0.6.2" defer></script><script src="admin-app.js?v=0.6.2" defer></script>
</body></html>
