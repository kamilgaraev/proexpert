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
                $dimensions = $this->pinnedDimensions($defect, $history);
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
        if (! is_array($history->reporting_evidence_refs)) {
            throw new LogicException('quality_defect_transition_evidence_invalid');
        }
        $evidenceRefs = [];
        foreach ($history->reporting_evidence_refs as $evidence) {
            if (! is_array($evidence)
                || ! is_int($evidence['id'] ?? null)
                || ! in_array($evidence['type'] ?? null, ['quality_defect_photo', 'status_comment'], true)) {
                throw new LogicException('quality_defect_transition_evidence_invalid');
            }
            if (($evidence['coverage'] ?? null) === 'unknown'
                && ($evidence['reason'] ?? null) === 'legacy_storage_identity_unverified') {
                $evidenceRefs[] = $evidence;

                continue;
            }
            if ($evidence['type'] === 'quality_defect_photo') {
                $required = [
                    'caption', 'content_hash', 'created_at', 'metadata', 'mime_type', 'photo_type',
                    'size_bytes', 'storage_etag', 'storage_key', 'storage_sha256', 'uploaded_by',
                    'storage_identity_verified',
                ];
                if (array_filter($required, static fn (string $key): bool => ! array_key_exists($key, $evidence)) !== []
                    || ! is_string($evidence['content_hash'])
                    || ! is_string($evidence['storage_key'])
                    || preg_match('/^[a-f0-9]{64}$/D', (string) $evidence['storage_sha256']) !== 1
                    || $evidence['storage_identity_verified'] !== true
                    || ! hash_equals($evidence['content_hash'], hash('sha256', CanonicalJson::encode([
                        'caption' => $evidence['caption'],
                        'created_at' => $evidence['created_at'],
                        'metadata' => $evidence['metadata'],
                        'mime_type' => $evidence['mime_type'],
                        'size_bytes' => $evidence['size_bytes'],
                        'storage_etag' => $evidence['storage_etag'],
                        'storage_key' => $evidence['storage_key'],
                        'storage_sha256' => $evidence['storage_sha256'],
                        'storage_identity_verified' => true,
                        'type' => $evidence['photo_type'],
                        'uploaded_by' => $evidence['uploaded_by'],
                    ])))) {
                    throw new LogicException('quality_defect_transition_evidence_invalid');
                }
            }
            $evidenceRefs[] = $evidence;
        }
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
            'contractor_id' => $dimensions['contractor_id'] === null ? null : (int) $dimensions['contractor_id'],
            'due_date' => $dimensions['due_date'],
            'evidence_refs' => $evidenceRefs,
            'event_version' => $version,
            'from_status' => $history->from_status?->value,
            'occurred_at' => $history->changed_at?->toAtomString(),
            'organization_id' => (int) $history->organization_id,
            'project_id' => (int) $dimensions['project_id'],
            'quality_defect_id' => (int) $history->quality_defect_id,
            'schedule_task_id' => $dimensions['schedule_task_id'] === null ? null : (int) $dimensions['schedule_task_id'],
            'severity' => (string) $dimensions['severity'],
            'status_history_id' => (int) $history->id,
            'to_status' => $history->to_status->value,
        ];
    }

    private function pinnedDimensions(QualityDefect $defect, QualityDefectStatusHistory $history): array
    {
        $dimensions = $history->reporting_dimensions;
        $required = ['contractor_id', 'due_date', 'project_id', 'schedule_task_id', 'severity'];
        $missing = array_filter(
            $required,
            static fn (string $key): bool => ! is_array($dimensions) || ! array_key_exists($key, $dimensions),
        );
        if (! is_array($dimensions)
            || $missing !== []
            || (int) $history->quality_defect_id !== (int) $defect->id
            || (int) $history->organization_id !== (int) $defect->organization_id
            || ! is_int($dimensions['project_id'])
            || ! is_string($dimensions['severity'])
            || ($dimensions['contractor_id'] !== null && ! is_int($dimensions['contractor_id']))
            || ($dimensions['schedule_task_id'] !== null && ! is_int($dimensions['schedule_task_id']))
            || ($dimensions['due_date'] !== null && ! is_string($dimensions['due_date']))) {
            throw new LogicException('quality_defect_transition_dimensions_invalid');
        }

        return $dimensions;
    }
}
