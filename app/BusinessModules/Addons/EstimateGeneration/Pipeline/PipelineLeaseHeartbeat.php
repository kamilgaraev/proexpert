<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use Closure;

final readonly class PipelineLeaseHeartbeat
{
    /** @var Closure(): bool */
    private Closure $renewal;

    /** @param callable(): bool $renewal */
    public function __construct(callable $renewal)
    {
        $this->renewal = Closure::fromCallable($renewal);
    }

    public function renew(): bool
    {
        return ($this->renewal)();
    }
}
