<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateEstimateDraftJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'estimate-generation';

    public int $tries = 1;

    public int $timeout = 1200;

    public bool $failOnTimeout = true;

    public function __construct(
        private readonly int $sessionId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(EstimateGenerationOrchestrator $orchestrator): void
    {
        $session = EstimateGenerationSession::query()->find($this->sessionId);

        if (!$session instanceof EstimateGenerationSession) {
            return;
        }

        if (in_array($session->status, ['generated', 'applied'], true)) {
            return;
        }

        $session->forceFill([
            'status' => 'processing',
            'processing_stage' => 'draft_generation',
            'processing_progress' => 45,
            'last_error' => null,
        ])->save();

        $orchestrator->generate($session);
    }

    public function failed(\Throwable $exception): void
    {
        EstimateGenerationSession::query()
            ->where('id', $this->sessionId)
            ->update([
                'status' => 'failed',
                'processing_stage' => 'failed',
                'processing_progress' => 0,
                'last_error' => mb_substr($exception->getMessage(), 0, 500),
                'updated_at' => now(),
            ]);

        Log::error('[EstimateGeneration] Draft generation job failed', [
            'session_id' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
