<?php

namespace App\Jobs;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportErrorMessage;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportPipelineService;
use App\Models\ImportSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessEstimateImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200; // Increased timeout for parsing and enrichment

    public int $tries = 1; // Don't retry parsing automatically if it fails logic

    public function __construct(
        public string $sessionId
    ) {
        $this->queue = 'imports';
    }

    public function handle(ImportPipelineService $pipeline): void
    {
        Log::info("[ProcessEstimateImportJob] Handling session: {$this->sessionId}");

        $session = ImportSession::find($this->sessionId);

        if (! $session) {
            Log::error("[ProcessEstimateImportJob] Session not found: {$this->sessionId}");

            return;
        }

        if ($session->status === 'failed') {
            Log::warning("[ProcessEstimateImportJob] Session already marked failed: {$this->sessionId}");

            return;
        }

        try {
            $pipeline->run($session);
        } catch (Throwable $e) {
            Log::error('[ProcessEstimateImportJob] Pipeline failed', ['session_id' => $this->sessionId, 'exception' => $e]);
            $userMessage = ImportErrorMessage::fromException($e);
            $stats = $session->stats ?? [];
            unset($stats['technical_error_message'], $stats['error_trace']);

            $session->update([
                'status' => 'failed',
                'error_message' => $userMessage,
                'stats' => array_merge($stats, [
                    'message' => $userMessage,
                ]),
            ]);

            $this->fail($e);
        }
    }
}
