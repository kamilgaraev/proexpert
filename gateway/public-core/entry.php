<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli' && count($argv ?? []) === 3 && $argv[1] === 'serve'
    && PHP_OS_FAMILY === 'Linux' && extension_loaded('sockets')
    && defined('SO_PASSCRED') && defined('SCM_CREDENTIALS')) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    try {
        \App\Services\Privacy\Gateway\GatewayPublicCoreTransport::serveProtected($argv[2]);
        exit(0);
    } catch (\Throwable) {
    }
}

http_response_code(503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'schemaVersion' => 'public-core-gateway-entry/1',
    'status' => 'unavailable',
    'reasonCode' => 'runtime_not_activated',
], JSON_THROW_ON_ERROR);
