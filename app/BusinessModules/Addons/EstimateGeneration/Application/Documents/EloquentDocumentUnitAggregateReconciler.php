<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use Illuminate\Support\Facades\DB;

final readonly class EloquentDocumentUnitAggregateReconciler implements DocumentUnitAggregateReconciler
{
    public function __construct(
        private ReconcileEstimateGenerationDocuments $sessions,
    ) {}

    public function reconcile(int $documentId, string $sourceVersion): void
    {
        $session = DB::transaction(function () use ($documentId, $sourceVersion) {
            $document = EstimateGenerationDocument::query()->with(['pages', 'session'])->lockForUpdate()->find($documentId);

            if (
                ! $document instanceof EstimateGenerationDocument
                || (string) $document->source_version !== $sourceVersion
                || $document->status === 'ignored'
                || $document->status === 'ready'
            ) {
                return null;
            }

            $base = EstimateGenerationProcessingUnit::query()
                ->where('organization_id', $document->organization_id)
                ->where('project_id', $document->project_id)
                ->where('session_id', $document->session_id)
                ->where('document_id', $document->id)
                ->where('source_version', $sourceVersion);

            if (! (clone $base)->exists() || (clone $base)->where('status', '<>', DocumentProcessingUnitStatus::Completed->value)->exists()) {
                return null;
            }

            $pages = $document->pages->sortBy('page_number')->values();
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
                'status' => 'ready',
                'processing_stage' => 'completed',
                'progress_percent' => 100,
                'quality_score' => 1.0,
                'quality_level' => 'good',
                'facts_summary' => [],
                'ocr_finished_at' => now(),
            ])->save();

            return $document->session;
        }, 3);

        if ($session !== null) {
            $this->sessions->reconcile($session);
        }
    }
}
