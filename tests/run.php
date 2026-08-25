<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/core/autoload.php';

use Tenyen\Analytics\LocaleResolver;
use Tenyen\Analytics\OrganizationClassifier;
use Tenyen\Analytics\RuntimePreferences;
use Tenyen\Analytics\Translator;
use Tenyen\Analytics\TrafficAttribution;
use Tenyen\Analytics\Payload;
use Tenyen\Analytics\AdminMetadata;
use Tenyen\Analytics\ExclusionRules;
use Tenyen\Analytics\AnalyticsExport;
use Tenyen\Analytics\LogLifecycle;
use Tenyen\Analytics\DailyAggregation;
use Tenyen\Analytics\GeoLite2Updater;

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
foreach (['dashboard','realtime','history','sessions','events','campaigns','content','referrers','organizations','metadata','exclusions','lifecycle','audience','engagement','system','settings'] as $view) {
    $test(str_contains((string)$adminViews, "'{$view}'"), "admin view exists: {$view}");
}

$installer = file_get_contents(dirname(__DIR__) . '/app/core/src/Installer.php');
$test(str_contains((string)$installer, "'fallback_locale' => 'en'"), 'generated configuration includes locale keys');
$test(LocaleResolver::resolve(['app' => []], null, null, 'en') === 'en', 'old configuration remains loadable');

