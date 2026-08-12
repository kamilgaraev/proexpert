<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\EloquentSessionBuildingModelBridge;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DocumentVisualAttributeSummaryBuilder;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class EloquentDocumentUnitAggregateReconciler implements DocumentUnitAggregateReconciler
{
    public function __construct(
        private ReconcileEstimateGenerationDocuments $sessions,
        private Connection $database,
        private EloquentSessionBuildingModelBridge $buildingModels,
        private DocumentVisualAttributeSummaryBuilder $visualAttributes = new DocumentVisualAttributeSummaryBuilder,
        private DocumentProcessingOutcomeResolver $outcomes = new DocumentProcessingOutcomeResolver,
        private DocumentResourceUsageSummarizer $resourceUsage = new DocumentResourceUsageSummarizer,
    ) {}

    public function reconcile(int $documentId, string $sourceVersion): void
    {
        $claim = $this->database->transaction(function () use ($documentId, $sourceVersion): ?array {
            $document = $this->documentQuery()->with('session')->lockForUpdate()->find($documentId);

            if (! $document instanceof EstimateGenerationDocument
                || (string) $document->source_version !== $sourceVersion
                || $document->status === 'ignored'
                || (string) $document->units_reconciled_source_version === $sourceVersion
                || ((string) $document->units_reconcile_claim_token !== '' && $document->units_reconcile_lease_expires_at?->isFuture())) {
                return null;
            }

            $base = $this->unitQuery()
                ->where('organization_id', $document->organization_id)
                ->where('project_id', $document->project_id)
                ->where('session_id', $document->session_id)
                ->where('document_id', $document->id)
                ->where('source_version', $sourceVersion);

            if (! (clone $base)->exists() || $this->hasBlockingUnits($base)) {
                return null;
            }

            $shouldRebuildBuildingModel = false;
            if ((string) $document->units_finalized_source_version !== $sourceVersion) {
                $units = (clone $base)->get(['id', 'status', 'attempt_count', 'output_count', 'metadata']);
                $currentUnitIds = $units->pluck('id');
                $document->facts()->delete();
                $document->drawingElements()->delete();
                $document->quantityTakeoffs()->delete();
                $document->scopeInferences()->delete();
                $document->pages()->where('source_version', '<>', $sourceVersion)->delete();
                $document->pages()->whereNotIn('processing_unit_id', $currentUnitIds)->delete();
                $pages = $document->pages()
                    ->whereIn('processing_unit_id', $currentUnitIds)
                    ->where('source_version', $sourceVersion)
                    ->orderBy('page_number')
                    ->get();
                $includedPages = $pages->reject(static fn ($page): bool => (string) $page->status === 'excluded');
                $excludedCount = $pages->count() - $includedPages->count();
                $outcome = $this->outcomes->resolve(
                    $pages->map(static fn ($page): array => [
                        'processing_unit_id' => (int) $page->processing_unit_id,
                        'status' => (string) $page->status,
                    ])->all(),
                    $units->map(static fn ($unit): array => [
                        'id' => (int) $unit->id,
                        'status' => $unit->status->value,
                        'output_count' => (int) $unit->output_count,
                        'metadata' => is_array($unit->metadata) ? $unit->metadata : [],
                    ])->all(),
                );
                $status = $outcome->documentStatus;
                $shouldRebuildBuildingModel = $outcome->processedPages > 0;
                $qualitySignals = $this->qualitySignals($includedPages->pluck('normalized_payload')->all());
                $visualAttributes = $this->visualAttributes->summarize($includedPages->pluck('normalized_payload')->all());
                $resourceUsage = $this->resourceUsage->summarize(
                    $includedPages->pluck('normalized_payload')->all(),
                    $units->map(static fn ($unit): array => [
                        'status' => $unit->status->value,
                        'metadata' => is_array($unit->metadata) ? $unit->metadata : [],
                    ])->all(),
                );
                $document->forceFill([
                    'extracted_text' => $includedPages->pluck('text')->filter()->implode("\n\n"),
                    'structured_payload' => [
                        'source_version' => $sourceVersion,
                        'processing_outcome' => $outcome->toArray(),
                        'pages' => $includedPages->map(fn ($page): array => [
                            'page_number' => $page->page_number,
                            'text' => $page->text,
                            'confidence' => $page->confidence,
                            'normalized_payload' => $page->normalized_payload,
                        ])->all(),
                    ],
                    'page_count' => $pages->count(),
                    'processed_page_count' => $outcome->processedPages,
                    'units_finalized_source_version' => $sourceVersion,
                    'status' => $status,
                    'processing_stage' => 'completed',
                    'progress_percent' => 100,
                    'quality_score' => $status === 'ready' ? 1.0 : null,
                    'quality_level' => $status === 'ready' ? 'good' : null,
                    'quality_flags' => $this->qualityFlags($document, $excludedCount, $outcome),
                    'facts_summary' => [
                        'processing_outcome' => $outcome->toArray(),
                        'resource_usage' => $resourceUsage,
                        ...($qualitySignals === [] ? [] : ['quality_signals' => $qualitySignals]),
                        ...$visualAttributes,
                    ],
                    'error_code' => $outcome->errorCode,
                    'error_message_key' => $outcome->errorMessageKey,
                    'error_context' => $outcome->errorCode === null ? null : ['counts' => $outcome->counts],
                    'ocr_finished_at' => now(),
                ]);
            } else {
                $factsSummary = is_array($document->facts_summary) ? $document->facts_summary : [];
                $processingOutcome = is_array($factsSummary['processing_outcome'] ?? null)
                    ? $factsSummary['processing_outcome']
                    : [];
                $counts = is_array($processingOutcome['counts'] ?? null) ? $processingOutcome['counts'] : [];
                $shouldRebuildBuildingModel = (int) ($counts['ready'] ?? 0) > 0;
            }

            $token = (string) Str::uuid();
            $document->forceFill([
                'units_reconcile_claim_token' => $token,
                'units_reconcile_lease_expires_at' => now()->addMinutes(5),
            ])->save();

            return [$document->session, $token, $shouldRebuildBuildingModel];
        }, 3);

        if ($claim === null) {
            return;
        }

        [$session, $token, $shouldRebuildBuildingModel] = $claim;

        try {
            if ($shouldRebuildBuildingModel) {
                $this->buildingModels->rebuild((int) $session->getKey());
            }
            $marked = $this->documentQuery()
                ->whereKey($documentId)
                ->where('source_version', $sourceVersion)
                ->where('units_reconcile_claim_token', $token)
                ->update([
                    'units_reconciled_source_version' => $sourceVersion,
                    'units_reconcile_claim_token' => null,
                    'units_reconcile_lease_expires_at' => null,
                    'updated_at' => now(),
                ]);
            if ($marked !== 1) {
                throw new RuntimeException('estimate_generation.document_reconciliation_marker_stale');
            }
            $this->sessions->reconcile($session);
        } catch (Throwable $error) {
            $this->documentQuery()
                ->whereKey($documentId)
                ->where('source_version', $sourceVersion)
                ->where('units_reconcile_claim_token', $token)
                ->update([
                    'units_reconcile_claim_token' => null,
                    'units_reconcile_lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

            throw $error;
        }
    }

    /**
     * @param  array<int, mixed>  $payloads
     * @return array<string, array<string, mixed>>
     */
    private function qualitySignals(array $payloads): array
    {
        $result = [];

        foreach ($payloads as $payload) {
            $signals = is_array($payload) && is_array($payload['quality_signals'] ?? null)
                ? $payload['quality_signals']
                : [];
            foreach ($signals as $domain => $signal) {
                if (! is_string($domain) || ! is_array($signal)) {
                    continue;
                }
                $confidence = $signal['confidence'] ?? null;
                if ((is_int($confidence) || is_float($confidence)) && is_finite((float) $confidence)) {
                    $current = $result[$domain]['confidence'] ?? null;
                    $result[$domain]['confidence'] = $current === null
                        ? (float) $confidence
                        : min((float) $current, (float) $confidence);
                }
                if (is_bool($signal['provider_requires_review'] ?? null)) {
                    $result[$domain]['provider_requires_review'] = ($result[$domain]['provider_requires_review'] ?? false) === true
                        || $signal['provider_requires_review'];
                }
                $blockers = is_array($signal['hard_blockers'] ?? null) ? $signal['hard_blockers'] : [];
                if ($blockers !== []) {
                    $current = is_array($result[$domain]['hard_blockers'] ?? null)
                        ? $result[$domain]['hard_blockers']
                        : [];
                    $result[$domain]['hard_blockers'] = array_values(array_unique([
                        ...$current,
                        ...array_values(array_filter($blockers, 'is_string')),
                    ]));
                }
            }
        }

        return $result;
    }

    private function hasBlockingUnits(Builder $base): bool
    {
        return (clone $base)
            ->where(static function (Builder $query): void {
                $query->whereIn('status', [
                    DocumentProcessingUnitStatus::Pending->value,
                    DocumentProcessingUnitStatus::Running->value,
                ])->orWhere(static fn (Builder $failed): Builder => $failed
                    ->where('status', DocumentProcessingUnitStatus::Failed->value)
                    ->where('attempt_count', '<', ProcessDocumentUnit::MAX_ATTEMPTS));
            })
            ->whereDoesntHave('page', static function (Builder $query): void {
                $query->where('status', 'excluded');
            })
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function qualityFlags(
        EstimateGenerationDocument $document,
        int $excludedCount,
        DocumentProcessingOutcome $outcome,
    ): array {
        $flags = array_values(array_filter(
            array_map('strval', is_array($document->quality_flags) ? $document->quality_flags : []),
            static fn (string $flag): bool => ! in_array($flag, [
                'document_unit_attempts_exhausted',
                'pages_excluded_from_estimation',
            ], true),
        ));

        if ($excludedCount > 0) {
            $flags[] = 'pages_excluded_from_estimation';
        }
        if ($outcome->type === 'system_failure') {
            $flags[] = 'document_processing_system_failed';
        }
        if ($outcome->type === 'temporary_failure') {
            $flags[] = 'document_processing_temporarily_unavailable';
        }

        return array_values(array_unique($flags));
    }

    /** @return Builder<EstimateGenerationDocument> */
    private function documentQuery(): Builder
    {
        $model = new EstimateGenerationDocument;
        $model->setConnection($this->database->getName());

        return $model->newQuery();
    }

    /** @return Builder<EstimateGenerationProcessingUnit> */
    private function unitQuery(): Builder
    {
        $model = new EstimateGenerationProcessingUnit;
        $model->setConnection($this->database->getName());

        return $model->newQuery();
    }
}
