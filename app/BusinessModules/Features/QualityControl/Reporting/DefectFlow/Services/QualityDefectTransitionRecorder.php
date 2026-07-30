<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\Reporting\Support\ReportSnapshotFirstWriter;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectStatusHistory;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectTransitionEvent;
use LogicException;

final readonly class QualityDefectTransitionRecorder
{
    public function record(QualityDefect $defect, QualityDefectStatusHistory $history): QualityDefectTransitionEvent
    {
        return ReportSnapshotFirstWriter::run(
            'quality_defect_transition:'.$defect->organization_id.':'.$defect->id,
            function () use ($defect, $history): QualityDefectTransitionEvent {
                $evidenceRefs = $this->evidenceRefs($history);
                $dimensions = is_array($history->reporting_dimensions) ? $history->reporting_dimensions : [];
                $existing = QualityDefectTransitionEvent::query()
                    ->where('organization_id', $defect->organization_id)
                    ->where('status_history_id', $history->id)
                    ->first();
                if ($existing instanceof QualityDefectTransitionEvent) {
                    $expected = $this->payload(
                        $defect,
                        $history,
                        $dimensions,
                        $evidenceRefs,
                        (int) $existing->event_version,
                    );
                    if (! hash_equals((string) $existing->event_hash, hash('sha256', CanonicalJson::encode($expected)))) {
                        throw new LogicException('quality_defect_transition_conflict');
                    }

                    return $existing;
                }

                $last = QualityDefectTransitionEvent::query()
                    ->where('organization_id', $defect->organization_id)
                    ->where('quality_defect_id', $defect->id)
                    ->lockForUpdate()
                    ->orderByDesc('event_version')
                    ->first();
                $version = ($last?->event_version ?? 0) + 1;
                $payload = $this->payload($defect, $history, $dimensions, $evidenceRefs, $version);

                return QualityDefectTransitionEvent::query()->create($payload + [
                    'event_hash' => hash('sha256', CanonicalJson::encode($payload)),
                    'recorded_at' => now(),
                ]);
            },
        );
    }

    private function evidenceRefs(QualityDefectStatusHistory $history): array
    {
        $evidenceRefs = is_array($history->reporting_evidence_refs)
            ? array_map(
                static fn (array $evidence): array => [
                    ...$evidence,
                    'type' => ($evidence['type'] ?? null) === 'status_comment'
                        ? 'status_comment'
                        : 'quality_defect_photo',
                ],
                $history->reporting_evidence_refs,
            )
            : [];
        if (trim((string) $history->comment) !== '') {
            $evidenceRefs[] = [
                'hash' => hash('sha256', trim((string) $history->comment)),
                'id' => (int) $history->id,
                'type' => 'status_comment',
            ];
        }

        return $evidenceRefs;
    }

    private function payload(
        QualityDefect $defect,
        QualityDefectStatusHistory $history,
        array $dimensions,
        array $evidenceRefs,
        int $version,
    ): array {
        return [
            'actor_user_id' => $history->changed_by === null ? null : (int) $history->changed_by,
            'contractor_id' => isset($dimensions['contractor_id']) ? (int) $dimensions['contractor_id'] : ($defect->contractor_id === null ? null : (int) $defect->contractor_id),
            'due_date' => $dimensions['due_date'] ?? $defect->due_date?->toDateString(),
            'evidence_refs' => $evidenceRefs,
            'event_version' => $version,
            'from_status' => $history->from_status?->value,
            'occurred_at' => $history->changed_at?->toAtomString(),
            'organization_id' => (int) $defect->organization_id,
            'project_id' => (int) ($dimensions['project_id'] ?? $defect->project_id),
            'quality_defect_id' => (int) $defect->id,
            'schedule_task_id' => isset($dimensions['schedule_task_id']) ? (int) $dimensions['schedule_task_id'] : ($defect->schedule_task_id === null ? null : (int) $defect->schedule_task_id),
            'severity' => (string) ($dimensions['severity'] ?? $defect->severity->value),
            'status_history_id' => (int) $history->id,
            'to_status' => $history->to_status->value,
        ];
    }
}
