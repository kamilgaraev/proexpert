<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\VideoMonitoring\Services;

use App\BusinessModules\Features\VideoMonitoring\Contracts\StreamProvisionerInterface;
use App\BusinessModules\Features\VideoMonitoring\Models\VideoCamera;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MediaMtxStreamProvisioner implements StreamProvisionerInterface
{
    public function __construct(
        private readonly array $config
    ) {
    }

    public function driver(): string
    {
        return 'mediamtx';
    }

    public function isConfigured(): bool
    {
        return filled($this->apiBaseUrl()) && filled($this->publicBaseUrl('webrtc'));
    }

    public function sync(VideoCamera $camera): array
    {
        $streamName = $this->buildStreamName($camera);
        $webrtcUrl = $this->buildPublicUrl('webrtc', $streamName);
        $hlsUrl = $this->buildPublicUrl('hls', $streamName);
        $preferredProtocol = (string) Arr::get($this->config, 'preferred_live_protocol', 'webrtc');
        $playbackUrl = $preferredProtocol === 'hls' ? $hlsUrl : $webrtcUrl;

        if (!$this->isConfigured()) {
            return [
                'driver' => $this->driver(),
                'configured' => false,
                'managed' => false,
                'stream_name' => $streamName,
                'webrtc_url' => $webrtcUrl,
                'hls_url' => $hlsUrl,
                'playback_url' => $playbackUrl,
                'message' => trans_message('video_monitoring.media_server.not_configured', [], 'ru'),
                'synced_at' => now()->toIso8601String(),
                'metadata' => [],
            ];
        }

        if (!$this->shouldManagePaths()) {
            return [
                'driver' => $this->driver(),
                'configured' => true,
                'managed' => false,
                'stream_name' => $streamName,
                'webrtc_url' => $webrtcUrl,
                'hls_url' => $hlsUrl,
                'playback_url' => $playbackUrl,
                'message' => trans_message('video_monitoring.media_server.prepared', [], 'ru'),
                'synced_at' => now()->toIso8601String(),
                'metadata' => [],
            ];
        }

        $this->upsertPath($streamName, $camera);

        return [
            'driver' => $this->driver(),
            'configured' => true,
            'managed' => true,
            'stream_name' => $streamName,
            'webrtc_url' => $webrtcUrl,
            'hls_url' => $hlsUrl,
            'playback_url' => $playbackUrl,
            'message' => trans_message('video_monitoring.media_server.synced', [], 'ru'),
            'synced_at' => now()->toIso8601String(),
            'metadata' => [],
        ];
    }

    public function remove(VideoCamera $camera): void
    {
        if (!$this->isConfigured() || !$this->shouldManagePaths()) {
            return;
        }

        $response = $this->httpClient()->post($this->pathDeleteUrl($this->buildStreamName($camera)));

        if ($response->failed() && $response->status() !== 404) {
            throw new RuntimeException(
                $response->json('message')
                    ?: $response->body()
                    ?: trans_message('video_monitoring.media_server.remove_failed', [], 'ru')
            );
        }
    }

    private function upsertPath(string $streamName, VideoCamera $camera): void
    {
        $payload = [
            'source' => $this->buildSourceUrl($camera),
            'sourceOnDemand' => (bool) Arr::get($this->config, 'mediamtx.source_on_demand', true),
            'sourceProtocol' => $camera->transport_protocol === 'udp' ? 'udp' : 'tcp',
            'record' => false,
        ];

        $createResponse = $this->httpClient()->post($this->pathAddUrl($streamName), $payload);

        if ($createResponse->successful()) {
            return;
        }

        if (!in_array($createResponse->status(), [400, 409], true)) {
            throw new RuntimeException(
                $createResponse->json('message')
                    ?: $createResponse->body()
                    ?: trans_message('video_monitoring.media_server.sync_failed', [], 'ru')
            );
        }

        $patchResponse = $this->httpClient()->patch($this->pathPatchUrl($streamName), $payload);

        if ($patchResponse->failed()) {
            throw new RuntimeException(
                $patchResponse->json('message')
                    ?: $patchResponse->body()
                    ?: trans_message('video_monitoring.media_server.sync_failed', [], 'ru')
            );
        }
    }

    private function buildSourceUrl(VideoCamera $camera): string
    {
        if (blank($camera->username) || blank($camera->password)) {
            return (string) $camera->source_url;
        }

        $parts = parse_url((string) $camera->source_url);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return (string) $camera->source_url;
        }

        $scheme = (string) $parts['scheme'];
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $username = rawurlencode((string) $camera->username);
        $password = rawurlencode((string) $camera->password);

        return sprintf('%s://%s:%s@%s%s%s%s', $scheme, $username, $password, $host, $port, $path, $query);
    }

    private function buildStreamName(VideoCamera $camera): string
    {
        $prefix = trim((string) Arr::get($this->config, 'mediamtx.path_prefix', 'prohelper'), '/');

        return sprintf(
            '%s/org-%d/project-%d/camera-%d',
            $prefix !== '' ? $prefix : 'prohelper',
            $camera->organization_id,
            $camera->project_id,
            $camera->id
        );
    }

    private function buildPublicUrl(string $protocol, string $streamName): ?string
    {
        $baseUrl = $this->publicBaseUrl($protocol);

        if (!filled($baseUrl)) {
            return null;
        }

        $trimmed = rtrim($baseUrl, '/');
        $encodedName = str_replace('%2F', '/', rawurlencode($streamName));

        return match ($protocol) {
            'hls' => $trimmed . '/' . $encodedName . '/index.m3u8',
            default => $trimmed . '/' . $encodedName,
        };
    }

    private function pathAddUrl(string $streamName): string
    {
        return rtrim($this->apiBaseUrl(), '/') . '/v3/config/paths/add/' . $this->encodePathName($streamName);
    }

    private function pathPatchUrl(string $streamName): string
    {
        return rtrim($this->apiBaseUrl(), '/') . '/v3/config/paths/patch/' . $this->encodePathName($streamName);
    }

    private function pathDeleteUrl(string $streamName): string
    {
        return rtrim($this->apiBaseUrl(), '/') . '/v3/config/paths/remove/' . $this->encodePathName($streamName);
    }

    private function encodePathName(string $streamName): string
    {
        return str_replace('%2F', '/', rawurlencode($streamName));
    }

    private function publicBaseUrl(string $protocol): ?string
    {
        return Arr::get($this->config, 'mediamtx.public_urls.' . $protocol);
    }

    private function apiBaseUrl(): ?string
    {
        return Arr::get($this->config, 'mediamtx.api_base_url');
    }

    private function shouldManagePaths(): bool
    {
        return (bool) Arr::get($this->config, 'mediamtx.manage_paths', false);
    }

    private function httpClient(): PendingRequest
    {
        $request = Http::timeout((int) Arr::get($this->config, 'timeout', 5))
            ->acceptJson();

        if (!(bool) Arr::get($this->config, 'verify_tls', true)) {
            $request = $request->withoutVerifying();
        }

        $token = Arr::get($this->config, 'mediamtx.api_token');

        if (filled($token)) {
            $request = $request->withToken($token);
        }

        return $request;
    }
}
