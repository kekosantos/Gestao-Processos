<?php
declare(strict_types=1);

require_once __DIR__ . '/version.php';

use LexCloud\Support\Http;
use LexCloud\Support\Security;

spl_autoload_register(static function (string $class): void {
    $prefix = 'LexCloud\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('default_charset', 'UTF-8');

Security::startSession();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: private, no-store, max-age=0');

set_exception_handler(static function (Throwable $exception): void {
    error_log('LexCloud unhandled exception: ' . get_class($exception) . ' ' . $exception->getMessage());
    if (str_starts_with(Http::path(), '/api/')) {
        Http::respond(['error' => 'Ocorreu um erro interno. Tente novamente ou contate o administrador do escritório.'], 500);
    }
    http_response_code(500);
    echo 'Erro interno. Atualize a página ou contate o administrador do escritório.';
});
