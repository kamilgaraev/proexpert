<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$acceptanceStarted = hrtime(true);
require_once __DIR__.'/runtime.php';
$acceptanceAutoloaded = hrtime(true);

$data = \Tests\Runtime\BimDeviceAcceptance\descriptor();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__bim_acceptance/control' && isset($data['control_port'])
    && (int) ($_SERVER['SERVER_PORT'] ?? 0) !== $data['control_port']) {
    http_response_code(403);

    return;
}
if (is_string($path) && str_starts_with($path, '/__bim_acceptance/')) {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        if ($origin !== $data['ui_origin']) {
            http_response_code(403);

            return;
        }
        header('Access-Control-Allow-Origin: '.$origin);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Vary: Origin');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);

            return;
        }
    }
}
if ($path === '/__bim_acceptance/health') {
    header('Content-Type: application/json');
    echo '{"status":"ready"}';

    return;
}
if ($path === '/__bim_acceptance/device-config') {
    $signature = (string) ($_GET['signature'] ?? '');
    if (! hash_equals(hash_hmac('sha256', 'device-config|'.$data['expires_at'], $data['file_signing_key']), $signature)) {
        http_response_code(403);

        return;
    }
    header('Content-Type: application/json');
    header('Cache-Control: private, no-store');
    echo json_encode($data['device_config'], JSON_THROW_ON_ERROR);

    return;
}
if ($path === '/__bim_acceptance/control') {
    $signature = (string) ($_GET['signature'] ?? '');
    if (! hash_equals(hash_hmac('sha256', 'control|'.$data['expires_at'], $data['file_signing_key']), $signature)) {
        http_response_code(403);

        return;
    }
    $file = fopen($data['runtime_directory'].'/control.json', 'c+');
    if ($file === false || ! flock($file, LOCK_EX)) {
        http_response_code(503);

        return;
    }
    try {
        $stored = stream_get_contents($file);
        $state = $stored === '' ? ['phases' => [], 'commands' => []] : json_decode($stored, true, 16, JSON_THROW_ON_ERROR);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = file_get_contents('php://input', false, null, 0, 16385);
            if ($body === false || strlen($body) > 16384) {
                http_response_code(413);

                return;
            }
            $change = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (isset($change['reset_pair'])) {
                if (! is_array($change) || count($change) !== 1 || ! is_string($change['reset_pair'])
                    || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $change['reset_pair']) !== 1) {
                    http_response_code(422);

                    return;
                }
                $apiProof = $state['phases']['mobile']['apiComplete'] ?? null;
                if (! is_array($apiProof) || ($apiProof['actor'] ?? null) !== 'mobile' || ($apiProof['phase'] ?? null) !== 'apiComplete') {
                    http_response_code(409);

                    return;
                }
                $pairRunId = strtolower($change['reset_pair']);
                if (($state['run_id'] ?? null) !== $pairRunId) {
                    $state = ['run_id' => $pairRunId, 'pair_started_at' => time(),
                        'phases' => ['admin' => [], 'mobile' => ['apiComplete' => $apiProof]], 'commands' => []];
                }
            } elseif (isset($change['actor'], $change['phase'])
                && in_array($change['actor'], ['mobile', 'admin'], true)
                && is_string($change['phase']) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $change['phase']) === 1) {
                $state['phases'][$change['actor']][$change['phase']] = ['at' => time()] + $change;
            } elseif (isset($change['commands']) && is_array($change['commands']) && count($change['commands']) <= 100) {
                $state['commands'] = $change['commands'];
            } else {
                http_response_code(422);

                return;
            }
            rewind($file);
            ftruncate($file, 0);
            fwrite($file, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($file);
        }
        header('Content-Type: application/json');
        header('Cache-Control: private, no-store');
        echo json_encode($state, JSON_THROW_ON_ERROR);
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }

    return;
}
if ($path === '/__bim_acceptance/file') {
    $key = (string) ($_GET['path'] ?? '');
    $expires = filter_var($_GET['expires'] ?? null, FILTER_VALIDATE_INT);
    $signature = (string) ($_GET['signature'] ?? '');
    $prefix = 'org-'.$data['organization_id'].'/';
    if (! is_int($expires) || $expires < time() || $expires > $data['expires_at']
        || ! str_starts_with($key, $prefix) || str_contains($key, '..') || str_contains($key, '\\')
        || preg_match('#^org-[1-9][0-9]*/[A-Za-z0-9/_\-.]+$#D', $key) !== 1
        || ! hash_equals(hash_hmac('sha256', $key.'|'.$expires, $data['file_signing_key']), $signature)) {
        http_response_code(403);

        return;
    }
    $file = realpath($data['runtime_directory'].'/files/'.$key);
    $orgDirectory = realpath($data['runtime_directory'].'/files/'.rtrim($prefix, '/'));
    if ($file === false || $orgDirectory === false || ! is_file($file)
        || ! str_starts_with($file, $orgDirectory.DIRECTORY_SEPARATOR)) {
        http_response_code(404);

        return;
    }
    header('Content-Type: '.((new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream'));
    header('Content-Length: '.filesize($file));
    header('Cache-Control: private, no-store');
    readfile($file);

    return;
}
$acceptanceBeforeApplication = hrtime(true);
$request = Request::capture();
$acceptanceBootstrapProfile = [];
$app = \Tests\Runtime\BimDeviceAcceptance\application($data, $request, $acceptanceBootstrapProfile);
$acceptanceExceptionHandler = $app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
if ($acceptanceExceptionHandler instanceof \Illuminate\Foundation\Exceptions\Handler) {
    $acceptanceExceptionHandler->reportable(static function (\Throwable $exception) use ($data): void {
        \Tests\Runtime\BimDeviceAcceptance\recordException($data, $exception);
    });
}
$acceptanceBootstrapped = hrtime(true);
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);
$acceptanceHandled = hrtime(true);
$response->send();
$kernel->terminate($request, $response);
$acceptanceTerminated = hrtime(true);
if (is_string($path) && preg_match('#^/api/v1/(?:admin|mobile)/[A-Za-z0-9/_-]{1,500}$#D', $path) === 1) {
    $acceptanceTimingFile = $data['runtime_directory'].'/http-timings.jsonl';
    if (! is_file($acceptanceTimingFile) || filesize($acceptanceTimingFile) < 1048576) {
        file_put_contents($acceptanceTimingFile, json_encode([
            'completed_at' => gmdate('Y-m-d\TH:i:s\Z'), 'port' => (int) ($_SERVER['SERVER_PORT'] ?? 0),
            'method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'status' => $response->getStatusCode(),
            'opcache_loaded' => extension_loaded('Zend OPcache'),
            'bootstrap_profile' => $acceptanceBootstrapProfile,
            'autoload_ms' => round(($acceptanceAutoloaded - $acceptanceStarted) / 1000000, 2),
            'before_application_ms' => round(($acceptanceBeforeApplication - $acceptanceStarted) / 1000000, 2),
            'bootstrap_ms' => round(($acceptanceBootstrapped - $acceptanceBeforeApplication) / 1000000, 2),
            'kernel_ms' => round(($acceptanceHandled - $acceptanceBootstrapped) / 1000000, 2),
            'terminate_ms' => round(($acceptanceTerminated - $acceptanceHandled) / 1000000, 2),
            'total_router_ms' => round(($acceptanceTerminated - $acceptanceStarted) / 1000000, 2),
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }
}
