<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\VideoMonitoring\Contracts;

use App\BusinessModules\Features\VideoMonitoring\Models\VideoCamera;

interface StreamProvisionerInterface
{
    public function driver(): string;

    public function isConfigured(): bool;

    public function sync(VideoCamera $camera): array;

    public function remove(VideoCamera $camera): void;
}
