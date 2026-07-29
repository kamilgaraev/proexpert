<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectStatusHistory;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectTransitionEvent;
use Illuminate\Support\Facades\DB;

final readonly class QualityDefectTransitionRecorder
{
    public function record(QualityDefect $defect, QualityDefectStatusHistory $history): QualityDefectTransitionEvent
    {
        return DB::transaction(function () use ($defect, $history): QualityDefectTransitionEvent {
            $existing = QualityDefectTransitionEvent::query()
                ->where('organization_id', $defect->organization_id)
                ->where('status_history_id', $history->id)
                ->first();
            if ($existing instanceof QualityDefectTransitionEvent) {
                return $existing;
            }

            $last = QualityDefectTransitionEvent::query()
                ->where('organization_id', $defect->organization_id)
                ->where('quality_defect_id', $defect->id)
                ->lockForUpdate()
                ->orderByDesc('event_version')
                ->first();
            $version = ($last?->event_version ?? 0) + 1;
            $evidenceRefs = $defect->photos()
                ->where('organization_id', $defect->organization_id)
                ->orderBy('id')
                ->get(['id', 'type'])
                ->map(static fn ($photo): array => [
                    'id' => (int) $photo->id,
                    'type' => (string) $photo->type,
                ])
                ->all();
            $payload = [
                'actor_user_id' => $history->changed_by === null ? null : (int) $history->changed_by,
                'contractor_id' => $defect->contractor_id === null ? null : (int) $defect->contractor_id,
                'evidence_refs' => $evidenceRefs,
                'event_version' => $version,
                'from_status' => $history->from_status?->value,
                'occurred_at' => $history->changed_at?->toAtomString(),
                'organization_id' => (int) $defect->organization_id,
                'project_id' => (int) $defect->project_id,
                'quality_defect_id' => (int) $defect->id,
                'schedule_task_id' => $defect->schedule_task_id === null ? null : (int) $defect->schedule_task_id,
                'severity' => $defect->severity->value,
                'status_history_id' => (int) $history->id,
                'to_status' => $history->to_status->value,
            ];

            return QualityDefectTransitionEvent::query()->create($payload + [
                'event_hash' => hash('sha256', CanonicalJson::encode($payload)),
                'recorded_at' => now(),
            ]);
        });
    }
}
