<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/core/autoload.php';

use Tenyen\Analytics\LocaleResolver;
use Tenyen\Analytics\OrganizationClassifier;
use Tenyen\Analytics\RuntimePreferences;
use Tenyen\Analytics\Translator;
use Tenyen\Analytics\TrafficAttribution;
use Tenyen\Analytics\Payload;

$failures = 0;
$test = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'ok' : 'not ok') . ' - ' . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
};

$en = new Translator('en');
$ja = new Translator('ja');
$test($en->get('common.loading') === 'Loading…', 'English translation lookup');
$test($ja->get('common.loading') === '読み込んでいます…', 'Japanese translation lookup');
$test($ja->get('test.english_only') === 'English fallback', 'missing Japanese key falls back to English');
$test($ja->get('test.missing') === 'test.missing', 'missing key safely falls back to key');
$test($en->get('history.count', ['count' => 12]) === '12 events', 'named placeholder replacement');
$test(LocaleResolver::resolve(['app' => ['locale' => 'en']]) === 'en', 'English locale resolution');
$test(LocaleResolver::resolve(['app' => ['locale' => 'ja']]) === 'ja', 'Japanese locale resolution');
$test(LocaleResolver::resolve(['app' => ['locale' => 'auto']], null, null, 'ja-JP,ja;q=0.9') === 'ja', 'automatic locale resolution');
$test(LocaleResolver::resolve(['app' => ['locale' => 'unsupported']], null, null, 'fr') === 'en', 'unsupported locale fallback');

$temporary = sys_get_temp_dir() . '/tya-prefs-' . bin2hex(random_bytes(5));
$preferences = new RuntimePreferences($temporary);
$test($preferences->load() === [], 'missing preferences are safe');
file_put_contents($temporary, '{not-json');
$test($preferences->load() === [], 'malformed preferences are safe');
@unlink($temporary);

$samples = [
    'Example University' => 'research',
    'Ministry of Example' => 'government',
    'Example Telecom' => 'isp',
    'Example Corporation' => 'company',
];
foreach ($samples as $organization => $category) {
    $result = OrganizationClassifier::classify(64500, $organization, false);
    $test($result['category'] === $category, "organization category: {$category}");
    $test($organization === (string)$organization, "raw organization remains unchanged: {$organization}");
}

$adminViews = file_get_contents(dirname(__DIR__) . '/app/admin-views.php');
foreach (['dashboard','realtime','history','sessions','events','campaigns','content','referrers','organizations','audience','engagement','system','settings'] as $view) {
    $test(str_contains((string)$adminViews, "'{$view}'"), "admin view exists: {$view}");
}

$installer = file_get_contents(dirname(__DIR__) . '/app/core/src/Installer.php');
$test(str_contains((string)$installer, "'fallback_locale' => 'en'"), 'generated configuration includes locale keys');
$test(LocaleResolver::resolve(['app' => []], null, null, 'en') === 'en', 'old configuration remains loadable');

$versionFiles = ['app/core/src/Installer.php', 'public/admin/index.php', 'public/install/index.php', 'app/admin-auth.php', 'bin/doctor.php', 'tools/build-release.sh', 'README.md', 'README.ja.md', 'CHANGELOG.md', 'CHANGELOG.ja.md'];
foreach ($versionFiles as $file) {
    $test(str_contains((string)file_get_contents(dirname(__DIR__) . '/' . $file), '0.6.1'), "version reference: {$file}");
}

$english = require dirname(__DIR__) . '/app/i18n/en.php';
$japanese = require dirname(__DIR__) . '/app/i18n/ja.php';
$productionEnglish = $english;
unset($productionEnglish['test.english_only']);
$test(array_keys($productionEnglish) === array_keys($japanese), 'English and Japanese production translation keys are consistent');
$test($en->get('dashboard.description') === 'Get a quick overview of activity across the entire site.', 'English dashboard description');
$test($ja->get('dashboard.description') === 'サイト全体の動きを簡潔に確認できます。', 'standard-Japanese dashboard description');
$test($en->get('nav.sessions') === 'Sessions' && $ja->get('nav.sessions') === 'セッション', 'bilingual Sessions navigation');
$test($en->get('nav.events') === 'Events' && $ja->get('nav.events') === 'イベント', 'bilingual Events navigation');

