<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use Illuminate\Database\Connection;

final readonly class DocumentCostJournalReader
{
    public function __construct(private Connection $database) {}

    /** @param iterable<EstimateGenerationDocument> $documents */
    public function attach(iterable $documents): void
    {
        $models = is_array($documents) ? $documents : iterator_to_array($documents, false);
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (EstimateGenerationDocument $document): int => (int) $document->getKey(),
            $models,
        ))));
        if ($ids === []) {
            return;
        }

        $totals = $this->database->table('estimate_generation_documents as documents')
            ->leftJoin('estimate_generation_vision_physical_attempts as attempts', function ($join): void {
                $join->on('attempts.document_id', '=', 'documents.id')
                    ->on('attempts.organization_id', '=', 'documents.organization_id')
                    ->on('attempts.project_id', '=', 'documents.project_id')
                    ->on('attempts.session_id', '=', 'documents.session_id')
                    ->whereRaw("attempts.processing_lineage_id::text = documents.meta->>'processing_attempt_id'");
            })
            ->leftJoin('estimate_generation_ai_usage as usage', 'usage.attempt_id', '=', 'attempts.attempt_id')
            ->whereIn('documents.id', $ids)
            ->groupBy('documents.id')
            ->selectRaw(<<<'SQL'
documents.id,
COALESCE(SUM(usage.cost_amount) FILTER (
    WHERE usage.pricing_status = 'available' AND usage.currency = 'RUB'
), 0)::numeric(20,8) AS spent_rub
SQL)
            ->pluck('spent_rub', 'id');

        foreach ($models as $document) {
            $document->setAttribute(
                'processing_cost_spent_rub',
                (string) ($totals[(int) $document->getKey()] ?? '0.00000000'),
            );
        }
    }
}
