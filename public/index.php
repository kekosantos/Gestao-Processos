<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use LexCloud\Support\Database;
use LexCloud\Support\Http;
use LexCloud\Support\Security;

$path = Http::path();
$method = Http::method();

if (str_starts_with($path, '/api/')) {
    require dirname(__DIR__) . '/app/ApiController.php';
    \LexCloud\ApiController::dispatch();
    exit;
}

if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo 'Método não permitido.';
    exit;
}

$knownPages = ['/', '/login', '/cadastro', '/offline'];
if (!in_array($path, $knownPages, true) && !str_starts_with($path, '/app/')) {
    http_response_code(404);
}

// Liveness/readiness page never exposes configuration or database errors.
if ($path === '/api/health') {
    try {
        Database::connection()->query('SELECT 1');
        Http::respond(['status' => 'ok', 'service' => 'lexcloud']);
    } catch (Throwable) {
        Http::respond(['status' => 'unavailable', 'service' => 'lexcloud'], 503);
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
$csrf = htmlspecialchars(Security::csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$title = 'LexCloud — Gestão Jurídica';
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0b5b55">
  <meta name="description" content="Gestão clara de processos, tarefas, prazos e clientes para escritórios de advocacia.">
  <meta name="csrf-token" content="<?= $csrf ?>">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
  <link rel="stylesheet" href="/assets/app.css?v=24">
  <script defer src="/assets/app.js?v=24"></script>
</head>
<body>
  <a class="skip-link" href="#main">Pular para o conteúdo</a>
  <main id="main" class="app-root" aria-live="polite">
    <div class="boot-screen"><span class="brand-mark" aria-hidden="true">L</span><p>Preparando seu escritório…</p></div>
  </main>
  <noscript>O LexCloud precisa de JavaScript habilitado para apresentar os módulos interativos.</noscript>
</body>
</html>
