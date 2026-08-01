<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSheetAnalysisOperation;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use DateTimeImmutable;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationAuditEvent;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use LogicException;
use Throwable;

final class SheetAnalysisOperationJournal
{
    /**
     * The journal is deliberately written before the provider call. A completed entry contains
     * the provider result, so a lost worker can publish it on the next leased unit attempt.
     *
     * @param array<string, mixed> $routing
     * @param callable(): VisionAnalysisData $wire
     */
    public function run(string $operationId, string $kind, DocumentSheetOperationScope $scope, array $routing, callable $wire): SheetAnalysisOperationRun
    {
        $this->ensure($operationId, $kind, $scope, $routing);
        $claimed = $this->claim($operationId, $scope);
        if (! $claimed) {
            $stored = EstimateGenerationSheetAnalysisOperation::query()->find($operationId);
            if (! $stored instanceof EstimateGenerationSheetAnalysisOperation) {
                throw new LogicException('Sheet analysis operation disappeared.');
            }
            if ($stored->status === 'completed' && is_array($stored->analysis_payload)) {
                return SheetAnalysisOperationRun::replayed($this->analysis($stored->analysis_payload), $this->routing($stored->final_routing, $routing));
            }
            if (in_array($stored->status, ['needs_review', 'exhausted'], true)) {
                return SheetAnalysisOperationRun::needsReview($this->routing($stored->final_routing, $routing));
            }
            throw new SheetAnalysisOperationBusy('sheet_analysis_operation_in_progress');
        }

        try {
            $analysis = $wire();
            $finalRouting = $routing;
            $this->complete($operationId, $kind, $scope, $analysis, $finalRouting);

            return SheetAnalysisOperationRun::performed($analysis, $finalRouting);
        } catch (Throwable $exception) {
            $status = $exception instanceof \App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionProviderException
                && $exception->reason === 'vision_wire_replay_forbidden' ? 'needs_review' : 'failed';
            $this->fail($operationId, $kind, $scope, $status, $exception::class);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $routing */
    private function ensure(string $operationId, string $kind, DocumentSheetOperationScope $scope, array $routing): void
    {
        try {
            EstimateGenerationSheetAnalysisOperation::query()->create([
                'operation_id' => $operationId, 'kind' => $kind, ...$scope->attributes(),
                'status' => 'queued', 'lease_token' => null,
                'lease_expires_at' => null, 'attempt_count' => 0,
                'initial_routing' => $routing, 'final_routing' => [], 'analysis_payload' => [],
            ]);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23505') {
                throw $exception;
            }
        }
        $stored = EstimateGenerationSheetAnalysisOperation::query()->find($operationId);
        if (! $stored instanceof EstimateGenerationSheetAnalysisOperation
            || $stored->kind !== $kind
            || (int) $stored->organization_id !== $scope->organizationId
            || (int) $stored->project_id !== $scope->projectId
            || (int) $stored->session_id !== $scope->sessionId
            || (int) $stored->document_id !== $scope->documentId
            || (int) $stored->unit_id !== $scope->unitId
            || (string) $stored->source_version !== $scope->sourceVersion) {
            throw new LogicException('Sheet analysis operation identity is bound to different source.');
        }
    }

    private function claim(string $operationId, DocumentSheetOperationScope $scope): bool
    {
        return EstimateGenerationSheetAnalysisOperation::query()
            ->whereKey($operationId)->where($scope->attributes())
            ->where(function ($query): void {
                $query->whereIn('status', ['queued', 'failed'])->orWhere(function ($expired): void {
                    $expired->where('status', 'claimed')->where('lease_expires_at', '<=', now());
                });
            })->update([
                'status' => 'claimed', 'lease_token' => $scope->claimToken, 'lease_expires_at' => $this->leaseExpiry(),
                'attempt_count' => \Illuminate\Support\Facades\DB::raw('attempt_count + 1'), 'updated_at' => now(),
            ]) === 1;
    }

    /** @param array<string, mixed> $routing */
    private function complete(string $operationId, string $kind, DocumentSheetOperationScope $scope, VisionAnalysisData $analysis, array $routing): void
    {
        $this->transition($operationId, $kind, $scope, 'completed', null, [
            'analysis_payload' => $analysis->toArray(), 'final_routing' => $routing, 'completed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $routing */
    public function persistFinalRouting(string $operationId, DocumentSheetOperationScope $scope, array $routing): void
    {
        if (EstimateGenerationSheetAnalysisOperation::query()->whereKey($operationId)->where($scope->attributes())
            ->where('status', 'completed')->update(['final_routing' => $routing, 'updated_at' => now()]) !== 1) {
            throw new LogicException('Completed sheet analysis operation is unavailable.');
        }
    }

    private function fail(string $operationId, string $kind, DocumentSheetOperationScope $scope, string $status, string $reason): void
    {
        $this->transition($operationId, $kind, $scope, $status, $reason);
    }

    /** @param array<string, mixed> $attributes */
    private function transition(string $operationId, string $kind, DocumentSheetOperationScope $scope, string $status, ?string $reason, array $attributes = []): void
    {
        DB::transaction(function () use ($operationId, $kind, $scope, $status, $reason, $attributes): void {
            $operation = EstimateGenerationSheetAnalysisOperation::query()->whereKey($operationId)->where($scope->attributes())->lockForUpdate()->first();
            if (! $operation instanceof EstimateGenerationSheetAnalysisOperation || $operation->status !== 'claimed' || $operation->lease_token !== $scope->claimToken) {
                throw new SheetAnalysisOperationBusy('sheet_analysis_operation_lease_lost');
            }
            $operation->forceFill([
                ...$attributes,
                'status' => $status,
                'failure_reason' => $reason,
                'lease_token' => null,
                'lease_expires_at' => null,
            ])->save();
            if ($kind !== 'targeted') {
                return;
            }
            $session = EstimateGenerationSession::query()->find($scope->sessionId);
            if (! $session instanceof EstimateGenerationSession) {
                throw new LogicException('Sheet analysis session disappeared.');
            }
            $payload = [
                'operation_id' => $operationId,
                'attempt' => (string) $operation->attempt_count,
                'status' => $status,
                'document_id' => $scope->documentId,
                'unit_id' => $scope->unitId,
                'source_version' => $scope->sourceVersion,
                'reason' => $reason,
            ];
            try {
                EstimateGenerationAuditEvent::query()->create([
                    'session_id' => $session->id,
                    'package_id' => null,
                    'user_id' => $session->user_id,
                    'event_type' => 'sheet_targeted_reanalysis_transition',
                    'payload' => $payload,
                ]);
            } catch (QueryException $exception) {
                if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23505') {
                    throw $exception;
                }
            }
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    private function analysis(array $payload): VisionAnalysisData
    {
        $provider = $payload['provider'] ?? null; $requested = $payload['requested_model'] ?? null;
        $reported = $payload['reported_model'] ?? null; $version = $payload['model_version'] ?? null; $usage = $payload['usage'] ?? null;
        if (!is_string($provider) || !is_string($requested) || !is_string($reported) || !is_string($version) || !is_array($usage)) {
            throw new LogicException('Persisted sheet analysis is invalid.');
        }
        $raw = $payload; unset($raw['provider'], $raw['requested_model'], $raw['reported_model'], $raw['model_version'], $raw['usage']);
        return VisionAnalysisData::fromProviderArray($raw, $provider, $requested, $reported, $version,
            (string) ($usage['status'] ?? ''), is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
            is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null, 500, 500);
    }

    /** @param mixed $stored @param array<string, mixed> $fallback @return array<string, mixed> */
    private function routing(mixed $stored, array $fallback): array { return is_array($stored) && $stored !== [] ? $stored : $fallback; }
    private function leaseExpiry(): DateTimeImmutable
    {
        return (new DateTimeImmutable)->modify(sprintf('+%d seconds', self::LEASE_SECONDS));
    }

    private const LEASE_SECONDS = 1860;
}
