<?php

declare(strict_types=1);

const READINESS_PROTOCOL = 'most-onlyoffice-http/1';
const RESPONSE_BODY = 'most-curl-runtime';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$server = null;
$connection = null;

try {
    $token = $argv[1] ?? '';
    if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
        throw new RuntimeException('Invalid readiness token.');
    }
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($server === false) {
        throw new RuntimeException(sprintf('Unable to bind HTTP server: %d %s', $errorCode, $errorMessage));
    }
    $address = stream_socket_get_name($server, false);
    if (! is_string($address) || preg_match('/:(\d+)$/D', $address, $matches) !== 1) {
        throw new RuntimeException('Unable to determine bound HTTP port.');
    }
    $port = (int) $matches[1];
    fwrite(STDOUT, json_encode([
        'protocol' => READINESS_PROTOCOL,
        'token' => $token,
        'pid' => getmypid(),
        'port' => $port,
    ], JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);

    $connection = stream_socket_accept($server, 5);
    if ($connection === false) {
        throw new RuntimeException('HTTP client did not connect before timeout.');
    }
    stream_set_timeout($connection, 5);
    $request = '';
    while (! str_contains($request, "\r\n\r\n")) {
        if (strlen($request) >= 8192) {
            throw new RuntimeException('HTTP request headers are too large.');
        }
        $chunk = fread($connection, min(1024, 8192 - strlen($request)));
        if ($chunk === false) {
            throw new RuntimeException('Unable to read HTTP request.');
        }
        if ($chunk === '') {
            $metadata = stream_get_meta_data($connection);
            if (($metadata['timed_out'] ?? false) === true || feof($connection)) {
                throw new RuntimeException('HTTP request ended before complete headers.');
            }

            continue;
        }
        $request .= $chunk;
    }
    $requestLine = strstr($request, "\r\n", true);
    if (! is_string($requestLine) || preg_match('#^GET /document HTTP/1\.[01]$#D', $requestLine) !== 1) {
        throw new RuntimeException('Unexpected HTTP request line.');
    }
    $response = "HTTP/1.1 200 OK\r\n"
        ."Content-Type: application/octet-stream\r\n"
        .'Content-Length: '.strlen(RESPONSE_BODY)."\r\n"
        ."Connection: close\r\n\r\n"
        .RESPONSE_BODY;
    $written = 0;
    while ($written < strlen($response)) {
        $bytes = fwrite($connection, substr($response, $written));
        if ($bytes === false || $bytes === 0) {
            throw new RuntimeException('Unable to write HTTP response.');
        }
        $written += $bytes;
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
    exit(1);
} finally {
    if (is_resource($connection)) {
        fclose($connection);
    }
    if (is_resource($server)) {
        fclose($server);
    }
}
