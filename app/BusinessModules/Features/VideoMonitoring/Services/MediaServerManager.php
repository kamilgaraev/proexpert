<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\VideoMonitoring\Services;

use App\BusinessModules\Features\VideoMonitoring\Contracts\StreamProvisionerInterface;
use App\BusinessModules\Features\VideoMonitoring\Models\VideoCamera;

class MediaServerManager
{
    public function __construct(
        private readonly StreamProvisionerInterface $provisioner
    ) {
    }

    public function sync(VideoCamera $camera): array
    {
        return $this->provisioner->sync($camera);
    }

    public function remove(VideoCamera $camera): void
    {
        $this->provisioner->remove($camera);
    }

    public function driver(): string
    {
        return $this->provisioner->driver();
    }

    public function isConfigured(): bool
    {
        return $this->provisioner->isConfigured();
    }
}
