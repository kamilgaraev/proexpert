<?php

namespace App\Jobs;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\Models\EstimateImportHistory;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEstimateImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public string $fileId,
        public int $userId,
        public int $organizationId,
        public array $matchingConfig,
        public array $estimateSettings,
        public string $jobId
    ) {}

    public function handle(EstimateImportService $service): void
    {
        $history = EstimateImportHistory::where('job_id', $this->jobId)->first();
        
        if (!$history) {
            Log::error("Import history not found for job_id: {$this->jobId}");
            return;
        }
        
        try {
            $history->update(['status' => 'processing', 'progress' => 0]);
            
            Log::info("Starting estimate import", [
                'job_id' => $this->jobId,
                'file_id' => $this->fileId,
                'user_id' => $this->userId,
                'organization_id' => $this->organizationId,
            ]);
            
            $result = $service->syncImport(
                $this->fileId,
                $this->matchingConfig,
                $this->estimateSettings,
                $this->jobId
            );
            
            $history->update([
                'status' => 'completed',
                'progress' => 100,
                'estimate_id' => $result['estimate_id'] ?? null,
                'items_imported' => $result['result']['items_imported'] ?? 0,
                'items_skipped' => $result['result']['items_skipped'] ?? 0,
                'items_total' => $result['result']['items_total'] ?? 0,
                'result_log' => $result['result'] ?? [],
                'processing_time_ms' => $result['result']['processing_time_ms'] ?? null,
            ]);
            
            Log::info("Estimate import completed successfully", [
                'job_id' => $this->jobId,
                'estimate_id' => $result['estimate_id'] ?? null,
            ]);
            
        } catch (\Throwable $e) {
            Log::error("Estimate import failed", [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $history->update([
                'status' => 'failed',
                'progress' => 0,
                'result_log' => [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
            ]);
            
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Estimate import job failed permanently", [
            'job_id' => $this->jobId,
            'file_id' => $this->fileId,
            'error' => $exception->getMessage(),
        ]);
        
        $history = EstimateImportHistory::where('job_id', $this->jobId)->first();
        
        if ($history) {
            $history->update([
                'status' => 'failed',
                'result_log' => [
                    'error' => $exception->getMessage(),
                    'failed_at' => now()->toIso8601String(),
                ],
            ]);
        }
    }
}

