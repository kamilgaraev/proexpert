<?php

declare(strict_types=1);

require_once __DIR__.'/runtime.php';

$data = \Tests\Runtime\BimDeviceAcceptance\descriptor();
$port = $data['control_port'] ?? null;
if (! is_int($port) || (int) ($_SERVER['SERVER_PORT'] ?? 0) !== $port
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:'.$port) {
    http_response_code(403);

    return;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (! in_array($path, ['/__bim_acceptance/control', '/__bim_acceptance/health'], true)) {
    http_response_code(404);

    return;
}
if (! in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST', 'OPTIONS'], true)
    || ($path === '/__bim_acceptance/health' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET')) {
    header('Allow: '.($path === '/__bim_acceptance/health' ? 'GET' : 'GET, POST, OPTIONS'));
    http_response_code(405);

    return;
}

require __DIR__.'/router.php';
