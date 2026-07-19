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
use Throwable;

final readonly class EloquentDocumentUnitAggregateReconciler implements DocumentUnitAggregateReconciler
{
    public function __construct(
        private ReconcileEstimateGenerationDocuments $sessions,
        private Connection $database,
        private EloquentSessionBuildingModelBridge $buildingModels,
        private DocumentVisualAttributeSummaryBuilder $visualAttributes = new DocumentVisualAttributeSummaryBuilder,
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

            if (! (clone $base)->exists() || (clone $base)->where('status', '<>', DocumentProcessingUnitStatus::Completed->value)->exists()) {
                return null;
            }

            if ((string) $document->units_finalized_source_version !== $sourceVersion) {
                $currentUnitIds = (clone $base)->pluck('id');
                $document->facts()->delete();
                $document->drawingElements()->delete();
                $document->quantityTakeoffs()->delete();
                $document->scopeInferences()->delete();
                $document->pages()->whereNotIn('processing_unit_id', $currentUnitIds)->delete();
                $pages = $document->pages()
                    ->whereIn('processing_unit_id', $currentUnitIds)
                    ->where('source_version', $sourceVersion)
                    ->orderBy('page_number')
                    ->get();
                $qualitySignals = $this->qualitySignals($pages->pluck('normalized_payload')->all());
                $visualAttributes = $this->visualAttributes->summarize($pages->pluck('normalized_payload')->all());
                $document->forceFill([
                    'extracted_text' => $pages->pluck('text')->filter()->implode("\n\n"),
                    'structured_payload' => [
                        'source_version' => $sourceVersion,
                        'pages' => $pages->map(fn ($page): array => [
                            'page_number' => $page->page_number,
                            'text' => $page->text,
                            'confidence' => $page->confidence,
                            'normalized_payload' => $page->normalized_payload,
                        ])->all(),
                    ],
                    'page_count' => $pages->count(),
                    'processed_page_count' => $pages->count(),
                    'units_finalized_source_version' => $sourceVersion,
                    'status' => 'ready',
                    'processing_stage' => 'completed',
                    'progress_percent' => 100,
                    'quality_score' => 1.0,
                    'quality_level' => 'good',
                    'facts_summary' => [
                        ...($qualitySignals === [] ? [] : ['quality_signals' => $qualitySignals]),
                        ...$visualAttributes,
                    ],
                    'ocr_finished_at' => now(),
                ]);
            }

            $token = (string) Str::uuid();
            $document->forceFill([
                'units_reconcile_claim_token' => $token,
                'units_reconcile_lease_expires_at' => now()->addMinutes(5),
            ])->save();

            return [$document->session, $token];
        }, 3);

        if ($claim === null) {
            return;
        }

        [$session, $token] = $claim;

        try {
            $this->buildingModels->rebuild((int) $session->getKey());
            $this->sessions->reconcile($session);
            $this->documentQuery()
                ->whereKey($documentId)
                ->where('source_version', $sourceVersion)
                ->where('units_reconcile_claim_token', $token)
                ->update([
                    'units_reconciled_source_version' => $sourceVersion,
                    'units_reconcile_claim_token' => null,
                    'units_reconcile_lease_expires_at' => null,
                    'updated_at' => now(),
                ]);
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
