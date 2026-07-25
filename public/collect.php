<?php

declare(strict_types=1);

use Tenyen\Analytics\BotDetector;
use Tenyen\Analytics\IpResolver;
use Tenyen\Analytics\Payload;
use Tenyen\Analytics\RateLimiter;
use Tenyen\Analytics\TrafficAttribution;
use Tenyen\Analytics\UserAgentParser;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

try {
    $services = require dirname(__DIR__) . '/app/bootstrap.php';
    $root = $services['root'];
    $config = $services['config'];
    $pdo = $services['pdo'];
    $crypto = $services['crypto'];
    $geoIp = $services['geoIp'];
    $app = $config['app'] ?? [];

    $input = json_decode((string)file_get_contents('php://input'), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new InvalidArgumentException('JSON object required.');
    }

    $expected = (string)($app['site_token'] ?? '');
    $provided = isset($input['token']) ? (string)$input['token'] : '';
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'invalid_token']);
        exit;
    }

    $siteHost = strtolower((string)parse_url((string)($app['site_url'] ?? $app['base_url'] ?? ''), PHP_URL_HOST));
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
        if (!empty($_SERVER[$header])) {
            $requestHost = strtolower((string)parse_url((string)$_SERVER[$header], PHP_URL_HOST));
            if ($siteHost !== '' && $requestHost !== '' && !hash_equals($siteHost, $requestHost)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'cross_site']);
                exit;
            }
            break;
        }
    }

    $ip = IpResolver::resolve($_SERVER, (string)($app['trusted_proxy_header'] ?? ''));
    $ipHash = $ip !== '' ? $crypto->hashIp($ip) : null;
    $limiter = new RateLimiter($root . '/storage/ratelimit', 120, 60);
    if ($ipHash !== null && !$limiter->allow(bin2hex($ipHash))) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited']);
        exit;
    }

    $payload = Payload::normalize($input);
    $attribution = TrafficAttribution::classify($payload['path'], $payload['referrer'], (string)($app['site_url'] ?? ''));
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1024);
    $isBot = BotDetector::isBot($ua);
    if ($isBot && !($app['log_bots'] ?? true)) {
        http_response_code(202);
        echo json_encode(['ok' => true, 'bot_excluded' => true]);
        exit;
    }

    $geo = $geoIp->lookup($ip);
    $agent = UserAgentParser::parse($ua);

    $sql = <<<'SQL'
INSERT INTO tya_events (
    occurred_at, event_type, event_name, visitor_id, session_id, ip_encrypted, ip_hash, ip_version,
    country_code, country_name, region, city, latitude, longitude, accuracy_radius,
    asn, asn_org, path, page_title, referrer, traffic_channel, referrer_domain,
    utm_source, utm_medium, utm_campaign, utm_content, utm_term, target_url, event_metadata, user_agent, browser, os,
    device_type, language, timezone, screen, viewport, duration_ms, scroll_depth, is_bot
) VALUES (
    :occurred_at, :event_type, :event_name, :visitor_id, :session_id, :ip_encrypted, :ip_hash, :ip_version,
    :country_code, :country_name, :region, :city, :latitude, :longitude, :accuracy_radius,
    :asn, :asn_org, :path, :page_title, :referrer, :traffic_channel, :referrer_domain,
    :utm_source, :utm_medium, :utm_campaign, :utm_content, :utm_term, :target_url, :event_metadata, :user_agent, :browser, :os,
    :device_type, :language, :timezone, :screen, :viewport, :duration_ms, :scroll_depth, :is_bot
)
SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'occurred_at' => gmdate('Y-m-d H:i:s'),
        'event_type' => $payload['event'],
        'event_name' => $payload['event_name'],
        'visitor_id' => $payload['visitor_id'],
        'session_id' => $payload['session_id'],
        'ip_encrypted' => $ip !== '' ? $crypto->encryptIp($ip) : null,
        'ip_hash' => $ipHash,
        'ip_version' => IpResolver::version($ip),
        'country_code' => $geo['country_code'],
        'country_name' => $geo['country_name'],
        'region' => $geo['region'],
        'city' => $geo['city'],
        'latitude' => $geo['latitude'],
        'longitude' => $geo['longitude'],
        'accuracy_radius' => $geo['accuracy_radius'],
        'asn' => $geo['asn'],
        'asn_org' => $geo['asn_org'],
        'path' => $payload['path'],
        'page_title' => $payload['title'],
        'referrer' => $payload['referrer'],
        'traffic_channel' => $attribution['channel'],
        'referrer_domain' => $attribution['referrer_domain'],
        'utm_source' => $attribution['utm_source'],
        'utm_medium' => $attribution['utm_medium'],
        'utm_campaign' => $attribution['utm_campaign'],
        'utm_content' => $attribution['utm_content'],
        'utm_term' => $attribution['utm_term'],
        'target_url' => $payload['target_url'],
        'event_metadata' => $payload['metadata'] ? json_encode($payload['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'user_agent' => $ua,
        'browser' => $agent['browser'],
        'os' => $agent['os'],
        'device_type' => $agent['device'],
        'language' => $payload['language'],
        'timezone' => $payload['timezone'],
        'screen' => $payload['screen'],
        'viewport' => $payload['viewport'],
        'duration_ms' => $payload['duration_ms'],
        'scroll_depth' => $payload['scroll_depth'],
        'is_bot' => $isBot ? 1 : 0,
    ]);

    http_response_code(201);
    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
} catch (JsonException|InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request']);
} catch (Throwable $e) {
    error_log('[Tenyen Analytics] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
