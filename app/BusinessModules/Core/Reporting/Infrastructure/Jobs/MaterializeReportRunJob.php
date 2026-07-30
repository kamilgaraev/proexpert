<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Jobs;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCatalog;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CanonicalReportSourceHashBuilder;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportProgressWritePolicy;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotIdentityViolationReason;
use App\BusinessModules\Core\Reporting\Domain\Exceptions\ReportSnapshotIdentityViolation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class MaterializeReportRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $runId)
    {
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $runId) !== 1) {
            throw new \InvalidArgumentException('report_run_id_invalid');
        }
        $this->onConnection('redis_reports');
        $this->onQueue('reports');
    }

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(
        ReportRunAttemptLifecycleStore $attempts,
        ReportRunExecutionContextRehydrator $contexts,
        ReportRunStore $runs,
        ReportDefinitionRegistry $definitions,
        ReportDefinitionBindingAssembler $bindings,
        CanonicalReportSourceHashBuilder $sourceHashes,
        ReportProgressWritePolicy $progressPolicy,
        ReportExecutionClock $clock,
        ReportExecutionTelemetry $telemetry,
    ): void {
        $leaseToken = $this->envelopeUuid();
        $claimedAt = $clock->now();
        $leaseExpiresAt = $claimedAt->modify('+960 seconds');
        if (! $attempts->claimOrRenew($this->runId, $leaseToken, $leaseExpiresAt, $claimedAt)) {
            return;
        }
        $startedAt = hrtime(true);

        $run = null;
        try {
            $context = $contexts->forRun($this->runId);
            $run = $runs->get($context, $this->runId);
            $run = $runs->claimMaterialization(
                $context,
                $this->runId,
                $leaseToken,
                $leaseExpiresAt,
                $claimedAt,
            );
            $query = $runs->queryForRun($context, $this->runId);
            $published = $definitions->published($run->reportCode);
            $binding = $bindings->assemble($definitions)->get($run->reportCode);
            if (
                $published->definitionHash->value !== $run->definitionHash->value
                || $query->definition->definitionHash->value !== $run->definitionHash->value
                || $binding->definitionHash->value !== $run->definitionHash->value
                || $binding->contractVersion !== $run->contractVersion
            ) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
            }
            Log::info('report_run_execution_started', [
                'run_id' => $this->runId,
                'report_code' => $run->reportCode,
                'definition_hash' => $run->definitionHash->value,
                'query_hash' => $run->queryHash->value,
            ]);

            $persistedProgress = new ReportProgress($run->progress);
            $progress = new ReportProgress($run->progress);
            try {
                $snapshot = $binding->dataProvider->materialize($context, $query, $progress);
            } catch (ReportSnapshotIdentityViolation $exception) {
                throw $this->mapIdentityViolation($exception);
            } catch (\InvalidArgumentException $exception) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, previous: $exception);
            }
            $afterMaterialization = $clock->now();
            if ($progressPolicy->shouldPersist($persistedProgress, $progress, $run->updatedAt, $afterMaterialization)) {
                $runs->persistProgress(
                    $context,
                    $this->runId,
                    $leaseToken,
                    $progress,
                    $afterMaterialization->modify('+960 seconds'),
                    $afterMaterialization,
                );
            }

            $result = $binding->dataProvider->result($context, $snapshot);
            $sourceHash = $sourceHashes->build($query, $snapshot, $result);
            $progress->advance(100);
            $runs->sealReady($context, $this->runId, $leaseToken, $snapshot, $result, $sourceHash, $clock->now());
            $telemetry->runTransition($run->reportCode, ReportRunStatus::READY->value);
            $telemetry->runDuration(
                $run->reportCode,
                ReportRunStatus::READY->value,
                $this->elapsedSeconds($startedAt),
            );
            Log::info('report_run_execution_ready', [
                'run_id' => $this->runId,
                'report_code' => $run->reportCode,
                'definition_hash' => $run->definitionHash->value,
                'query_hash' => $run->queryHash->value,
                'source_hash' => $sourceHash->value,
                'snapshot_id' => $snapshot->id,
                'row_count' => $result->metadata->rowCount,
            ]);
        } catch (ReportContractException $exception) {
            $descriptor = ReportErrorCatalog::descriptor($exception->errorCode);
            if (! $descriptor->retryable) {
                $failed = $attempts->failLeased(
                    $this->runId,
                    $leaseToken,
                    $exception->errorCode,
                    $clock->now(),
                );
                if ($failed && $run !== null) {
                    $telemetry->runTransition($run->reportCode, ReportRunStatus::FAILED->value);
                    $telemetry->runDuration(
                        $run->reportCode,
                        ReportRunStatus::FAILED->value,
                        $this->elapsedSeconds($startedAt),
                    );
                }
                Log::warning('report_run_execution_failed', [
                    'run_id' => $this->runId,
                    'report_code' => $run?->reportCode,
                    'definition_hash' => $run?->definitionHash->value,
                    'query_hash' => $run?->queryHash->value,
                    'error_code' => $exception->errorCode->value,
                    'retryable' => false,
                ]);

                return;
            }
            $telemetry->executionAttempt('run', $exception->errorCode->value);
            if ($run !== null) {
                $telemetry->runDuration(
                    $run->reportCode,
                    ReportRunStatus::MATERIALIZING->value,
                    $this->elapsedSeconds($startedAt),
                );
            }
            Log::warning('report_run_execution_failed', [
                'run_id' => $this->runId,
                'report_code' => $run?->reportCode,
                'definition_hash' => $run?->definitionHash->value,
                'query_hash' => $run?->queryHash->value,
                'error_code' => $exception->errorCode->value,
                'retryable' => true,
            ]);
            throw $exception;
        } catch (Throwable $exception) {
            $telemetry->executionAttempt('run', ReportErrorCode::REPORT_INTERNAL_ERROR->value);
            if ($run !== null) {
                $telemetry->runDuration(
                    $run->reportCode,
                    ReportRunStatus::MATERIALIZING->value,
                    $this->elapsedSeconds($startedAt),
                );
            }
            Log::warning('report_run_execution_failed', [
                'run_id' => $this->runId,
                'report_code' => $run?->reportCode,
                'definition_hash' => $run?->definitionHash->value,
                'query_hash' => $run?->queryHash->value,
                'error_code' => ReportErrorCode::REPORT_INTERNAL_ERROR->value,
                'retryable' => true,
            ]);
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, previous: $exception);
        }
    }

    private function envelopeUuid(): string
    {
        $uuid = $this->job?->uuid();
        if (! is_string($uuid) || ! Str::isUuid($uuid)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return strtolower($uuid);
    }

    private function mapIdentityViolation(ReportSnapshotIdentityViolation $exception): ReportContractException
    {
        $code = $exception->reason === ReportSnapshotIdentityViolationReason::OFFICIAL_SEAL_REQUIRED
            ? ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED
            : ReportErrorCode::REPORT_INTERNAL_ERROR;

        return ReportContractException::fromCode($code, previous: $exception);
    }

    private function elapsedSeconds(int $startedAt): float
    {
        return max(0.0, (hrtime(true) - $startedAt) / 1_000_000_000);
    }
}
