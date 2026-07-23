<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/core/autoload.php';

use Tenyen\Analytics\LocaleResolver;
use Tenyen\Analytics\OrganizationClassifier;
use Tenyen\Analytics\RuntimePreferences;
use Tenyen\Analytics\Translator;

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
foreach (['dashboard','realtime','history','content','referrers','organizations','audience','engagement','system','settings'] as $view) {
    $test(str_contains((string)$adminViews, "'{$view}'"), "admin view exists: {$view}");
}

$installer = file_get_contents(dirname(__DIR__) . '/app/core/src/Installer.php');
$test(str_contains((string)$installer, "'fallback_locale' => 'en'"), 'generated configuration includes locale keys');
$test(LocaleResolver::resolve(['app' => []], null, null, 'en') === 'en', 'old configuration remains loadable');

$versionFiles = ['app/core/src/Installer.php', 'public/admin/index.php', 'README.md', 'CHANGELOG.md'];
foreach ($versionFiles as $file) {
    $test(str_contains((string)file_get_contents(dirname(__DIR__) . '/' . $file), '0.5.5'), "version reference: {$file}");
}

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/public')) as $javascript) {
    if (!$javascript->isFile() || $javascript->getExtension() !== 'js') {
        continue;
    }
    $contents = (string)file_get_contents($javascript->getPathname());
    $test(!preg_match('/[\x{3040}-\x{30ff}\x{3400}-\x{9fff}]/u', $contents), 'no hard-coded Japanese browser strings: ' . $javascript->getFilename());
}

exit($failures === 0 ? 0 : 1);
