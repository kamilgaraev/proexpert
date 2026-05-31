<?php

namespace App\Jobs;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportPipelineService;
use App\Models\ImportSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

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
        
        if (!$session) {
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
            Log::error("[ProcessEstimateImportJob] Pipeline failed: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $userMessage = $this->userFacingErrorMessage($e);
            
            $session->update([
                'status' => 'failed',
                'error_message' => $userMessage,
                'stats' => array_merge($session->stats ?? [], [
                    'message' => $userMessage,
                    'technical_error_message' => $e->getMessage(),
                    'error_trace' => $e->getTraceAsString(),
                ])
            ]);
            
            $this->fail($e);
        }
    }

    private function userFacingErrorMessage(Throwable $e): string
    {
        $message = trim($e->getMessage());

        if ($message === '') {
            return trans_message('estimate.import_failed');
        }

        return str_starts_with($message, 'estimate.') ? trans_message($message) : $message;
    }
}
