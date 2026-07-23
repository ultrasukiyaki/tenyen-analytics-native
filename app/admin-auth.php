<?php

declare(strict_types=1);

/**
 * Native admin authentication.
 *
 * Uses an application session login so Apache/FastCGI does not need to pass
 * the Authorization header to PHP. HTTP is supported for local test systems;
 * production installations should use HTTPS.
 */

function tyaa_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function tyaa_admin_cookie_path(array $config): string
{
    $base = (string)($config['app']['base_url'] ?? '');
    $path = (string)(parse_url($base, PHP_URL_PATH) ?: '/');
    $path = '/' . trim($path, '/');
    if ($path === '/') {
        return '/admin/';
    }
    return rtrim($path, '/') . '/admin/';
}

function tyaa_start_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('TYA_ADMIN');
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => tyaa_is_https(),
        'cookie_samesite' => 'Lax',
        'cookie_path' => tyaa_admin_cookie_path($config),
        'use_strict_mode' => true,
        'use_only_cookies' => true,
        'gc_maxlifetime' => 43200,
    ]);
}

function tyaa_csrf_token(): string
{
    if (empty($_SESSION['tya_csrf'])) {
        $_SESSION['tya_csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['tya_csrf'];
}

function tyaa_verify_csrf(string $token): bool
{
    return $token !== '' && hash_equals(tyaa_csrf_token(), $token);
}

/** @return array{0:string,1:string} */
function tyaa_basic_credentials(): array
{
    $user = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
    $password = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
    if ($user !== '' || $password !== '') {
        return [$user, $password];
    }

    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $header = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }
    }
    if (!preg_match('/^Basic\s+(.+)$/i', trim($header), $matches)) {
        return ['', ''];
    }
    $decoded = base64_decode($matches[1], true);
    if ($decoded === false || !str_contains($decoded, ':')) {
        return ['', ''];
    }
    return explode(':', $decoded, 2);
}

function tyaa_credentials_valid(array $config, string $user, string $password): bool
{
    $admin = $config['admin'] ?? [];
    $expectedUser = (string)($admin['username'] ?? '');
    $passwordHash = (string)($admin['password_hash'] ?? '');
    return $expectedUser !== ''
        && $passwordHash !== ''
        && hash_equals($expectedUser, $user)
        && password_verify($password, $passwordHash);
}

function tyaa_session_valid(array $config): bool
{
    $admin = $config['admin'] ?? [];
    $expectedUser = (string)($admin['username'] ?? '');
    $sessionUser = (string)($_SESSION['tya_admin_user'] ?? '');
    $authenticated = !empty($_SESSION['tya_admin_authenticated']);
    return $authenticated && $expectedUser !== '' && hash_equals($expectedUser, $sessionUser);
}

function tyaa_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

function tyaa_logout(array $config): never
{
    tyaa_start_session($config);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    header('Location: ./', true, 303);
    exit;
}

function tyaa_render_login(array $config, string $error = ''): never
{
    require_once __DIR__ . '/core/autoload.php';
    $preferences = new \Tenyen\Analytics\RuntimePreferences(dirname(__DIR__) . '/storage/admin-settings.json');
    $stored = $preferences->load();
    $locale = \Tenyen\Analytics\LocaleResolver::resolve(
        $config,
        $stored['locale'] ?? null,
        null,
        (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
    );
    $translator = new \Tenyen\Analytics\Translator($locale, (string)($config['app']['fallback_locale'] ?? 'en'));
    $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $https = tyaa_is_https();
    $csrf = htmlspecialchars(tyaa_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $errorHtml = $error === '' ? '' : '<div class="alert error">' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    $httpWarning = $https ? '' : '<div class="alert warning"><strong>HTTP connection.</strong><br>HTTP is supported for local and test environments only. Use HTTPS in public production.</div>';

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    echo '<!doctype html><html lang="' . $h($translator->htmlLang()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Tenyen Analytics — ' . $h($translator->get('auth.login')) . '</title><style>'
        . ':root{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1f2937;background:#eef2f7}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px}.card{width:min(440px,100%);background:#fff;border:1px solid #dbe3ed;border-radius:16px;padding:30px;box-shadow:0 15px 45px rgba(15,23,42,.1)}.brand{display:flex;gap:12px;align-items:center;margin-bottom:24px}.mark{display:grid;place-items:center;width:48px;height:48px;border-radius:15px;background:#2563eb;color:#fff;font-size:25px;font-weight:800}.brand h1{font-size:22px;margin:0}.brand p{margin:3px 0 0;color:#64748b}.field{display:grid;gap:7px;margin:15px 0}.field label{font-weight:700}.field input{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}.button{width:100%;margin-top:12px;border:0;border-radius:8px;padding:12px;background:#2563eb;color:#fff;font-weight:700;font-size:15px;cursor:pointer}.alert{padding:12px 14px;border-radius:9px;margin:0 0 15px;line-height:1.6}.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a}.hint{font-size:12px;color:#64748b;margin-top:16px;line-height:1.65}</style></head><body><main class="card">'
        . '<div class="brand"><div class="mark">T</div><div><h1>Tenyen Analytics</h1><p>Administration v0.5.7</p></div></div>'
        . $errorHtml . $httpWarning
        . '<form method="post" autocomplete="on"><input type="hidden" name="tya_action" value="login"><input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<div class="field"><label for="username">Username</label><input id="username" name="username" autocomplete="username" required autofocus></div>'
        . '<div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>'
        . '<button class="button" type="submit">' . $h($translator->get('auth.login')) . '</button></form>'
        . '<p class="hint">Authentication uses a protected PHP session. Basic Authentication remains available for compatible clients.</p>'
        . '<p class="hint">Tenyen Analytics v0.5.7 — <a href="https://www.10yendama.com/" target="_blank" rel="noopener noreferrer">Powered by 10yendama.com</a> — © 2026 10yendama.com</p>'
        . '</main></body></html>';
    exit;
}

function tyaa_require_auth(array $config, bool $json = false): void
{
    tyaa_start_session($config);

    if (isset($_GET['logout']) && (string)$_GET['logout'] === '1' && !$json) {
        tyaa_logout($config);
    }

    if (tyaa_session_valid($config)) {
        $_SESSION['tya_last_seen'] = time();
        return;
    }

    // Keep Basic auth as a compatibility fallback for scripts and existing clients.
    [$basicUser, $basicPassword] = tyaa_basic_credentials();
    if (tyaa_credentials_valid($config, $basicUser, $basicPassword)) {
        session_regenerate_id(true);
        $_SESSION['tya_admin_authenticated'] = true;
        $_SESSION['tya_admin_user'] = $basicUser;
        $_SESSION['tya_last_seen'] = time();
        return;
    }

    $error = '';
    if (!$json && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['tya_action'] ?? '') === 'login') {
        if (!tyaa_verify_csrf((string)($_POST['csrf'] ?? ''))) {
            $error = 'This page has expired. Reload it and try again.';
        } else {
            $user = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if (tyaa_credentials_valid($config, $user, $password)) {
                session_regenerate_id(true);
                $_SESSION['tya_admin_authenticated'] = true;
                $_SESSION['tya_admin_user'] = $user;
                $_SESSION['tya_last_seen'] = time();
                header('Location: ./', true, 303);
                exit;
            }
            $error = 'The username or password is incorrect.';
        }
    }

    if ($json) {
        tyaa_json(['ok' => false, 'message' => 'Your login session has expired. Reload the administration page and sign in again.'], 401);
    }
    tyaa_render_login($config, $error);
}
