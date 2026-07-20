<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

use Closure;
use DomainException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class OnlyOfficeBoundedDocumentFetcher implements EditorDocumentFetcher
{
    private const ABSOLUTE_MAX_SIZE_BYTES = 104857600;

    private const ABSOLUTE_MAX_CONNECT_SECONDS = 30.0;

    private const ABSOLUTE_MAX_IDLE_SECONDS = 60.0;

    private const ABSOLUTE_MAX_DURATION_SECONDS = 1800.0;

    private HttpClientInterface $client;

    private ?Closure $resolver;

    public function __construct(
        private readonly ?array $configuration = null,
        ?HttpClientInterface $client = null,
        ?callable $resolver = null,
    ) {
        $this->client = $client ?? HttpClient::create(['proxy' => '', 'no_proxy' => '*']);
        $this->resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public function fetch(string $url, string $expectedExtension): DownloadedEditorDocument
    {
        $config = $this->configuration ?? (array) config('legal-document-editor.download', []);
        $maxSize = min(self::ABSOLUTE_MAX_SIZE_BYTES, max(1, (int) ($config['max_size_bytes'] ?? self::ABSOLUTE_MAX_SIZE_BYTES)));
        $maxRedirects = min(3, max(0, (int) ($config['max_redirects'] ?? 1)));
        $connectTimeout = min(self::ABSOLUTE_MAX_CONNECT_SECONDS, max(1.0, (float) ($config['connect_timeout_seconds'] ?? 10)));
        $idleTimeout = min(self::ABSOLUTE_MAX_IDLE_SECONDS, max(1.0, (float) ($config['idle_timeout_seconds'] ?? 30)));
        $maxDuration = min(self::ABSOLUTE_MAX_DURATION_SECONDS, max($idleTimeout, (float) ($config['max_duration_seconds'] ?? 900)));
        $current = $url;

        for ($redirect = 0; $redirect <= $maxRedirects; $redirect++) {
            $resolved = $this->safeResolution($current, (array) ($config['allowed_hosts'] ?? []));
            $pinnedIp = (string) reset($resolved);
            $connectedIpValidated = false;
            try {
                $response = $this->client->request('GET', $current, [
                    'max_redirects' => 0,
                    'timeout' => $connectTimeout,
                    'max_duration' => $maxDuration,
                    'headers' => ['Accept' => 'application/octet-stream'],
                    'resolve' => $resolved,
                    'proxy' => '',
                    'no_proxy' => '*',
                    'on_progress' => function (int $downloaded, int $downloadSize, array $info) use ($pinnedIp, $maxSize, &$connectedIpValidated): void {
                        if ($downloadSize > $maxSize || $downloaded > $maxSize) {
                            throw new DomainException('legal_document_editor_download_too_large');
                        }
                        if (! isset($info['primary_ip'])) {
                            return;
                        }
                        $primaryIp = trim((string) $info['primary_ip'], '[]');
                        if (filter_var($primaryIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                            || ! hash_equals($this->normalizedIp($pinnedIp), $this->normalizedIp($primaryIp))) {
                            throw new DomainException('legal_document_editor_download_url_denied');
                        }
                        $connectedIpValidated = true;
                    },
                ]);
                $status = $response->getStatusCode();
            } catch (TransportExceptionInterface $error) {
                throw $this->normalizedTransportError($error);
            }
            if (! $connectedIpValidated) {
                throw new DomainException('legal_document_editor_download_url_denied');
            }
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->getHeaders(false)['location'][0] ?? null;
                if (! is_string($location) || $redirect === $maxRedirects) {
                    throw new DomainException('legal_document_editor_download_redirect_denied');
                }
                $current = $this->absoluteRedirect($current, $location);

                continue;
            }
            if ($status !== 200) {
                throw new DomainException('legal_document_editor_download_failed');
            }
            $headers = $response->getHeaders(false);
            $lengthHeaders = $headers['content-length'] ?? [];
            if (count($lengthHeaders) > 1 || (isset($lengthHeaders[0]) && preg_match('/^[0-9]+$/D', $lengthHeaders[0]) !== 1)) {
                throw new DomainException('legal_document_editor_download_failed');
            }
            $declared = isset($lengthHeaders[0]) ? (int) $lengthHeaders[0] : null;
            if ($declared !== null && ($declared < 1 || $declared > $maxSize)) {
                throw new DomainException('legal_document_editor_download_too_large');
            }
            $path = tempnam(sys_get_temp_dir(), 'most-editor-');
            if (! is_string($path)) {
                throw new DomainException('legal_document_editor_download_failed');
            }
            $handle = fopen($path, 'wb');
            if ($handle === false) {
                @unlink($path);
                throw new DomainException('legal_document_editor_download_failed');
            }
            $size = 0;
            $hash = hash_init('sha256');
            try {
                foreach ($this->client->stream($response, $idleTimeout) as $chunk) {
                    if ($chunk->isTimeout()) {
                        throw new DomainException('legal_document_editor_download_timeout');
                    }
                    if ($chunk->isFirst() || $chunk->isLast()) {
                        continue;
                    }
                    $content = $chunk->getContent();
                    $size += strlen($content);
                    if ($size > $maxSize) {
                        throw new DomainException('legal_document_editor_download_too_large');
                    }
                    hash_update($hash, $content);
                    if (fwrite($handle, $content) !== strlen($content)) {
                        throw new DomainException('legal_document_editor_download_failed');
                    }
                }
                fclose($handle);
                $handle = null;
                if ($size < 1) {
                    throw new DomainException('legal_document_editor_download_failed');
                }
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
                if (! is_string($mime) || ! $this->mimeAllowed($expectedExtension, $mime)) {
                    throw new DomainException('legal_document_editor_download_mime_invalid');
                }

                return new DownloadedEditorDocument($path, 'edited.'.strtolower($expectedExtension), $mime, $size, hash_final($hash));
            } catch (Throwable $error) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                @unlink($path);
                if ($error instanceof TransportExceptionInterface) {
                    throw $this->normalizedTransportError($error);
                }
                throw $error;
            }
        }

        throw new DomainException('legal_document_editor_download_failed');
    }

    /** @return array<string, string> */
    private function safeResolution(string $url, array $allowedHosts): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (($parts['scheme'] ?? null) !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || ! in_array($host, array_map('strtolower', $allowedHosts), true)) {
            throw new DomainException('legal_document_editor_download_url_denied');
        }
        $addresses = $this->resolver === null
            ? $this->resolveWithDns($host)
            : ($this->resolver)($host);
        if (! is_array($addresses) || $addresses === []) {
            throw new DomainException('legal_document_editor_download_url_denied');
        }
        foreach ($addresses as $ip) {
            if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new DomainException('legal_document_editor_download_url_denied');
            }
        }

        return [$host => $addresses[0]];
    }

    /** @return list<string> */
    private function resolveWithDns(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }

        return $addresses;
    }

    private function normalizedIp(string $ip): string
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            throw new DomainException('legal_document_editor_download_url_denied');
        }

        return bin2hex($packed);
    }

    private function normalizedTransportError(TransportExceptionInterface $error): DomainException
    {
        foreach (['legal_document_editor_download_too_large', 'legal_document_editor_download_url_denied'] as $message) {
            if (str_contains($error->getMessage(), $message)) {
                return new DomainException($message, previous: $error);
            }
        }

        return new DomainException('legal_document_editor_download_timeout', previous: $error);
    }

    private function absoluteRedirect(string $source, string $location): string
    {
        if (str_starts_with($location, 'https://')) {
            return $location;
        }
        $parts = parse_url($source);
        if (! str_starts_with($location, '/') || ! is_array($parts) || ! isset($parts['host'])) {
            throw new DomainException('legal_document_editor_download_redirect_denied');
        }

        return 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$location;
    }

    private function mimeAllowed(string $extension, string $mime): bool
    {
        $allowed = (array) (($this->configuration['allowed_mime_types'][strtolower($extension)] ?? null)
            ?? config('file-uploads.legal_archive.allowed_mime_types.'.strtolower($extension), []));

        return in_array($mime, $allowed, true);
    }
}
