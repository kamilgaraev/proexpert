<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Access;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Services\Storage\FileService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ProductionReportScopedResourceAuthorizers
{
    public function handlers(): array
    {
        return [
            $this->direct('quality_defect', 'quality_defects'),
            $this->scheduleTask('schedule_task'),
            $this->scheduleTask('task'),
            $this->contractor(),
            $this->qualityPhoto(),
            $this->qualityStatusComment(),
            $this->direct('safety_incident', 'safety_incidents'),
            $this->direct('safety_violation', 'safety_violations'),
            $this->direct('safety_corrective_action', 'safety_corrective_actions'),
            $this->direct('safety_site', 'safety_sites'),
            $this->workforceAssignment(),
            $this->workforceEmployee(),
            $this->workforceSnapshotRelation(),
            $this->workforceSnapshotEvidence(),
            $this->safetyEvidence('incident_closure', 'safety_incidents', 'incident', 'closed'),
            $this->safetyEvidence('incident_cancellation', 'safety_incidents', 'incident', 'cancelled'),
            $this->violationResolution(),
            $this->safetyEvidence(
                'corrective_action_verification',
                'safety_corrective_actions',
                'corrective_action',
                'verified',
            ),
            $this->workforceEvidence('employee_requirement', 'safety_employee_requirements'),
            $this->workforceEvidence('training', 'safety_training_records'),
            $this->workforceEvidence('medical_exam', 'safety_medical_exams'),
            $this->workforceEvidence('ppe', 'safety_ppe_issues'),
            $this->briefingEvidence(),
        ];
    }

    private function direct(string $kind, string $table): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            $kind,
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table($table)
                ->where('id', $id)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->exists(),
        );
    }

    private function scheduleTask(string $kind): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            $kind,
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('schedule_tasks as task')
                ->join('project_schedules as schedule', 'schedule.id', '=', 'task.schedule_id')
                ->where('task.id', $id)
                ->where('task.organization_id', $organizationId)
                ->where('schedule.organization_id', $organizationId)
                ->where('schedule.project_id', $projectId)
                ->exists(),
        );
    }

    private function contractor(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'contractor',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('contractors')
                ->where('id', $id)
                ->where('organization_id', $organizationId)
                ->exists()
                && (
                    DB::table('quality_defects')
                        ->where('organization_id', $organizationId)
                        ->where('project_id', $projectId)
                        ->where('contractor_id', $id)
                        ->exists()
                    || self::safetySubjectUsesContractor(
                        'safety_incidents',
                        $organizationId,
                        $projectId,
                        $id,
                    )
                    || self::safetySubjectUsesContractor(
                        'safety_violations',
                        $organizationId,
                        $projectId,
                        $id,
                    )
                    || self::safetySubjectUsesContractor(
                        'safety_corrective_actions',
                        $organizationId,
                        $projectId,
                        $id,
                    )
                ),
        );
    }

    private function qualityPhoto(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'quality_defect_photo',
            static fn (int $organizationId, int $projectId, int $id): bool => self::qualityPhotoIsCurrent(
                $organizationId,
                $projectId,
                $id,
            ),
        );
    }

    private static function qualityPhotoIsCurrent(int $organizationId, int $projectId, int $id): bool
    {
        $photo = DB::table('quality_defect_photos as photo')
            ->join('quality_defects as defect', 'defect.id', '=', 'photo.quality_defect_id')
            ->where('photo.id', $id)
            ->where('photo.organization_id', $organizationId)
            ->where('defect.organization_id', $organizationId)
            ->where('defect.project_id', $projectId)
            ->first([
                'photo.caption',
                'photo.created_at',
                'photo.metadata',
                'photo.mime_type',
                'photo.quality_defect_id',
                'photo.size_bytes',
                'photo.storage_etag',
                'photo.storage_sha256',
                'photo.storage_identity_verified',
                'photo.type',
                'photo.uploaded_by',
                'photo.url',
            ]);
        if ($photo === null) {
            return false;
        }
        $contentHash = hash('sha256', CanonicalJson::encode([
            'caption' => $photo->caption,
            'created_at' => CarbonImmutable::parse((string) $photo->created_at)->toAtomString(),
            'metadata' => $photo->metadata === null ? null : json_decode((string) $photo->metadata, true, 512, JSON_THROW_ON_ERROR),
            'mime_type' => (string) $photo->mime_type,
            'size_bytes' => (int) $photo->size_bytes,
            'storage_etag' => (string) $photo->storage_etag,
            'storage_key' => (string) $photo->url,
            'storage_sha256' => (string) $photo->storage_sha256,
            'storage_identity_verified' => (bool) $photo->storage_identity_verified,
            'type' => (string) $photo->type,
            'uploaded_by' => $photo->uploaded_by === null ? null : (int) $photo->uploaded_by,
        ]));
        try {
            $stored = app(FileService::class)->headCurrent((string) $photo->url);
        } catch (\Throwable) {
            return false;
        }
        if (! (bool) $photo->storage_identity_verified
            || ! hash_equals((string) $photo->storage_sha256, $stored->sha256)
            || ! hash_equals((string) $photo->storage_etag, $stored->etag)
            || (int) $photo->size_bytes !== $stored->sizeBytes
            || ! hash_equals((string) $photo->mime_type, $stored->mime)) {
            return false;
        }
        $events = DB::table('quality_defect_transition_events')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('quality_defect_id', $photo->quality_defect_id)
            ->get(['evidence_refs']);
        foreach ($events as $event) {
            $references = json_decode((string) $event->evidence_refs, true, 512, JSON_THROW_ON_ERROR);
            foreach (is_array($references) ? $references : [] as $reference) {
                if (($reference['type'] ?? null) === 'quality_defect_photo'
                    && (int) ($reference['id'] ?? 0) === $id
                    && is_string($reference['content_hash'] ?? null)
                    && hash_equals($reference['content_hash'], $contentHash)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function qualityStatusComment(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'status_comment',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('quality_defect_status_history as history')
                ->join('quality_defects as defect', 'defect.id', '=', 'history.quality_defect_id')
                ->where('history.id', $id)
                ->whereNotNull('history.comment')
                ->where('defect.organization_id', $organizationId)
                ->where('defect.project_id', $projectId)
                ->exists(),
        );
    }

    private function workforceEmployee(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'workforce_employee',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('workforce_employees as employee')
                ->join('safety_site_workforce_assignments as mapping', 'mapping.employee_id', '=', 'employee.id')
                ->join('workforce_employee_assignments as assignment', 'assignment.id', '=', 'mapping.workforce_assignment_id')
                ->join('safety_sites as site', 'site.id', '=', 'mapping.safety_site_id')
                ->where('employee.id', $id)
                ->where('employee.organization_id', $organizationId)
                ->where('mapping.organization_id', $organizationId)
                ->where('mapping.project_id', $projectId)
                ->where('assignment.organization_id', $organizationId)
                ->where('assignment.project_id', $projectId)
                ->where('assignment.employee_id', $id)
                ->where('assignment.status', 'active')
                ->whereNull('assignment.deleted_at')
                ->whereDate('mapping.valid_from', '<=', now()->toDateString())
                ->where(static fn ($query) => $query->whereNull('mapping.valid_to')->orWhereDate('mapping.valid_to', '>=', now()->toDateString()))
                ->where('site.organization_id', $organizationId)
                ->where('site.project_id', $projectId)
                ->where('site.is_active', true)
                ->exists(),
        );
    }

    private function workforceAssignment(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'workforce_assignment',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('workforce_employee_assignments as assignment')
                ->join(
                    'safety_site_workforce_assignments as mapping',
                    'mapping.workforce_assignment_id',
                    '=',
                    'assignment.id',
                )
                ->join('safety_sites as site', 'site.id', '=', 'mapping.safety_site_id')
                ->where('assignment.id', $id)
                ->where('assignment.organization_id', $organizationId)
                ->where('mapping.organization_id', $organizationId)
                ->where('mapping.project_id', $projectId)
                ->where('assignment.project_id', $projectId)
                ->where('assignment.status', 'active')
                ->whereNull('assignment.deleted_at')
                ->whereDate('mapping.valid_from', '<=', now()->toDateString())
                ->where(static fn ($query) => $query->whereNull('mapping.valid_to')->orWhereDate('mapping.valid_to', '>=', now()->toDateString()))
                ->where('site.organization_id', $organizationId)
                ->where('site.project_id', $projectId)
                ->where('site.is_active', true)
                ->exists(),
        );
    }

    private function workforceEvidence(string $kind, string $table): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            $kind,
            static function (int $organizationId, int $projectId, int $id) use ($kind, $table): bool {
                $query = DB::table($table.' as evidence')
                    ->join('safety_site_workforce_assignments as mapping', 'mapping.employee_id', '=', 'evidence.employee_id')
                    ->join('workforce_employee_assignments as assignment', 'assignment.id', '=', 'mapping.workforce_assignment_id')
                    ->join('safety_sites as site', 'site.id', '=', 'mapping.safety_site_id')
                    ->where('evidence.id', $id)
                    ->where('evidence.organization_id', $organizationId)
                    ->where('mapping.organization_id', $organizationId)
                    ->where('mapping.project_id', $projectId)
                    ->where('assignment.organization_id', $organizationId)
                    ->where('assignment.project_id', $projectId)
                    ->whereColumn('assignment.employee_id', 'evidence.employee_id')
                    ->where('assignment.status', 'active')
                    ->whereNull('assignment.deleted_at')
                    ->whereDate('mapping.valid_from', '<=', now()->toDateString())
                    ->where(static fn ($query) => $query->whereNull('mapping.valid_to')->orWhereDate('mapping.valid_to', '>=', now()->toDateString()))
                    ->where('site.organization_id', $organizationId)
                    ->where('site.project_id', $projectId)
                    ->where('site.is_active', true);
                self::applyWorkforceEvidenceValidity($query, $kind);

                return $query->exists();
            },
        );
    }

    private function workforceSnapshotRelation(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'workforce_assignment_site',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('safety_site_workforce_assignments as mapping')
                ->join('workforce_employee_assignments as assignment', 'assignment.id', '=', 'mapping.workforce_assignment_id')
                ->join('safety_sites as site', 'site.id', '=', 'mapping.safety_site_id')
                ->where('mapping.id', $id)
                ->where('mapping.organization_id', $organizationId)
                ->where('mapping.project_id', $projectId)
                ->whereDate('mapping.valid_from', '<=', now()->toDateString())
                ->where(static fn ($query) => $query->whereNull('mapping.valid_to')->orWhereDate('mapping.valid_to', '>=', now()->toDateString()))
                ->where('assignment.organization_id', $organizationId)
                ->where('assignment.project_id', $projectId)
                ->where('assignment.status', 'active')
                ->whereNull('assignment.deleted_at')
                ->where('site.organization_id', $organizationId)
                ->where('site.project_id', $projectId)
                ->where('site.is_active', true)
                ->exists(),
        );
    }

    private function workforceSnapshotEvidence(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'workforce_snapshot_evidence',
            static fn (int $organizationId, int $projectId, int $id): bool => self::workforceSnapshotEvidenceExists(
                $organizationId,
                $projectId,
                $id,
            ),
        );
    }

    private static function workforceSnapshotEvidenceExists(
        int $organizationId,
        int $projectId,
        int $rowId,
    ): bool {
        $row = DB::table('safety_admission_rows')
            ->where('id', $rowId)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->first([
                'employee_id',
                'evidence_id',
                'evidence_hash',
                'evidence_identity',
                'evidence_type',
                'evidence_version_id',
                'requirement_code',
                'status',
                'valid_until',
                'safety_site_id',
                'site_assignment_id',
                'workforce_assignment_id',
            ]);
        if ($row === null) {
            return false;
        }
        $identity = $row->evidence_identity === null
            ? []
            : json_decode((string) $row->evidence_identity, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($identity)
            || (int) ($identity['ownership_version_id'] ?? 0) < 1
            || ! is_string($identity['ownership_version_hash'] ?? null)
            || ! DB::table('safety_assignment_ownership_versions')
                ->where('id', (int) $identity['ownership_version_id'])
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('employee_id', $row->employee_id)
                ->where('safety_site_id', $row->safety_site_id)
                ->where('site_assignment_id', $row->site_assignment_id)
                ->where('workforce_assignment_id', $row->workforce_assignment_id)
                ->where('history_complete', true)
                ->where('tombstone', false)
                ->where('source_hash', $identity['ownership_version_hash'])
                ->exists()) {
            return false;
        }
        if ($row->evidence_id === null) {
            return $row->evidence_type === null
                && $row->evidence_version_id === null
                && $row->evidence_hash === null;
        }
        if (! is_array($identity)
            || (int) ($identity['version_id'] ?? 0) !== (int) $row->evidence_version_id
            || ! hash_equals((string) ($identity['version_hash'] ?? ''), (string) $row->evidence_hash)
            || (int) ($identity['evidence_id'] ?? 0) !== (int) $row->evidence_id
            || (int) ($identity['employee_id'] ?? 0) !== (int) $row->employee_id
            || (int) ($identity['project_id'] ?? 0) !== $projectId
            || (int) ($identity['safety_site_id'] ?? 0) !== (int) $row->safety_site_id
            || (int) ($identity['site_assignment_id'] ?? 0) !== (int) $row->site_assignment_id
            || (int) ($identity['workforce_assignment_id'] ?? 0) !== (int) $row->workforce_assignment_id
            || ($identity['evidence_type'] ?? null) !== $row->evidence_type) {
            return false;
        }
        $version = DB::table('safety_evidence_versions')
            ->where('id', $row->evidence_version_id)
            ->where('organization_id', $organizationId)
            ->where('evidence_type', $row->evidence_type)
            ->where('evidence_id', $row->evidence_id)
            ->where('employee_id', $row->employee_id)
            ->where('history_complete', true)
            ->where('content_hash', $row->evidence_hash)
            ->first(['content']);
        if ($version === null) {
            return false;
        }
        $content = json_decode((string) $version->content, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($content)) {
            return false;
        }
        if ($row->evidence_type === 'briefing'
            && ((int) ($identity['resource_id'] ?? 0) < 1
                || ($identity['resource_type'] ?? null) !== 'safety_briefing'
                || (int) ($content['participant']['briefing_id'] ?? 0) !== (int) $identity['resource_id'])) {
            return false;
        }

        return ($content['_deleted'] ?? false) !== true;
    }

    private static function applyWorkforceEvidenceValidity(Builder $query, string $type, ?object $row = null): void
    {
        $query->whereNull('evidence.deleted_at');
        match ($type) {
            'employee_requirement' => $query
                ->when($row !== null, static fn ($builder) => $builder
                    ->where('evidence.requirement_code', $row->requirement_code))
                ->whereIn('evidence.status', $row?->status === 'waived'
                    ? ['waived'] : ['fulfilled', 'valid', 'approved', 'completed'])
                ->where(static fn ($builder) => $builder
                    ->whereNull('evidence.valid_until')
                    ->orWhereDate('evidence.valid_until', '>=', now()->toDateString())),
            'training' => $query
                ->when($row !== null, static fn ($builder) => $builder
                    ->where('evidence.program_code', $row->requirement_code))
                ->where('evidence.result', 'passed')
                ->where(static fn ($builder) => $builder
                    ->whereNull('evidence.valid_until')
                    ->orWhereDate('evidence.valid_until', '>=', now()->toDateString())),
            'medical_exam' => $query
                ->when($row !== null, static fn ($builder) => $builder
                    ->where('evidence.exam_type', $row->requirement_code))
                ->whereIn('evidence.result', ['fit', 'fit_with_restrictions'])
                ->where(static fn ($builder) => $builder
                    ->whereNull('evidence.valid_until')
                    ->orWhereDate('evidence.valid_until', '>=', now()->toDateString())),
            'ppe' => $query
                ->when($row !== null, static fn ($builder) => $builder
                    ->where('evidence.ppe_code', $row->requirement_code))
                ->where('evidence.status', 'issued')
                ->where(static fn ($builder) => $builder
                    ->whereNull('evidence.valid_until')
                    ->orWhereDate('evidence.valid_until', '>=', now()->toDateString())),
            default => null,
        };
    }

    private function briefingEvidence(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'briefing',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('safety_briefing_participants as participant')
                ->join('safety_briefings as briefing', 'briefing.id', '=', 'participant.briefing_id')
                ->where('participant.id', $id)
                ->where('briefing.organization_id', $organizationId)
                ->where('briefing.project_id', $projectId)
                ->whereNotNull('participant.signed_at')
                ->whereNull('briefing.deleted_at')
                ->whereNull('briefing.cancelled_at')
                ->exists(),
        );
    }

    private function violationResolution(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'violation_resolution',
            static fn (int $organizationId, int $projectId, int $id): bool => self::safetyEvidenceIsCurrent(
                $organizationId,
                $projectId,
                $id,
                'safety_violations',
                'violation',
                'resolved',
                'violation_resolution',
            ),
        );
    }

    private function safetyEvidence(
        string $kind,
        string $table,
        string $subjectType,
        string $status,
    ): QueryReportScopedResourceAuthorizer {
        return new QueryReportScopedResourceAuthorizer(
            $kind,
            static fn (int $organizationId, int $projectId, int $id): bool => self::safetyEvidenceIsCurrent(
                $organizationId,
                $projectId,
                $id,
                $table,
                $subjectType,
                $status,
                $kind,
            ),
        );
    }

    private static function safetyEvidenceIsCurrent(
        int $organizationId,
        int $projectId,
        int $id,
        string $table,
        string $subjectType,
        string $status,
        string $evidenceType,
    ): bool {
        $subject = DB::table($table)
            ->where('id', $id)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('status', $status)
            ->first();
        $event = DB::table('safety_transition_events')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $id)
            ->where('to_status', $status)
            ->where('evidence_type', $evidenceType)
            ->where('evidence_id', (string) $id)
            ->orderByDesc('event_version')
            ->first();
        if ($subject === null || $event === null) {
            return false;
        }
        $evidence = match ($evidenceType) {
            'incident_closure' => $subject->closed_at !== null && trim((string) $subject->root_cause) !== ''
                ? [
                    'closed_at' => CarbonImmutable::parse((string) $subject->closed_at)->format(DATE_ATOM),
                    'corrective_actions' => trim((string) $subject->corrective_actions),
                    'root_cause' => trim((string) $subject->root_cause),
                ] : null,
            'incident_cancellation' => $subject->cancelled_at !== null
                && trim((string) $subject->cancellation_reason) !== ''
                ? [
                    'cancelled_at' => CarbonImmutable::parse((string) $subject->cancelled_at)->format(DATE_ATOM),
                    'cancellation_reason' => trim((string) $subject->cancellation_reason),
                ] : null,
            'violation_resolution' => $subject->resolved_at !== null
                && trim((string) $subject->resolution_comment) !== ''
                ? [
                    'resolution_comment' => trim((string) $subject->resolution_comment),
                    'resolved_at' => CarbonImmutable::parse((string) $subject->resolved_at)->format(DATE_ATOM),
                ] : null,
            'corrective_action_verification' => $subject->resolved_at !== null
                && $subject->verified_at !== null
                && trim((string) $subject->resolution_comment) !== ''
                && trim((string) $subject->verification_comment) !== ''
                ? [
                    'resolution_comment' => trim((string) $subject->resolution_comment),
                    'resolved_at' => CarbonImmutable::parse((string) $subject->resolved_at)->format(DATE_ATOM),
                    'verification_comment' => trim((string) $subject->verification_comment),
                    'verified_at' => CarbonImmutable::parse((string) $subject->verified_at)->format(DATE_ATOM),
                ] : null,
            default => null,
        };
        if ($evidence === null) {
            return false;
        }

        return is_string($event->evidence_content_hash)
            && hash_equals(
                $event->evidence_content_hash,
                hash('sha256', CanonicalJson::encode($evidence)),
            );
    }

    private static function safetySubjectUsesContractor(
        string $table,
        int $organizationId,
        int $projectId,
        int $contractorId,
    ): bool {
        return DB::table($table)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('metadata->contractor_id', $contractorId)
            ->exists();
    }
}
