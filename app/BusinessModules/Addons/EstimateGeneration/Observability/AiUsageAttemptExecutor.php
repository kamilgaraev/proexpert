<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class AiUsageAttemptExecutor
{
    public function __construct(private AiUsageStore $store) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $attempt
     * @param  Closure(): AiUsageData  $measurement
     * @return T
     */
    public function execute(Closure $attempt, Closure $measurement): mixed
    {
        try {
            return $attempt();
        } finally {
            $usage = null;
            try {
                $usage = $measurement();
                $this->store->record($usage);
            } catch (Throwable $exception) {
                if ($usage?->context->creditOperationId !== null) {
                    throw $exception;
                }
                try {
                    Log::error('[EstimateGeneration] AI usage recording failed', [
                        'exception_class' => $exception::class,
                    ]);
                } catch (Throwable) {
                }
            }
        }
    }
}
