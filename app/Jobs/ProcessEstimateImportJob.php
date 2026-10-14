<?php

declare(strict_types=1);

namespace App\Jobs;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportErrorMessage;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportPipelineService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\Models\ImportSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

class ProcessEstimateImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public ?string $runToken = null;

    public function __construct(public string $sessionId, ?string $runToken = null)
    {
        $this->runToken = $runToken;
        $this->queue = 'imports';
        $this->connection = 'redis_estimate_imports';
    }

    public function handle(ImportPipelineService $pipeline): void
    {
        $session = DB::transaction(function (): ?ImportSession {
            $session = ImportSession::query()->whereKey($this->sessionId)->lockForUpdate()->first();
            if (! $session || $session->status !== 'queued'
                || ($session->options['run_token'] ?? null) !== $this->runToken) {
                return null;
            }
            if ($this->runToken === null) {
                $this->failed(null);

                return null;
            }
            try {
                app(EstimateImportService::class)->assertExecutionAccess($session);
            } catch (AuthorizationException|ModelNotFoundException $exception) {
                $this->failed($exception);

                return null;
            }
            $session->update(['status' => 'parsing', 'stats' => array_merge($session->stats ?? [], [
                'worker_deadline_at' => now()->addSeconds($this->timeout + 60)->timestamp,
            ])]);

            return $session;
        });
        if ($session === null) {
            return;
        }
        try {
            $pipeline->run($session);
        } catch (Throwable $exception) {
            $this->failed($exception);
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $session = ImportSession::query()->whereKey($this->sessionId)->lockForUpdate()->first();
            if (! $session || ! $session->isActive() || ($session->options['run_token'] ?? null) !== $this->runToken) {
                return;
            }
            $correlationId = (string) Str::uuid();
            $accessDenied = $exception instanceof AuthorizationException || $exception instanceof ModelNotFoundException;
            $reasonCode = $accessDenied ? 'import_execution_access_denied' : 'import_processing_failed';
            $message = $accessDenied ? trans_message('estimate.import_access_denied')
                : ($exception === null ? trans_message('estimate.import_worker_interrupted') : ImportErrorMessage::fromException($exception));
            $stats = $session->stats ?? [];
            unset($stats['technical_error_message'], $stats['error_trace']);
            $session->update(['status' => 'failed', 'error_message' => $message, 'stats' => array_merge($stats, [
                'message' => $message, 'reason_code' => $reasonCode, 'correlation_id' => $correlationId,
            ])]);
            Cache::forget("import_session_progress_{$this->sessionId}");
            Log::error('[EstimateImport] Worker failed', [
                'session_id' => $this->sessionId, 'correlation_id' => $correlationId,
                'reason_code' => $reasonCode, 'exception_class' => $exception === null ? null : $exception::class,
            ]);
        });
    }
}
