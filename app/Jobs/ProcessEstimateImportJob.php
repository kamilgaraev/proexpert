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

class ProcessEstimateImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // Increased timeout for parsing large files
    public int $tries = 1; // Don't retry parsing automatically if it fails logic
    public string $queue = 'imports';

    public function __construct(
        public string $sessionId,
        public array $config = []
    ) {}

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
            $pipeline->run($session, $this->config);
        } catch (\Throwable $e) {
            Log::error("[ProcessEstimateImportJob] Pipeline failed: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            
            $session->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'stats' => array_merge($session->stats ?? [], ['error_trace' => $e->getTraceAsString()])
            ]);
            
            $this->fail($e);
        }
    }
}
