<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentDocumentUnitDispatchStore implements DocumentUnitDispatchStore
{
    public function __construct(private Connection $database) {}

    public function dueForDocument(int $documentId, string $sourceVersion, DateTimeImmutable $now, int $limit): array
    {
        return $this->candidates(
            $this->due($now)->where('document_id', $documentId)->where('source_version', $sourceVersion),
            $limit,
        );
    }

    public function dueForRecovery(DateTimeImmutable $now, int $limit): array
    {
        return $this->candidates($this->due($now), $limit);
    }

    public function markDispatched(int $unitId, DateTimeImmutable $now, DateTimeImmutable $nextDispatchAt): void
    {
        $this->query()->whereKey($unitId)->update([
            'dispatch_attempt_count' => $this->database->raw('dispatch_attempt_count + 1'),
            'last_dispatched_at' => $now,
            'next_dispatch_at' => $nextDispatchAt,
            'updated_at' => $now,
        ]);
    }

    /** @return Builder<EstimateGenerationProcessingUnit> */
    private function due(DateTimeImmutable $now): Builder
    {
        return $this->query()
            ->whereHas('document', static fn (Builder $query): Builder => $query
                ->whereColumn('estimate_generation_documents.source_version', 'estimate_generation_processing_units.source_version')
                ->where('estimate_generation_documents.status', '<>', 'ignored'))
            ->where(static function (Builder $query) use ($now): void {
                $query->where(static fn (Builder $pending): Builder => $pending
                    ->where('status', DocumentProcessingUnitStatus::Pending->value)
                    ->where(static fn (Builder $due): Builder => $due->whereNull('next_dispatch_at')->orWhere('next_dispatch_at', '<=', $now)))
                    ->orWhere(static fn (Builder $running): Builder => $running
                        ->where('status', DocumentProcessingUnitStatus::Running->value)
                        ->where('lease_expires_at', '<=', $now))
                    ->orWhere(static fn (Builder $failed): Builder => $failed
                        ->where('status', DocumentProcessingUnitStatus::Failed->value)
                        ->where('attempt_count', '<', ProcessDocumentUnit::MAX_ATTEMPTS)
                        ->where(static fn (Builder $due): Builder => $due->whereNull('next_dispatch_at')->orWhere('next_dispatch_at', '<=', $now)))
                    ->orWhere(static fn (Builder $completed): Builder => $completed
                        ->where('status', DocumentProcessingUnitStatus::Completed->value)
                        ->where(static fn (Builder $due): Builder => $due->whereNull('next_dispatch_at')->orWhere('next_dispatch_at', '<=', $now))
                        ->whereHas('document', static fn (Builder $document): Builder => $document
                            ->whereNull('units_reconciled_source_version')
                            ->orWhereColumn('units_reconciled_source_version', '<>', 'estimate_generation_processing_units.source_version')));
            });
    }

    /**
     * @param  Builder<EstimateGenerationProcessingUnit>  $query
     * @return list<DocumentUnitDispatchCandidate>
     */
    private function candidates(Builder $query, int $limit): array
    {
        return $query->with('document:id,meta')->orderBy('id')->limit($limit)->get(['id', 'document_id', 'source_version'])
            ->map(static function (EstimateGenerationProcessingUnit $unit): DocumentUnitDispatchCandidate {
                $meta = is_array($unit->document?->meta) ? $unit->document->meta : [];

                return new DocumentUnitDispatchCandidate(
                    (int) $unit->id,
                    (string) $unit->source_version,
                    is_string($meta['retry_requested_at'] ?? null),
                );
            })
            ->all();
    }

    /** @return Builder<EstimateGenerationProcessingUnit> */
    private function query(): Builder
    {
        $model = new EstimateGenerationProcessingUnit;
        $model->setConnection($this->database->getName());

        return $model->newQuery();
    }
}
