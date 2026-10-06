<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;

final readonly class RecoverExhaustedDocumentUnits
{
    public function __construct(
        private DocumentUnitExhaustionHandler $handler,
        private DocumentUnitAggregateReconciler $reconciler,
        private Connection $database,
    ) {}

    public function handle(int $limit = 100): int
    {
        $now = now()->toDateTimeImmutable();
        $ids = EstimateGenerationProcessingUnit::query()
            ->where(static function (Builder $query) use ($now): void {
                $query->where(static fn (Builder $failed): Builder => $failed
                    ->where('status', DocumentProcessingUnitStatus::Failed->value)
                    ->where('attempt_count', '>=', ProcessDocumentUnit::MAX_ATTEMPTS))
                    ->orWhere(static fn (Builder $running): Builder => $running
                        ->where('status', DocumentProcessingUnitStatus::Running->value)
                        ->where('attempt_count', '>=', ProcessDocumentUnit::MAX_ATTEMPTS)
                        ->where('lease_expires_at', '<=', $now))
                    ->orWhere(static fn (Builder $dispatch): Builder => $dispatch
                        ->where('dispatch_attempt_count', '>=', DispatchDocumentProcessingUnits::MAX_DISPATCH_ATTEMPTS)
                        ->where(static fn (Builder $eligible): Builder => $eligible
                            ->where(static fn (Builder $queued): Builder => $queued
                                ->whereIn('status', [DocumentProcessingUnitStatus::Pending->value, DocumentProcessingUnitStatus::Failed->value])
                                ->where(static fn (Builder $due): Builder => $due
                                    ->whereNull('next_dispatch_at')
                                    ->orWhere('next_dispatch_at', '<=', $now)))
                            ->orWhere(static fn (Builder $expired): Builder => $expired
                                ->where('status', DocumentProcessingUnitStatus::Running->value)
                                ->where('lease_expires_at', '<=', $now))));
            })
            ->whereHas('document', static fn ($query) => $query->whereIn('status', ['queued', 'processing']))
            ->orderByRaw("CASE WHEN status = 'failed' THEN 1 ELSE 0 END")
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $unitId) {
            if ($this->finalize((int) $unitId, $now)) {
                $this->handler->handle((int) $unitId);
            }
        }

        $completed = EstimateGenerationProcessingUnit::query()
            ->where('status', DocumentProcessingUnitStatus::Completed->value)
            ->where('dispatch_attempt_count', '>=', DispatchDocumentProcessingUnits::MAX_DISPATCH_ATTEMPTS)
            ->whereHas('document', static fn (Builder $query): Builder => $query
                ->whereIn('status', ['queued', 'processing'])
                ->whereColumn('estimate_generation_documents.source_version', 'estimate_generation_processing_units.source_version')
                ->where(static fn (Builder $unreconciled): Builder => $unreconciled
                    ->whereNull('units_reconciled_source_version')
                    ->orWhereColumn('units_reconciled_source_version', '<>', 'estimate_generation_processing_units.source_version')))
            ->distinct()
            ->orderBy('document_id')
            ->limit($limit)
            ->get(['document_id', 'source_version']);

        foreach ($completed as $unit) {
            $this->reconciler->reconcile((int) $unit->document_id, (string) $unit->source_version);
        }

        return $ids->count() + $completed->count();
    }

    private function finalize(int $unitId, DateTimeImmutable $now): bool
    {
        return $this->database->transaction(function () use ($unitId, $now): bool {
            $documentId = EstimateGenerationProcessingUnit::query()->whereKey($unitId)->value('document_id');
            if ($documentId === null) {
                return false;
            }

            $document = EstimateGenerationDocument::query()->lock('for update skip locked')->find((int) $documentId);
            if ($document === null) {
                return false;
            }

            $unit = EstimateGenerationProcessingUnit::query()->lock('for update skip locked')->find($unitId);
            if ($unit === null || (int) $unit->document_id !== (int) $document->id) {
                return false;
            }
            if ($unit->status === DocumentProcessingUnitStatus::Failed
                && (int) $unit->attempt_count >= ProcessDocumentUnit::MAX_ATTEMPTS) {
                return true;
            }
            if (! in_array((string) $document->status, ['queued', 'processing'], true)
                || (string) $document->processing_control_status !== 'active'
                || ! hash_equals((string) $document->source_version, (string) $unit->source_version)) {
                return false;
            }

            $expiredRunning = $unit->status === DocumentProcessingUnitStatus::Running
                && $unit->lease_expires_at?->toDateTimeImmutable() <= $now;
            $attemptsExhausted = $expiredRunning && (int) $unit->attempt_count >= ProcessDocumentUnit::MAX_ATTEMPTS;
            $dispatchesExhausted = (int) $unit->dispatch_attempt_count >= DispatchDocumentProcessingUnits::MAX_DISPATCH_ATTEMPTS
                && ($expiredRunning || (in_array($unit->status, [DocumentProcessingUnitStatus::Pending, DocumentProcessingUnitStatus::Failed], true)
                    && ($unit->next_dispatch_at === null || new DateTimeImmutable((string) $unit->next_dispatch_at) <= $now)));
            if (! $attemptsExhausted && ! $dispatchesExhausted) {
                return false;
            }

            $failureCode = $attemptsExhausted ? 'document_unit_attempts_exhausted' : 'document_unit_dispatch_exhausted';
            $unit->forceFill([
                'status' => DocumentProcessingUnitStatus::Failed,
                'attempt_count' => max(ProcessDocumentUnit::MAX_ATTEMPTS, (int) $unit->attempt_count),
                'claim_token' => null,
                'lease_expires_at' => null,
                'next_dispatch_at' => null,
                'failed_at' => $now,
                'failure_code' => $failureCode,
                'failure_fingerprint' => hash('sha256', $failureCode),
                'metadata' => [
                    ...(array) $unit->metadata,
                    'failure_category' => 'recoverable',
                    'actual_execution_count' => (int) $unit->attempt_count,
                ],
            ])->save();
            $this->database->table('estimate_generation_document_pages')
                ->where('processing_unit_id', $unitId)
                ->where('organization_id', $unit->organization_id)
                ->where('project_id', $unit->project_id)
                ->where('session_id', $unit->session_id)
                ->where('document_id', $unit->document_id)
                ->where('source_version', $unit->source_version)
                ->where('status', '<>', 'excluded')
                ->update(['status' => 'failed', 'updated_at' => $now]);

            return true;
        }, 3);
    }
}
