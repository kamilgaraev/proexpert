<?php

namespace App\Jobs;

use App\BusinessModules\Features\BudgetEstimates\Services\Normative\Import\NormativeImportService;
use App\Models\NormativeImportLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportNormativeBaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 1;

    public function __construct(
        protected string $filePath,
        protected string $baseTypeCode,
        protected int $userId,
        protected int $organizationId,
        protected ?int $importLogId = null
    ) {
        $this->onQueue(config('estimates-enterprise.performance.background_jobs.queue', 'default'));
    }

    public function handle(NormativeImportService $importService): void
    {
        $importLog = $this->getOrCreateImportLog();

        try {
            $importLog->update([
                'status' => 'processing',
                'started_at' => now(),
            ]);

            $result = $importService->importFromFile($this->filePath, $this->baseTypeCode);

            $importLog->update([
                'status' => $result['success'] ? 'completed' : 'failed',
                'completed_at' => now(),
                'stats' => $result['stats'],
                'errors' => $result['errors'] ?? [],
                'error_message' => $result['error'] ?? null,
            ]);

            if (Storage::disk('local')->exists($this->filePath)) {
                Storage::disk('local')->delete($this->filePath);
            }

            Log::info('Импорт нормативной базы завершен', [
                'import_log_id' => $importLog->id,
                'stats' => $result['stats'],
            ]);

        } catch (\Exception $e) {
            $importLog->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
                'errors' => [['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]],
            ]);

            Log::error('Ошибка импорта нормативной базы', [
                'import_log_id' => $importLog->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function getOrCreateImportLog(): NormativeImportLog
    {
        if ($this->importLogId) {
            return NormativeImportLog::findOrFail($this->importLogId);
        }

        return NormativeImportLog::create([
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'base_type_code' => $this->baseTypeCode,
            'file_path' => $this->filePath,
            'status' => 'queued',
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->importLogId) {
            $importLog = NormativeImportLog::find($this->importLogId);
            
            if ($importLog) {
                $importLog->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error_message' => $exception->getMessage(),
                ]);
            }
        }
    }
}

