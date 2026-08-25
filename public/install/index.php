<?php

declare(strict_types=1);

use Tenyen\Analytics\Installer;
use Tenyen\Analytics\LocaleResolver;
use Tenyen\Analytics\Translator;

session_name('TYA_INSTALL');
$secureCookie = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => $secureCookie,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
    'use_only_cookies' => true,
    'gc_maxlifetime' => 43200,
]);
$_SESSION['last_activity'] = time();

$root = dirname(__DIR__, 2);
require_once $root . '/app/core/autoload.php';
$installer = new Installer($root);
$installerLocale = LocaleResolver::resolve([], null, $_SESSION['locale'] ?? null, (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
$_SESSION['locale'] ??= $installerLocale;
$translator = new Translator($installerLocale);

function ie(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function inferUrls(): array
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'));
    $publicPath = preg_replace('~/install(?:/index\.php)?$~', '', $script) ?: '';
    return [rtrim($scheme . '://' . $host, '/'), rtrim($scheme . '://' . $host . $publicPath, '/')];
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['csrf'];
}

function verifyCsrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals(csrfToken(), $token)) {
        throw new RuntimeException('画面の有効期限が切れました。ページを再読込してください。');
    }
}

function redirectStep(int $step): never
{
    header('Location: ?step=' . $step, true, 303);
    exit;
}

