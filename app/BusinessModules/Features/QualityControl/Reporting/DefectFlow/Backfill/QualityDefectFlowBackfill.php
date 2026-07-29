<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Backfill;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectStatusHistory;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectTransitionRecorder;
use Illuminate\Support\Collection;

final readonly class QualityDefectFlowBackfill
{
    public function __construct(private QualityDefectTransitionRecorder $recorder) {}

    public function sourceCode(): string
    {
        return 'quality_defect_status_history';
    }

    public function sourceSchemaVersion(): string
    {
        return 'quality_defect_flow_v1';
    }

    public function nextBatch(int $organizationId, int $afterHistoryId, int $limit = 500): Collection
    {
        return QualityDefectStatusHistory::query()
            ->with('defect.photos:id,quality_defect_id,type')
            ->where('organization_id', $organizationId)
            ->where('id', '>', $afterHistoryId)
            ->orderBy('id')
            ->limit(min(max($limit, 1), 500))
            ->get();
    }

    public function apply(Collection $batch): array
    {
        $inputHashes = [];
        $outputHashes = [];
        $gaps = 0;
        foreach ($batch as $history) {
            if (! $history instanceof QualityDefectStatusHistory || $history->defect === null) {
                $gaps++;

                continue;
            }
            $inputHashes[] = hash('sha256', CanonicalJson::encode([
                'changed_at' => $history->changed_at?->toAtomString(),
                'changed_by' => $history->changed_by,
                'comment_hash' => hash('sha256', trim((string) $history->comment)),
                'contractor_id' => $history->defect->contractor_id,
                'defect_id' => $history->quality_defect_id,
                'due_date' => $history->defect->due_date?->toDateString(),
                'from_status' => $history->from_status?->value,
                'history_id' => $history->id,
                'photo_refs' => $history->defect->photos
                    ->map(static fn ($photo): array => ['id' => (int) $photo->id, 'type' => (string) $photo->type])
                    ->sortBy('id')
                    ->values()
                    ->all(),
                'project_id' => $history->defect->project_id,
                'severity' => $history->defect->severity->value,
                'to_status' => $history->to_status->value,
            ]));
            $outputHashes[] = $this->recorder->record($history->defect, $history)->event_hash;
        }

        return [
            'source_count' => $batch->count(),
            'projected_count' => count($outputHashes),
            'gap_count' => $gaps,
            'unknown_count' => 0,
            'input_hash' => hash('sha256', implode('', $inputHashes)),
            'output_hash' => hash('sha256', implode('', $outputHashes)),
        ];
    }
}
