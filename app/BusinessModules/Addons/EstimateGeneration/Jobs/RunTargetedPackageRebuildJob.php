<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;

final class RunTargetedPackageRebuildJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const CONNECTION = 'redis_estimate_generation';

    public const QUEUE = 'estimate-generation';

    public function __construct(private readonly string $operationId)
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $operationId) !== 1) {
            throw new InvalidArgumentException('Targeted rebuild operation identifier is invalid.');
        }

        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function operationId(): string
    {
        return $this->operationId;
    }
}
