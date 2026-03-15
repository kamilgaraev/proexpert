<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\VideoMonitoring\Services;

use App\BusinessModules\Features\VideoMonitoring\Contracts\StreamProvisionerInterface;
use App\BusinessModules\Features\VideoMonitoring\Models\VideoCamera;

class NullStreamProvisioner implements StreamProvisionerInterface
{
    public function driver(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function sync(VideoCamera $camera): array
    {
        return [
            'driver' => $this->driver(),
            'configured' => false,
            'managed' => false,
            'stream_name' => null,
            'webrtc_url' => null,
            'hls_url' => null,
            'playback_url' => null,
            'message' => trans_message('video_monitoring.media_server.not_configured', [], 'ru'),
            'synced_at' => now()->toIso8601String(),
            'metadata' => [],
        ];
    }

    public function remove(VideoCamera $camera): void
    {
    }
}