[$inferredSite, $inferredPublic] = inferUrls();
$_SESSION['draft'] ??= [
    'site_url' => $inferredSite,
    'public_url' => $inferredPublic,
    'timezone' => 'Asia/Tokyo',
    'locale' => $installerLocale,
    'database' => ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'password' => ''],
    'admin_username' => 'admin',
    'admin_password_hash' => '',
    'geoip' => ['city' => false, 'asn' => false],
];
$draft = &$_SESSION['draft'];
$error = '';
$notice = '';
$completed = $_SESSION['completed'] ?? null;
$configExists = is_file($root . '/config.php');
$locked = is_file($root . '/storage/installed.lock');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new RuntimeException('The request may exceed PHP post_max_size. Reload the page and select the GeoLite2 file again; it is uploaded in 512 KB chunks.');
        }
        verifyCsrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'language') {
            $requestedLocale = LocaleResolver::validate($_POST['locale'] ?? null);
            if (!in_array($requestedLocale, ['en', 'ja'], true)) {
                throw new RuntimeException('Unsupported language.');
            }
            $_SESSION['locale'] = $requestedLocale;
            $draft['locale'] = $requestedLocale;
            header('Location: ?step=' . max(1, min(7, (int)($_POST['step'] ?? 1))), true, 303);
            exit;
        }
        if ($configExists && $action !== 'finish') {
            throw new RuntimeException('config.phpが存在するため、上書きインストールを停止しました。');
        }

        if ($action === 'site') {
            $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
            $publicUrl = rtrim(trim((string)($_POST['public_url'] ?? '')), '/');
            foreach ([$siteUrl, $publicUrl] as $url) {
                $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
                if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                    throw new RuntimeException('URLはhttp://またはhttps://から入力してください。');
                }
            }
            $timezone = (string)($_POST['timezone'] ?? 'Asia/Tokyo');
            if (!in_array($timezone, timezone_identifiers_list(), true)) $timezone = 'Asia/Tokyo';
            $draft['site_url'] = $siteUrl;
            $draft['public_url'] = $publicUrl;
            $draft['timezone'] = $timezone;
            redirectStep(3);
        }

        if ($action === 'database') {
            $database = [
                'host' => trim((string)($_POST['db_host'] ?? '')),
                'port' => (int)($_POST['db_port'] ?? 3306),
                'name' => trim((string)($_POST['db_name'] ?? '')),
                'user' => trim((string)($_POST['db_user'] ?? '')),
                'password' => (string)($_POST['db_password'] ?? ''),
            ];
            $pdo = $installer->connect($database);
            $pdo->query('SELECT 1')->fetchColumn();
            $draft['database'] = $database;
            $_SESSION['db_tested'] = true;
            redirectStep(4);
        }

        if ($action === 'admin') {
            $username = trim((string)($_POST['admin_username'] ?? ''));
            $password = (string)($_POST['admin_password'] ?? '');
            $confirmation = (string)($_POST['admin_password_confirmation'] ?? '');
            if (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $username)) {
                throw new RuntimeException('管理ユーザー名は3〜64文字の英数字と . _ @ - が使えます。');
            }
            if (strlen($password) < 12) {
                throw new RuntimeException('管理パスワードは12文字以上にしてください。');
            }
            if (!hash_equals($password, $confirmation)) {
                throw new RuntimeException('確認用パスワードが一致しません。');
            }
            $draft['admin_username'] = $username;
            $draft['admin_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            redirectStep(5);
        }

        if ($action === 'geoip') {
            $dataDir = $root . '/data';
            $messages = [];
            $skipUpload = isset($_POST['skip_geoip']);
            foreach (['city' => 'GeoLite2-City.mmdb', 'asn' => 'GeoLite2-ASN.mmdb'] as $kind => $filename) {
                if ($skipUpload) {
                    $draft['geoip'][$kind] = is_file($dataDir . '/' . $filename);
                    continue;
                }
                $upload = $_FILES[$kind . '_database'] ?? null;
                if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    $existing = $dataDir . '/' . $filename;
                    $draft['geoip'][$kind] = is_file($existing);
                    continue;
                }
                if (!is_dir($dataDir) || !is_writable($dataDir)) {
                    throw new RuntimeException('GeoLite2をアップロードするには、dataディレクトリの書込み権限が必要です。FTPで755または775を確認してください。GeoLite2を選択せずに進む場合は書込み不要です。');
                }
                if ((int)$upload['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException($filename . 'のアップロードに失敗しました（コード' . (int)$upload['error'] . '）。');
                }
                if ((int)$upload['size'] < 1024 || (int)$upload['size'] > 150 * 1024 * 1024) {
                    throw new RuntimeException($filename . 'のファイルサイズが不正です。');
                }
                $temporary = (string)$upload['tmp_name'];
                $inspection = $installer->inspectMmdb($temporary, $kind);
                if (!$inspection['ok']) {
                    throw new RuntimeException($filename . '：' . $inspection['message']);
                }
                $destination = $dataDir . '/' . $filename;
                if (!move_uploaded_file($temporary, $destination)) {
                    throw new RuntimeException($filename . 'をdataディレクトリへ保存できません。');
                }
                @chmod($destination, 0600);
                $draft['geoip'][$kind] = true;
                $messages[] = $filename . '（' . $inspection['type'] . '）';
            }
            $_SESSION['geo_notice'] = $messages ? implode('、', $messages) . 'を確認しました。' : 'GeoLite2は後から設定できます。';
            redirectStep(6);
        }

        if ($action === 'install') {
            if (empty($_SESSION['db_tested'])) throw new RuntimeException('データベース接続テストを先に完了してください。');
            if (empty($draft['admin_password_hash'])) throw new RuntimeException('管理画面アカウントを先に設定してください。');
            $completed = $installer->install($draft);
            $_SESSION['completed'] = $completed;
            unset($_SESSION['draft']['database']['password'], $_SESSION['draft']['admin_password_hash']);
            redirectStep(7);
        }

        if ($action === 'finish') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            header('Location: ../admin/', true, 303);
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$step = max(1, min(7, (int)($_GET['step'] ?? 1)));
if ($completed !== null) $step = 7;
$checks = $installer->environment();
$requiredOkay = $installer->canInstall();
$geoNotice = (string)($_SESSION['geo_notice'] ?? '');
unset($_SESSION['geo_notice']);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="<?= ie($installerLocale) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tenyen Analytics セットアップ</title>
<style>
:root{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1f2937;background:#eef2f7}*{box-sizing:border-box}body{margin:0}.install-shell{max-width:920px;margin:36px auto;padding:0 18px}.brand{display:flex;align-items:center;gap:12px;margin-bottom:20px}.brand-mark{display:grid;place-items:center;width:48px;height:48px;border-radius:15px;background:#2563eb;color:#fff;font-size:25px;font-weight:800}.brand h1{font-size:25px;margin:0}.brand p{margin:3px 0 0;color:#64748b}.wizard{display:grid;grid-template-columns:220px minmax(0,1fr);background:#fff;border:1px solid #dbe3ed;border-radius:16px;overflow:hidden;box-shadow:0 12px 35px rgba(15,23,42,.08)}.steps{padding:24px;background:#f8fafc;border-right:1px solid #e2e8f0}.steps ol{list-style:none;margin:0;padding:0}.steps li{display:flex;gap:10px;align-items:center;padding:9px 0;color:#64748b;font-size:14px}.steps .num{display:grid;place-items:center;width:26px;height:26px;border:1px solid #cbd5e1;border-radius:50%;font-size:12px;font-weight:700}.steps li.active{color:#1d4ed8;font-weight:700}.steps li.active .num{background:#2563eb;color:white;border-color:#2563eb}.content{padding:30px;min-height:570px}.content h2{margin:0 0 8px;font-size:23px}.lead{margin:0 0 24px;color:#64748b;line-height:1.75}.check-list{display:grid;gap:10px}.check{display:grid;grid-template-columns:30px 1fr auto;gap:8px;align-items:center;padding:12px 14px;border:1px solid #e2e8f0;border-radius:10px}.check-icon{font-size:19px}.check small{color:#64748b}.check.bad{border-color:#fecaca;background:#fff7f7}.check.optional{opacity:.83}.field-grid{display:grid;grid-template-columns:1fr 1fr;gap:17px}.field{display:grid;gap:7px}.field.full{grid-column:1/-1}.field label{font-weight:700;font-size:14px}.field input,.field select{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:8px;font:inherit;background:#fff}.hint{color:#64748b;font-size:13px;line-height:1.6}.alert{padding:13px 15px;border-radius:9px;margin-bottom:18px;line-height:1.6}.alert.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.alert.success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.alert.info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}.actions{display:flex;justify-content:space-between;gap:10px;margin-top:28px;padding-top:20px;border-top:1px solid #e5e7eb}.button{display:inline-flex;align-items:center;justify-content:center;padding:11px 17px;border:0;border-radius:8px;background:#2563eb;color:white;font-weight:700;font-size:14px;text-decoration:none;cursor:pointer}.button.secondary{background:#e2e8f0;color:#334155}.button[disabled]{opacity:.45;cursor:not-allowed}.summary{display:grid;grid-template-columns:170px 1fr;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}.summary dt,.summary dd{margin:0;padding:12px;border-bottom:1px solid #e2e8f0}.summary dt{background:#f8fafc;font-weight:700}.summary dd{overflow-wrap:anywhere}.summary dt:last-of-type,.summary dd:last-of-type{border-bottom:0}.code{position:relative;background:#111827;color:#e5e7eb;border-radius:10px;padding:18px;white-space:pre-wrap;overflow-wrap:anywhere;font:13px/1.65 ui-monospace,SFMono-Regular,Consolas,monospace}.copy{position:absolute;right:8px;top:8px;padding:7px 9px;border:0;border-radius:6px;cursor:pointer}.action-group{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.upload-progress{margin:20px 0;padding:14px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:10px}.progress-track{height:10px;margin:10px 0 6px;background:#dbeafe;border-radius:999px;overflow:hidden}.progress-track span{display:block;height:100%;width:0;background:#2563eb;transition:width .15s ease}.already{padding:30px;text-align:center}.already h2{font-size:24px}.already code{background:#f1f5f9;padding:3px 6px;border-radius:5px}@media(max-width:760px){.install-shell{margin:15px auto}.wizard{grid-template-columns:1fr}.steps{border-right:0;border-bottom:1px solid #e2e8f0;padding:12px 18px;overflow:auto}.steps ol{display:flex;min-width:650px;gap:18px}.steps li{white-space:nowrap}.content{padding:22px}.field-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.summary{grid-template-columns:1fr}.summary dt{border-bottom:0}.actions{flex-wrap:wrap}}
</style></head><body><div class="install-shell">
<div class="brand"><div class="brand-mark">T</div><div><h1>Tenyen Analytics</h1><p>Browser installer v0.7.1</p></div><form method="post" style="margin-left:auto;display:flex;gap:8px"><input type="hidden" name="csrf" value="<?= ie(csrfToken()) ?>"><input type="hidden" name="action" value="language"><input type="hidden" name="step" value="<?= $step ?>"><select name="locale" aria-label="Language"><option value="en"<?= $installerLocale==='en'?' selected':'' ?>>English</option><option value="ja"<?= $installerLocale==='ja'?' selected':'' ?>>日本語</option></select><button class="button" type="submit">Apply</button></form></div>
<?php if (($configExists || $locked) && $completed === null): ?>
<div class="wizard"><div class="already"><h2>✅ インストール済みです</h2><p>既存の<code>config.php</code>を保護するため、セットアップウィザードを停止しました。</p><p><a class="button" href="../admin/">管理画面を開く</a></p><p class="hint">再インストールする場合は、先にconfig.php・データベース・storageをバックアップしてください。</p></div></div>
<?php else: ?>
<div class="wizard"><aside class="steps"><ol><?php foreach ([1=>'環境確認',2=>'サイト情報',3=>'データベース',4=>'管理アカウント',5=>'GeoLite2',6=>'確認・実行',7=>'完了'] as $number=>$label): ?><li class="<?= $step===$number?'active':'' ?>"><span class="num"><?= $number ?></span><?= ie($label) ?></li><?php endforeach; ?></ol></aside><main class="content">
<?php if ($error !== ''): ?><div class="alert error"><strong>⚠ セットアップを続行できませんでした</strong><br><?= ie($error) ?></div><?php endif; ?>
<?php if ($geoNotice !== ''): ?><div class="alert success"><?= ie($geoNotice) ?></div><?php endif; ?>
<?php if (!$secureCookie): ?><div class="alert info"><strong>HTTP接続で動作しています。</strong><br>ローカル・テスト環境ではそのまま利用できます。公開運用では管理パスワード保護のためHTTPSへ切り替えてください。</div><?php endif; ?>
<?php if ($step === 1): ?>
<h2>動作環境を確認します</h2><p class="lead">赤い項目がなければ、ブラウザだけでセットアップできます。ComposerやSSHは不要です。</p><div class="check-list">
<?php foreach ($checks as $check): ?><div class="check <?= !$check['ok']?'bad':'' ?> <?= !$check['required']?'optional':'' ?>"><span class="check-icon"><?= $check['ok']?'✅':'❌' ?></span><div><strong><?= ie($check['label']) ?></strong><?php if(!$check['required']): ?> <small>（任意）</small><?php endif; ?></div><small><?= ie($check['detail']) ?></small></div><?php endforeach; ?></div>
<div class="actions"><span></span><a class="button<?= !$requiredOkay?' secondary':'' ?>" <?= $requiredOkay?'href="?step=2"':'aria-disabled="true"' ?>>次へ進む</a></div>
<?php elseif ($step === 2): ?>
<h2>解析するサイトを設定</h2><p class="lead">現在のURLから自動入力しました。共有サーバーに一式を配置した場合、公開URLの末尾には通常<code>/public</code>が入ります。</p>
<form method="post"><input type="hidden" name="csrf" value="<?= ie(csrfToken()) ?>"><input type="hidden" name="action" value="site"><div class="field-grid">
<div class="field full"><label for="site_url">解析対象サイトURL</label><input id="site_url" name="site_url" type="url" required value="<?= ie($draft['site_url']) ?>"><div class="hint">例：http://localhost または https://radio.example.com（HTTP／HTTPS両対応）</div></div>
<div class="field full"><label for="public_url">Tenyen Analytics公開URL</label><input id="public_url" name="public_url" type="url" required value="<?= ie($draft['public_url']) ?>"><div class="hint">config.js.php・tracker.js・collect.phpがあるpublicディレクトリのURLです。</div></div>
<div class="field"><label for="timezone">タイムゾーン</label><select id="timezone" name="timezone"><?php foreach(['Asia/Tokyo'=>'Asia/Tokyo（日本）','UTC'=>'UTC'] as $value=>$label): ?><option value="<?= ie($value) ?>"<?= $draft['timezone']===$value?' selected':'' ?>><?= ie($label) ?></option><?php endforeach; ?></select></div></div>
<div class="actions"><a class="button secondary" href="?step=1">戻る</a><button class="button" type="submit">保存して次へ</button></div></form>
<?php elseif ($step === 3): ?>
<h2>データベースへ接続</h2><p class="lead">レンタルサーバーの管理画面に表示されるMySQL情報を入力してください。入力内容で接続テストしてから次へ進みます。</p>
<form method="post"><input type="hidden" name="csrf" value="<?= ie(csrfToken()) ?>"><input type="hidden" name="action" value="database"><div class="field-grid">
<div class="field"><label for="db_host">DBホスト</label><input id="db_host" name="db_host" required value="<?= ie($draft['database']['host']) ?>"></div><div class="field"><label for="db_port">ポート</label><input id="db_port" name="db_port" type="number" min="1" max="65535" required value="<?= ie($draft['database']['port']) ?>"></div>
<div class="field"><label for="db_name">DB名</label><input id="db_name" name="db_name" required value="<?= ie($draft['database']['name']) ?>"></div><div class="field"><label for="db_user">DBユーザー</label><input id="db_user" name="db_user" required value="<?= ie($draft['database']['user']) ?>"></div>
<div class="field full"><label for="db_password">DBパスワード</label><input id="db_password" name="db_password" type="password" autocomplete="new-password" value="<?= ie($draft['database']['password']) ?>"></div></div>
<div class="actions"><a class="button secondary" href="?step=2">戻る</a><button class="button" type="submit">接続テストして次へ</button></div></form>
<?php elseif ($step === 4): ?>
<h2>管理画面アカウント</h2><p class="lead">アクセス解析の管理画面へログインするアカウントです。パスワードはハッシュ化して保存します。</p>
<form method="post"><input type="hidden" name="csrf" value="<?= ie(csrfToken()) ?>"><input type="hidden" name="action" value="admin"><div class="field-grid">
<div class="field full"><label for="admin_username">管理ユーザー名</label><input id="admin_username" name="admin_username" required autocomplete="username" value="<?= ie($draft['admin_username']) ?>"></div>
<div class="field"><label for="admin_password">管理パスワード</label><input id="admin_password" name="admin_password" type="password" minlength="12" required autocomplete="new-password"><div class="hint">12文字以上で入力してください。</div></div>
<div class="field"><label for="admin_password_confirmation">パスワード確認</label><input id="admin_password_confirmation" name="admin_password_confirmation" type="password" minlength="12" required autocomplete="new-password"></div></div>
<div class="actions"><a class="button secondary" href="?step=3">戻る</a><button class="button" type="submit">保存して次へ</button></div></form>
<?php elseif ($step === 5): ?>
<h2>GeoLite2を設定</h2><p class="lead">国・地域・ASN解析を使う場合だけアップロードしてください。空欄のままでもアクセス収集を開始でき、後からFTPや管理画面で追加できます。</p>
<div class="alert info">MaxMindから取得した展開済みの<code>.mmdb</code>ファイルを選択してください。圧縮ファイルはそのまま送信できません。選択したMMDBは512KBずつ分割送信するため、通常の<code>upload_max_filesize</code>／<code>post_max_size</code>より大きくてもアップロードできます。</div>
<form method="post" enctype="multipart/form-data" id="geoip-form" data-upload-endpoint="upload.php"><input type="hidden" name="csrf" value="<?= ie(csrfToken()) ?>"><input type="hidden" name="action" value="geoip"><div class="field-grid">
<div class="field full"><label for="city_database">GeoLite2-City.mmdb</label><input id="city_database" name="city_database" type="file" accept=".mmdb,application/octet-stream"><div class="hint">現在：<?= is_file($root.'/data/GeoLite2-City.mmdb')?'✅ 配置済み':'未配置' ?></div></div>
<div class="field full"><label for="asn_database">GeoLite2-ASN.mmdb</label><input id="asn_database" name="asn_database" type="file" accept=".mmdb,application/octet-stream"><div class="hint">現在：<?= is_file($root.'/data/GeoLite2-ASN.mmdb')?'✅ 配置済み':'未配置' ?></div></div></div>
<div class="upload-progress" data-upload-progress hidden><strong data-upload-title>アップロード準備中…</strong><div class="progress-track"><span data-upload-bar></span></div><small data-upload-detail></small></div>
<div class="actions"><a class="button secondary" href="?step=4">戻る</a><span class="action-group"><button class="button secondary" type="submit" name="skip_geoip" value="1">GeoLite2をスキップ</button><button class="button" type="submit" data-chunk-upload>アップロードして次へ</button></span></div></form>
<?php elseif ($step === 6): ?>
<h2>設定を確認</h2><p class="lead">「インストールを開始」を押すと、DBテーブルとconfig.phpを作成します。既存ファイルは上書きしません。</p>
<dl class="summary"><dt>解析対象</dt><dd><?= ie($draft['site_url']) ?></dd><dt>公開URL</dt><dd><?= ie($draft['public_url']) ?></dd><dt>DB</dt><dd><?= ie($draft['database']['host'].' / '.$draft['database']['name'].' / '.$draft['database']['user']) ?></dd><dt>管理ユーザー</dt><dd><?= ie($draft['admin_username']) ?></dd><dt>GeoLite2 City</dt><dd><?= !empty($draft['geoip']['city'])?'設定済み':'後から設定' ?></dd><dt>GeoLite2 ASN</dt><dd><?= !empty($draft['geoip']['asn'])?'設定済み':'後から設定' ?></dd></dl>
<form method="post"><input type="hidden" name="csrf" value="<?= ie(csrfToken()) ?>"><input type="hidden" name="action" value="install"><div class="actions"><a class="button secondary" href="?step=5">戻る</a><button class="button" type="submit">インストールを開始</button></div></form>
<?php else: $done = is_array($completed)?$completed:[]; ?>
<h2>🎉 セットアップが完了しました</h2><p class="lead">このページに表示されたコードを解析対象サイトへ貼り付けると、すぐに収集を開始できます。</p>
<div class="alert success"><strong>DBテーブル・config.php・秘密鍵・管理画面アカウントを作成しました。</strong></div>
<h3>管理画面</h3><p><a class="button" target="_blank" rel="noopener" href="<?= ie($done['admin_url']??'../admin/') ?>">管理画面を開く</a></p>
<h3>貼り付けコード</h3><div class="code" id="embed-code"><button type="button" class="copy" data-copy="#embed-code">コピー</button><?= ie($done['embed_code']??'') ?></div>
<h3>確認URL</h3><dl class="summary"><dt>config.js.php</dt><dd><a target="_blank" rel="noopener" href="<?= ie($done['config_url']??'') ?>"><?= ie($done['config_url']??'') ?></a></dd><dt>tracker.js</dt><dd><a target="_blank" rel="noopener" href="<?= ie($done['tracker_url']??'') ?>"><?= ie($done['tracker_url']??'') ?></a></dd><dt>collect.php</dt><dd><?= ie($done['collect_url']??'') ?></dd></dl>
<form method="post"><input type="hidden" name="csrf" value="<?= ie(csrfToken()) ?>"><input type="hidden" name="action" value="finish"><div class="actions"><span></span><button class="button" type="submit">セットアップを終了して管理画面へ</button></div></form>
<?php endif; ?>
</main></div><?php endif; ?><footer style="padding:20px;text-align:center;color:#64748b;font-size:12px">Tenyen Analytics v0.7.1 — <a href="https://www.10yendama.com/" target="_blank" rel="noopener noreferrer">Powered by 10yendama.com</a> — © <?= date('Y') > 2026 ? '2026–'.date('Y') : '2026' ?> 10yendama.com</footer></div>
<script>
document.addEventListener('click',async(e)=>{const b=e.target.closest('[data-copy]');if(!b)return;const host=document.querySelector(b.dataset.copy);if(!host)return;const clone=host.cloneNode(true);clone.querySelectorAll('button').forEach(x=>x.remove());const text=clone.textContent.trim();let copied=false;try{if(navigator.clipboard&&window.isSecureContext){await navigator.clipboard.writeText(text);copied=true}}catch(_){}if(!copied){const area=document.createElement('textarea');area.value=text;area.setAttribute('readonly','');area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();try{copied=document.execCommand('copy')}catch(_){}area.remove()}b.textContent=copied?'Copied':'Select and copy';setTimeout(()=>b.textContent='Copy',1400)});
(() => {
  const form=document.getElementById('geoip-form');
  if(!form||!window.fetch||!window.FormData||!window.Blob)return;
  const progress=form.querySelector('[data-upload-progress]');
  const title=progress?.querySelector('[data-upload-title]');
  const detail=progress?.querySelector('[data-upload-detail]');
  const bar=progress?.querySelector('[data-upload-bar]');
  const uploadButton=form.querySelector('[data-chunk-upload]');
  const csrf=form.querySelector('input[name="csrf"]')?.value||'';
  const endpoint=form.dataset.uploadEndpoint||'upload.php';
  const chunkSize=512*1024;
  const uid=()=>window.crypto?.randomUUID?.()||`${Date.now().toString(16)}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`;
  const format=n=>n>=1048576?`${(n/1048576).toFixed(1)} MB`:`${Math.ceil(n/1024)} KB`;
  async function uploadFile(file,kind,label,base,totalAll){
    const id=uid().replace(/[^a-f0-9-]/gi,'').toLowerCase();
    const chunks=Math.ceil(file.size/chunkSize);
    let sent=0;
    for(let index=0;index<chunks;index++){
      const offset=index*chunkSize;
      const blob=file.slice(offset,Math.min(file.size,offset+chunkSize));
      const data=new FormData();
      data.append('csrf',csrf);data.append('kind',kind);data.append('upload_id',id);
      data.append('chunk_index',String(index));data.append('total_chunks',String(chunks));
      data.append('total_size',String(file.size));data.append('offset',String(offset));
      data.append('chunk',blob,`${label}.part`);
      const response=await fetch(endpoint,{method:'POST',body:data,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      let payload={};try{payload=await response.json()}catch(_){throw new Error(`Could not read the server response (HTTP ${response.status}).`)}
      if(!response.ok||payload.ok===false)throw new Error(payload.message||`HTTP ${response.status}`);
      sent=Math.min(file.size,offset+blob.size);
      const completed=base+sent;
      const percent=totalAll>0?Math.round(completed/totalAll*100):100;
      if(title)title.textContent=`${label}: ${percent}%`;
      if(detail)detail.textContent=`${format(sent)} / ${format(file.size)} (512 KB chunks)`;
      if(bar)bar.style.width=`${percent}%`;
    }
  }
  form.addEventListener('submit',async(event)=>{
    const submitter=event.submitter;
    if(submitter?.name==='skip_geoip'){
      form.querySelectorAll('input[type=file]').forEach(el=>el.disabled=true);
      return;
    }
    if(!submitter?.matches('[data-chunk-upload]'))return;
    const city=form.querySelector('#city_database')?.files?.[0]||null;
    const asn=form.querySelector('#asn_database')?.files?.[0]||null;
    if(!city&&!asn){event.preventDefault();alert('Select an MMDB file or skip GeoLite2.');return;}
    event.preventDefault();
    const queue=[];if(city)queue.push([city,'city','GeoLite2-City.mmdb']);if(asn)queue.push([asn,'asn','GeoLite2-ASN.mmdb']);
    const total=queue.reduce((sum,item)=>sum+item[0].size,0);let base=0;
    progress.hidden=false;uploadButton.disabled=true;form.querySelectorAll('input[type=file],button').forEach(el=>el.disabled=true);
    try{
      for(const item of queue){await uploadFile(item[0],item[1],item[2],base,total);base+=item[0].size;}
      if(title)title.textContent='✅ Upload completed.';if(detail)detail.textContent='Opening the review screen…';if(bar)bar.style.width='100%';
      location.href='?step=6';
    }catch(error){
      if(title)title.textContent='❌ Upload failed.';if(detail)detail.textContent=error.message||String(error);if(bar)bar.style.width='0%';
      form.querySelectorAll('input[type=file],button').forEach(el=>el.disabled=false);uploadButton.disabled=false;
    }
  });
})();
</script>
</body></html>
