<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

use DomainException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class OnlyOfficeBoundedDocumentFetcher implements EditorDocumentFetcher
{
    private HttpClientInterface $client;

    public function __construct(private readonly ?array $configuration = null, ?HttpClientInterface $client = null)
    {
        $this->client = $client ?? HttpClient::create();
    }

    public function fetch(string $url, string $expectedExtension): DownloadedEditorDocument
    {
        $config = $this->configuration ?? (array) config('legal-document-editor.download', []);
        $maxSize = max(1, (int) ($config['max_size_bytes'] ?? 104857600));
        $maxRedirects = min(3, max(0, (int) ($config['max_redirects'] ?? 1)));
        $timeout = min(30.0, max(1.0, (float) ($config['timeout_seconds'] ?? 10)));
        $current = $url;

        for ($redirect = 0; $redirect <= $maxRedirects; $redirect++) {
            $resolved = $this->safeResolution($current, (array) ($config['allowed_hosts'] ?? []));
            $pinnedIp = (string) reset($resolved);
            $connectedIpValidated = false;
            $response = $this->client->request('GET', $current, [
                'max_redirects' => 0,
                'timeout' => $timeout,
                'max_duration' => $timeout,
                'headers' => ['Accept' => 'application/octet-stream'],
                'resolve' => $resolved,
                'proxy' => '',
                'no_proxy' => '*',
                'on_progress' => function (int $downloaded, int $downloadSize, array $info) use ($pinnedIp, &$connectedIpValidated): void {
                    if (! isset($info['primary_ip'])) {
                        return;
                    }
                    $primaryIp = trim((string) $info['primary_ip'], '[]');
                    if (filter_var($primaryIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                        || ! hash_equals(strtolower($pinnedIp), strtolower($primaryIp))) {
                        throw new DomainException('legal_document_editor_download_url_denied');
                    }
                    $connectedIpValidated = true;
                },
            ]);
            $status = $response->getStatusCode();
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
            $declared = isset($headers['content-length'][0]) ? (int) $headers['content-length'][0] : null;
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
                foreach ($this->client->stream($response) as $chunk) {
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
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            throw new DomainException('legal_document_editor_download_url_denied');
        }
        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new DomainException('legal_document_editor_download_url_denied');
            }
            $addresses[] = $ip;
        }

        return [$host => $addresses[0]];
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
        $allowed = (array) config('file-uploads.legal_archive.allowed_mime_types.'.strtolower($extension), []);

        return in_array($mime, $allowed, true);
    }
}