$versionFiles = ['app/core/src/Installer.php', 'public/admin/index.php', 'public/install/index.php', 'app/admin-auth.php', 'bin/doctor.php', 'tools/build-release.sh', 'README.md', 'README.ja.md', 'CHANGELOG.md', 'CHANGELOG.ja.md'];
foreach ($versionFiles as $file) {
    $test(str_contains((string)file_get_contents(dirname(__DIR__) . '/' . $file), '0.8.0'), "version reference: {$file}");
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
$test($en->get('nav.metadata') === 'Knowledge' && $ja->get('nav.metadata') === 'ナレッジ', 'bilingual Knowledge navigation');
$test($en->get('nav.exclusions') === 'Exclusions' && $ja->get('nav.exclusions') === '除外ルール', 'bilingual Exclusions navigation');
$test($en->get('nav.lifecycle') === 'Lifecycle & Export' && $ja->get('nav.lifecycle') === '保存・エクスポート', 'bilingual lifecycle navigation');
$authSource=(string)file_get_contents(dirname(__DIR__).'/app/admin-auth.php');
$test(str_contains($authSource,"return \$path === '/' ? '/' : rtrim(\$path, '/') . '/';"),'administrator session cookie reaches the collector path');

$test(AdminMetadata::entityKey('organization', '64500') === '64500', 'numeric ASN annotation identity');
$test(AdminMetadata::entityKey('visitor', 'visitor_abc-123') === 'visitor_abc-123', 'opaque anonymous visitor identity');
$test(AdminMetadata::entityKey('content', '/articles/example') === '/articles/example', 'content path identity');
$test(AdminMetadata::entityKey('referrer', 'Example.COM.') === 'example.com', 'normalized referrer-domain identity');
$campaignKey = json_encode(['source'=>'newsletter','medium'=>'email','campaign'=>'launch','content'=>'hero','term'=>'analytics'], JSON_UNESCAPED_SLASHES);
$test(AdminMetadata::entityKey('campaign', (string)$campaignKey) === $campaignKey, 'deterministic structured campaign identity');
$test(AdminMetadata::entityKey('external_target', 'Docs.Example.COM') === 'docs.example.com', 'normalized external target domain');
foreach ([['unsupported','key'],['organization','not-an-asn'],['visitor','bad visitor']] as [$type,$key]) {
    $rejected=false;try{AdminMetadata::entityKey($type,$key);}catch(InvalidArgumentException){$rejected=true;}
    $test($rejected, "invalid metadata identity rejected: {$type}");
}

$metadataService=(string)file_get_contents(dirname(__DIR__).'/app/core/src/AdminMetadata.php');
$metadataApi=(string)file_get_contents(dirname(__DIR__).'/public/admin/api/metadata.php');
$schema=(string)file_get_contents(dirname(__DIR__).'/app/schema.php');
foreach(['tya_annotations','tya_tags','tya_annotation_tags','tya_saved_views'] as $table)$test(str_contains($schema,$table),"fresh schema includes metadata table: {$table}");
$test(str_contains($metadataApi,'tyaa_require_auth')&&str_contains($metadataApi,'tyaa_verify_csrf'),'metadata API requires authentication and CSRF');
$test(str_contains($metadataService,'ON DUPLICATE KEY UPDATE')&&str_contains($metadataService,'INSERT IGNORE'),'annotation and tag assignment writes are idempotent');
$test(str_contains($metadataService,'self::VIEW_KEYS')&&str_contains($metadataService,'unsupported keys'),'saved-view state uses report allowlists');
$test(str_contains($metadataService,"unset(\$result['page'],\$result['csrf'],\$result['session_id'],\$result['site_token'])"),'saved views remove transient and secret keys');
$test(!str_contains((string)file_get_contents(dirname(__DIR__).'/public/collect.php'),'AdminMetadata'),'public collector does not expose administrator metadata');

$rule=static fn(int $id,string $type,string $value,int $precedence=100,bool $enabled=true):array=>['rule_id'=>$id,'rule_type'=>$type,'rule_value'=>$value,'scope'=>'both','action'=>'exclude','precedence'=>$precedence,'enabled'=>$enabled];
$evaluate=static fn(array $rules,array $context):array=>ExclusionRules::evaluateRules($rules,$context);
$test($evaluate([$rule(1,'ip_exact','192.0.2.10',20)],['ip'=>'192.0.2.10'])['excluded'],'IPv4 exact exclusion');
$test(!$evaluate([$rule(1,'ip_exact','192.0.2.10',20)],['ip'=>'192.0.2.11'])['excluded'],'IPv4 exact boundary');
$test($evaluate([$rule(1,'ip_exact','2001:db8::1',20)],['ip'=>'2001:db8::1'])['excluded'],'IPv6 exact exclusion');
$test($evaluate([$rule(1,'ip_exact','2001:db8::1',20)],['ip'=>'2001:0db8:0:0:0:0:0:1'])['excluded'],'IPv6 exact canonical equivalence');
$test($evaluate([$rule(1,'ip_cidr','192.0.2.0/24',30)],['ip'=>'192.0.2.255'])['excluded'],'IPv4 CIDR upper boundary');
$test(!$evaluate([$rule(1,'ip_cidr','192.0.2.0/24',30)],['ip'=>'192.0.3.0'])['excluded'],'IPv4 CIDR outside boundary');
$test($evaluate([$rule(1,'ip_cidr','2001:db8::/32',30)],['ip'=>'2001:db8:ffff::1'])['excluded'],'IPv6 CIDR match');
$invalid=false;try{ExclusionRules::normalizedInput('ip_cidr','192.0.2.0/99','analysis');}catch(InvalidArgumentException){$invalid=true;}$test($invalid,'invalid CIDR rejected');
$test($evaluate([$rule(1,'uri_exact','/private',40)],['path'=>'/private?x=1'])['excluded'],'URI exact ignores query');
$test(!$evaluate([$rule(1,'uri_exact','/private',40)],['path'=>'/private/child'])['excluded'],'URI exact does not match child path');
$test($evaluate([$rule(1,'uri_prefix','/private',50)],['path'=>'/private/child'])['excluded'],'URI prefix matches child path');
$test($evaluate([$rule(1,'native_admin','1',10)],['native_admin'=>true])['excluded'],'Native administrator session exclusion');
$test($evaluate([$rule(1,'bot','1',60)],['is_bot'=>true])['excluded'],'Bot exclusion');
foreach([['country','JP','country_code','jp'],['region','Tokyo','region','TOKYO'],['asn','64500','asn',64500],['organization','Example Corp','asn_org','The Example Corp Network'],['organization_category','company','organization_category','COMPANY'],['browser','Firefox','browser','firefox'],['os','Linux','os','LINUX'],['device','desktop','device_type','DESKTOP'],['referrer_domain','example.com','referrer_domain','EXAMPLE.COM'],['utm_source','newsletter','utm_source','NEWSLETTER'],['utm_medium','email','utm_medium','EMAIL'],['utm_campaign','launch','utm_campaign','LAUNCH']] as [$type,$value,$field,$actual])$test($evaluate([$rule(1,$type,$value)],[$field=>$actual])['excluded'],"exclusion type matches: {$type}");
$test(!$evaluate([$rule(1,'bot','1',60,false)],['is_bot'=>true])['excluded'],'disabled rule does not match');
$decision=$evaluate([$rule(9,'bot','1',60),$rule(3,'native_admin','1',10),$rule(2,'ip_exact','192.0.2.10',20)],['is_bot'=>true,'native_admin'=>true,'ip'=>'192.0.2.10']);
$test((int)$decision['winner']['rule_id']===3&&count($decision['matches'])===3,'deterministic precedence conflict and diagnostic matches');
$test(str_contains($decision['reason'],'precedence 10')&&str_contains($decision['reason'],'action: exclude'),'diagnostic explains precedence, action, and reason');
$many=[];for($n=1;$n<=1000;$n++)$many[]=$rule($n,'ip_exact','198.51.100.'.($n%255),20+$n);
$test($evaluate($many,['ip'=>'203.0.113.1'])['excluded']===false,'large rule set evaluation');
$invalid=false;try{ExclusionRules::normalizedInput('organization','<script>alert(1)</script>','analysis');}catch(InvalidArgumentException){$invalid=true;}$test($invalid,'XSS-shaped rule value rejected');
$exclusionApi=(string)file_get_contents(dirname(__DIR__).'/public/admin/api/exclusions.php');
$collector=(string)file_get_contents(dirname(__DIR__).'/public/collect.php');
$test(str_contains($exclusionApi,'tyaa_require_auth')&&str_contains($exclusionApi,'tyaa_verify_csrf'),'exclusion API requires authentication and CSRF');
$test(strpos($collector,'collectionDecision')<strpos($collector,'INSERT INTO tya_events'),'collection exclusion is evaluated before future storage');
$test(str_contains($collector,'recordAnalysisMatches')&&str_contains(ExclusionRules::analysisSql('e'),'NOT EXISTS'),'analysis exclusion uses SQL match mapping');
$test(str_contains($schema,'tya_exclusion_rules')&&str_contains($schema,'tya_event_exclusions'),'fresh schema includes exclusion rules and non-destructive historical matches');

$test(AnalyticsExport::csvCell('=SUM(A1:A2)')==="'=SUM(A1:A2)"&&AnalyticsExport::csvCell("  +cmd")==="'  +cmd",'CSV spreadsheet-formula injection mitigation');
$test(AnalyticsExport::csvCell('長い値'.str_repeat('文',1000))==='長い値'.str_repeat('文',1000),'CSV Unicode and long-field preservation');
$test(AnalyticsExport::maskIp('203.0.113.77')==='203.0.113.0/24','IPv4 export masking');
$test(str_ends_with(AnalyticsExport::maskIp('2001:db8:1234:5678::1'),'/48'),'IPv6 export masking');
$test(LogLifecycle::validateRetention('unlimited')===null&&LogLifecycle::validateRetention(30)===30&&LogLifecycle::validateRetention(365)===365,'retention unlimited and presets');
$test(LogLifecycle::validateRetention(730)===730,'custom retention validation');
$invalid=false;try{LogLifecycle::validateRetention(0);}catch(InvalidArgumentException){$invalid=true;}$test($invalid,'invalid custom retention rejected');
$lifecycleFile=sys_get_temp_dir().'/tya-lifecycle-'.bin2hex(random_bytes(5));$dummyPdo=new class extends PDO{public function __construct(){}};$lifecycleState=new LogLifecycle($dummyPdo,$lifecycleFile,90);$lifecycleState->saveRetention(180);$test($lifecycleState->state()['retention_days']===180,'retention state is atomically persisted');@unlink($lifecycleFile);
$exportApi=(string)file_get_contents(dirname(__DIR__).'/public/admin/api/export.php');$lifecycleApi=(string)file_get_contents(dirname(__DIR__).'/public/admin/api/lifecycle.php');$lifecycleService=(string)file_get_contents(dirname(__DIR__).'/app/core/src/LogLifecycle.php');$exportService=(string)file_get_contents(dirname(__DIR__).'/app/core/src/AnalyticsExport.php');$cleanupCli=(string)file_get_contents(dirname(__DIR__).'/bin/cleanup.php');
$test(str_contains($exportApi,'tyaa_require_auth')&&str_contains($exportApi,'tyaa_verify_csrf'),'export requires authentication and CSRF');
$test(str_contains($exportApi,"'schema':'tenyen.analytics.export.v1")||str_contains($exportApi,'tenyen.analytics.export.v1'),'stable JSON export schema');
$test(str_contains($exportApi,'while($row=$stmt->fetch())')&&str_contains($exportService,'MYSQL_ATTR_USE_BUFFERED_QUERY'),'large exports stream with unbuffered row fetching');
$test(str_contains($exportApi,"if(\$first){do{")&&str_contains($exportApi,'fputcsv($output,$columns)'),'empty CSV export retains stable headers');
$test(str_contains($exportApi,'EXPORT_RAW_IP')&&str_contains($exportApi,"\$ipMode==='raw'"),'raw IP export requires explicit authorized confirmation');
$test(str_contains($exportService,'ExclusionRules::analysisSql')&&str_contains($exportService,"'tag_id'")&&str_contains($exportService,"'watched'"),'filtered exports respect exclusions and administrator metadata filters');
$test(str_contains($lifecycleApi,'tyaa_require_auth')&&str_contains($lifecycleApi,'tyaa_verify_csrf'),'lifecycle API requires authentication and CSRF');
$test(str_contains($lifecycleService,'SELECT COUNT(*) events')&&str_contains($lifecycleService,'COUNT(DISTINCT NULLIF(session_id'), 'cleanup dry-run reports affected event and session counts');
$test(str_contains($lifecycleService,'LOCK_EX|LOCK_NB')&&str_contains($lifecycleService,'LIMIT {$batchSize}')&&str_contains($lifecycleService,"?'paused':'success'")&&str_contains($lifecycleService,"==='running'"),'cleanup uses overlap lock, bounded batches, and resumable state');
$test(str_contains($cleanupCli,"\$command==='scheduled'")&&str_contains($lifecycleService,'next_run'),'scheduled cleanup uses the protected CLI entry point');
$test(str_contains($lifecycleService,'information_schema.TABLES')&&str_contains($lifecycleService,"DATE_FORMAT(occurred_at,'%Y-%m')"),'storage diagnostics include sizes and monthly counts');
$test(!str_contains($lifecycleService,'DELETE FROM tya_annotations')&&!str_contains($lifecycleService,'DELETE FROM tya_saved_views'),'cleanup preserves annotations and saved views');

$aggregationService=(string)file_get_contents(dirname(__DIR__).'/app/core/src/DailyAggregation.php');$schema=(string)file_get_contents(dirname(__DIR__).'/app/schema.php');$aggregateCli=(string)file_get_contents(dirname(__DIR__).'/bin/aggregate.php');
$test(DailyAggregation::validateDay('2026-08-24')==='2026-08-24','first daily aggregate accepts a complete date');
$badDay=false;try{DailyAggregation::validateDay('2026-02-30');}catch(InvalidArgumentException){$badDay=true;}$test($badDay,'aggregate dates are strictly validated');
$test(str_contains($aggregationService,'DELETE FROM tya_daily_dimensions WHERE metric_day=?')&&str_contains($aggregationService,'ON DUPLICATE KEY UPDATE'),'idempotent rerun replaces dimensions and upserts one daily metric');
$test(str_contains($schema,'PRIMARY KEY (metric_day, actor)')&&str_contains($schema,'PRIMARY KEY (metric_day, actor, dimension_type, dimension_hash)'),'daily aggregate duplicate prevention');
$rawOnly=DailyAggregation::partitionDays('2026-08-01','2026-08-03',null,null);$test($rawOnly['aggregate']===null&&$rawOnly['raw']===[['2026-08-01','2026-08-03']],'raw-only report boundary');
$aggregateOnly=DailyAggregation::partitionDays('2026-08-01','2026-08-03','2026-07-01','2026-08-20');$test($aggregateOnly['aggregate']===['2026-08-01','2026-08-03']&&$aggregateOnly['raw']===[],'aggregate-only report boundary');
$mixed=DailyAggregation::partitionDays('2026-08-01','2026-08-10','2026-08-03','2026-08-08');$test($mixed['aggregate']===['2026-08-03','2026-08-08']&&$mixed['raw']===[['2026-08-01','2026-08-02'],['2026-08-09','2026-08-10']],'mixed raw and aggregate boundary');
$coveredDays=array_merge(range(1,2),range(3,8),range(9,10));$test(count($coveredDays)===10,'raw and aggregate ranges do not double count a day');
$test(str_contains($aggregationService,'rebuildDay')&&str_contains($aggregationService,'rebuildRange'),'day and range rebuild');
$test(str_contains($aggregationService,"'paused'")&&str_contains($aggregationService,'next_day')&&str_contains($aggregateCli,"'resume'"),'interrupted rebuild resumes from checkpoint');
$test(str_contains($aggregationService,"status['last_complete_day']")&&str_contains($aggregationService,'rebuildRange($from, $yesterday'),'incremental aggregation rebuilds the last complete day for late events');
$test(str_contains($lifecycleService,'verifyCoverage')&&str_contains($lifecycleService,"if(!\$coverage['complete'])"),'cleanup is blocked on incomplete aggregate coverage');
$test(str_contains($lifecycleService,"if(!\$coverage['complete'])throw")&&str_contains($aggregationService,"'complete' => true"),'cleanup proceeds only after complete coverage');
$test(DailyAggregation::ratio(9000,3)===3000.0&&DailyAggregation::ratio(10,0)===0.0,'engagement means retain numerator and denominator');
$test(str_contains($aggregationService,'SUM(pageviews=1) bounces')&&str_contains($aggregationService,'entries')&&str_contains($aggregationService,'exits'),'visitor session bounce entry and exit formulas');
$test(str_contains($aggregationService,"'campaign'")&&str_contains($aggregationService,"'organization'")&&str_contains($aggregationService,"'event'"),'bounded campaign organization and event totals');
$test(str_contains($aggregationService,'ExclusionRules::analysisSql')&&str_contains($schema,'tya_daily_dimensions'),'daily aggregation respects analysis exclusions');
$test(str_contains($aggregationService,"'organization'")&&str_contains($aggregationService,'CAST(asn AS CHAR)')&&!str_contains($aggregationService,'DELETE FROM tya_annotations'),'aggregate organization identities preserve current tags and watchlists');
$test(str_contains($aggregationService,"'limit' => 200")&&str_contains($aggregationService,'LIMIT {$limit}'),'high-cardinality dimensions are bounded for long-range performance');
$longRange=DailyAggregation::partitionDays('2024-08-26','2026-08-25','2024-08-26','2026-08-24');$test($longRange['aggregate']===['2024-08-26','2026-08-24']&&$longRange['raw']===[['2026-08-25','2026-08-25']],'long-range performance fixture uses one aggregate span and one raw edge');
$test(str_contains($schema,'tya_daily_metrics')&&str_contains($schema,'tya_aggregate_state'),'baseline upgrade creates aggregate tables non-destructively');
$test(str_contains($lifecycleService,'Daily aggregate coverage is incomplete')||str_contains($aggregationService,'Daily aggregate coverage is incomplete'),'historical totals are required before raw cleanup');
$test(str_contains($lifecycleApi,'aggregate_range')&&str_contains($lifecycleApi,'tyaa_require_auth')&&str_contains($lifecycleApi,'tyaa_verify_csrf'),'aggregate maintenance API requires administrator authentication and CSRF');

$geoUpdater=(string)file_get_contents(dirname(__DIR__).'/app/core/src/GeoLite2Updater.php');$geoApi=(string)file_get_contents(dirname(__DIR__).'/public/admin/api/geolite2.php');$geoCli=(string)file_get_contents(dirname(__DIR__).'/bin/geolite2-update.php');$manualUpload=(string)file_get_contents(dirname(__DIR__).'/public/admin/api/mmdb-upload.php');$buildScript=(string)file_get_contents(dirname(__DIR__).'/tools/build-release.sh');
$badCredentials=false;try{GeoLite2Updater::validateAccountId('');}catch(InvalidArgumentException){$badCredentials=true;}$test($badCredentials,'missing or invalid MaxMind credentials');
$test(GeoLite2Updater::validateAccountId('123456')==='123456'&&GeoLite2Updater::validateLicenseKey('abcdefghijklmnop')==='abcdefghijklmnop','valid MaxMind credentials');
$test(GeoLite2Updater::maskSecret('abcdefghijklmnop')==='••••••••mnop','GeoLite2 secret masking');
$geoRoot=sys_get_temp_dir().'/tya-geo-'.bin2hex(random_bytes(5));mkdir($geoRoot);mkdir($geoRoot.'/storage');mkdir($geoRoot.'/data');$geoCrypto=new \Tenyen\Analytics\Crypto('test-encryption-secret','test-hash-secret');$geoSettings=new GeoLite2Updater($geoRoot,['geoip'=>['city_database'=>$geoRoot.'/data/GeoLite2-City.mmdb','asn_database'=>$geoRoot.'/data/GeoLite2-ASN.mmdb']],$geoCrypto);$savedGeo=$geoSettings->saveSettings('123456','abcdefghijklmnop',true);$credentialPayload=(string)file_get_contents($geoRoot.'/storage/geolite2-credentials.json');$test($savedGeo['configured']===true&&!str_contains($credentialPayload,'abcdefghijklmnop'),'GeoLite2 credentials are encrypted at rest');@unlink($geoRoot.'/storage/geolite2-credentials.json');@unlink($geoRoot.'/storage/geolite2-state.json');@rmdir($geoRoot.'/storage');@rmdir($geoRoot.'/data');@rmdir($geoRoot);
$test(str_contains($geoUpdater,"'city'=>'GeoLite2-City'")&&str_contains($geoCli,"update('city')"),'successful City update pipeline');
$test(str_contains($geoUpdater,"'asn'=>'GeoLite2-ASN'")&&str_contains($geoCli,"update('asn')"),'successful ASN update pipeline');
$test(str_contains($geoUpdater,'foreach(array_keys(self::EDITIONS)')&&str_contains($geoUpdater,"'partial_failure'"),'City-only and ASN-only failures remain independent');
$test(str_contains($geoUpdater,'The GeoLite2 archive is invalid.'),'invalid archive rejection');
$test(str_contains($geoUpdater,'The expected MMDB is missing from the archive.'),'missing expected MMDB rejection');
$test(str_contains($geoUpdater,'wrong database type'),'wrong MMDB type rejection');
$test(str_contains($geoUpdater,'corrupt or unreadable'),'corrupt MMDB rejection');
$test(str_contains($geoUpdater,'.incoming-')&&str_contains($geoUpdater,'.previous'),'atomic MMDB replacement');
$test(str_contains($geoUpdater,'Could not preserve the current MMDB')&&str_contains($geoUpdater,'@rename($backup,$destination)'),'old MMDB retained on replacement failure');
$test(str_contains($geoUpdater,'cleanupTemps')&&str_contains($geoUpdater,'time()-86400'),'stale GeoLite2 temporary-file cleanup');
$test(str_contains($manualUpload,'GeoLite2Updater')&&str_contains($manualUpload,'recordManual'),'manual MMDB upload compatibility');
$test(str_contains($geoUpdater,"'enabled'=>filter_var")&&str_contains($geoUpdater,"state['next_run']"),'automatic update enable and disable');
$test(str_contains($geoUpdater,'LOCK_EX|LOCK_NB')&&str_contains($geoUpdater,'retry_count')&&str_contains($geoUpdater,'7*86400'),'schedule lock and retry backoff');
$test(str_contains((string)$adminViews,'data-geolite2-form')&&str_contains((string)$adminViews,"['health']"),'GeoLite2 status UI');
$test(!preg_match('/error_log\([^\n]*(license|account_id)/i',$geoUpdater.$geoApi.$manualUpload),'GeoLite2 secret absent from logs');
$test(!GeoLite2Updater::validateArchivePath('../secret')&&!GeoLite2Updater::validateArchivePath('/absolute')&&GeoLite2Updater::validateArchivePath('GeoLite2-City/GeoLite2-City.mmdb'),'archive traversal rejection');
$test(str_contains($geoApi,'tyaa_require_auth')&&str_contains($geoApi,'tyaa_verify_csrf')&&str_contains($geoApi,'require HTTPS'),'GeoLite2 API authentication CSRF and HTTPS enforcement');
$test(str_contains($geoUpdater,"'https://download.maxmind.com/app/geoip_download?'")&&str_contains($geoUpdater,'CURLPROTO_HTTPS')&&!str_contains($geoApi,'download_url'),'fixed HTTPS MaxMind endpoint');
$test(str_contains($buildScript,"! -name '*.mmdb'")&&str_contains($buildScript,"! -path './storage/*'"),'release package excludes MMDB credentials and state');
$test(str_contains($geoCli,"'scheduled'")&&str_contains($geoUpdater,'due()'),'Native scheduled GeoLite2 update command');

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