$test(TrafficAttribution::classify('/landing', '', 'https://example.com')['channel'] === 'Direct', 'direct traffic attribution');
$test(TrafficAttribution::classify('/landing', 'https://example.com/from', 'https://example.com')['channel'] === 'Internal', 'internal traffic attribution');
$test(TrafficAttribution::classify('/landing', 'https://www.google.com/search?q=x', 'https://example.com')['channel'] === 'Organic Search', 'Google organic attribution');
$test(TrafficAttribution::classify('/landing', 'https://www.bing.com/search?q=x', 'https://example.com')['channel'] === 'Organic Search', 'Bing organic attribution');
$test(TrafficAttribution::classify('/landing', 'https://bsky.app/profile/example', 'https://example.com')['channel'] === 'Social', 'Bluesky social attribution');
$test(TrafficAttribution::classify('/landing', 'https://mastodon.example/@person', 'https://example.com')['channel'] === 'Social', 'Mastodon social attribution');
$campaign = TrafficAttribution::classify('/?UTM_Source=News&utm_campaign=Launch&utm_campaign=ignored', '', 'https://example.com');
$test($campaign['channel'] === 'Campaign' && $campaign['utm_source'] === 'News' && $campaign['utm_campaign'] === 'Launch', 'UTM precedence and duplicate handling');
$test(strlen(TrafficAttribution::classify('/?utm_campaign=' . str_repeat('x', 400), '', 'https://example.com')['utm_campaign']) === 255, 'UTM value bound');
$custom = Payload::normalize(['event'=>'custom','event_name'=>'radio_play','metadata'=>['station'=>'example','server'=>'primary']]);
$test($custom['event_name'] === 'radio_play' && count($custom['metadata']) === 2, 'bounded radio custom event');
$rejected = false;
try { Payload::normalize(['event'=>'custom','event_name'=>'Invalid event','metadata'=>[]]); } catch (InvalidArgumentException) { $rejected = true; }
$test($rejected, 'invalid custom event name rejected');
$rejected = false;
try { Payload::normalize(['event'=>'custom','event_name'=>'test','metadata'=>array_combine(array_map(static fn(int $n): string => 'k'.$n, range(1, 13)), range(1, 13))]); } catch (InvalidArgumentException) { $rejected = true; }
$test($rejected, 'excessive metadata rejected');

$sessionService = (string)file_get_contents(dirname(__DIR__) . '/app/core/src/SessionAnalytics.php');
$test(str_contains($sessionService, "e.session_id <> ''"), 'legacy rows without session IDs are excluded');
$test(str_contains($sessionService, 'ORDER BY occurred_at ASC,event_id ASC'), 'journey ordering has deterministic secondary order');
$test(str_contains($sessionService, "MAX(duration_ms) max_duration"), 'cumulative engagement uses the final maximum per page');
$test(str_contains($sessionService, "SUM(e.event_type='pageview')"), 'pageviews exclude engagement events');
$sessionsApi = (string)file_get_contents(dirname(__DIR__) . '/public/admin/api/sessions.php');
$test(str_contains($sessionsApi, 'tyaa_require_auth') && str_contains($sessionsApi, 'tyaa_verify_csrf'), 'session API requires authentication and CSRF');

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/public')) as $javascript) {
    if (!$javascript->isFile() || $javascript->getExtension() !== 'js') {
        continue;
    }
    $contents = (string)file_get_contents($javascript->getPathname());
    $test(!preg_match('/[\x{3040}-\x{30ff}\x{3400}-\x{9fff}]/u', $contents), 'no hard-coded Japanese browser strings: ' . $javascript->getFilename());
}

$historyCss = (string)file_get_contents(dirname(__DIR__) . '/public/admin/admin-history.css');
$test(
    str_contains($historyCss, 'tr:not(.tya-history-detail-row)')
        && str_contains($historyCss, '.tya-history-detail-grid dl{margin:0;min-width:0}')
        && str_contains($historyCss, 'overflow-wrap:anywhere'),
    'history detail rows wrap long values without overlapping adjacent columns'
);

exit($failures === 0 ? 0 : 1);
