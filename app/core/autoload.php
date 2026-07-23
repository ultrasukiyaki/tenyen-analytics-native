<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tenyen\\Analytics\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// Composer is optional. The stable package includes a built-in MMDB reader, so
// a missing or half-uploaded vendor directory must never prevent installation.
$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($autoload)) {
    try {
        require_once $autoload;
    } catch (Throwable $e) {
        error_log('[Tenyen Analytics] Optional Composer autoloader was ignored: ' . $e->getMessage());
    }
}
