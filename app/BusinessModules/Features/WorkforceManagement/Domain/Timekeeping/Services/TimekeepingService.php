<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services;

use App\BusinessModules\Features\TimeTracking\Support\WorkTimeInterval;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrAccess;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Models\TimekeepingPeriod;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\File;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TimekeepingService
{
    public function __construct(private readonly HrAccess $access, private readonly AuthorizationService $authorization, private readonly TimekeepingSourceReader $sources, private readonly MonthlyTimekeepingCalculator $calculator, private readonly TimekeepingDocumentRenderer $renderer, private readonly FileService $files, private readonly TimekeepingPolicyService $policies) {}

    public function index(int $organizationId, User $actor, array $filters): LengthAwarePaginator
    {
        $this->access->assert($organizationId, $actor, 'workforce.timekeeping.view');
        $query = TimekeepingPeriod::query()->where('organization_id', $organizationId);
        if (isset($filters['project_id'])) {
            $this->project($organizationId, $actor, (int) $filters['project_id'], 'workforce.timekeeping.view');
            $query->where('project_id', $filters['project_id']);
        }
        if (isset($filters['month'])) {
            $query->where('month', $filters['month'].'-01');
        }
        $allowedProjects = DB::table('projects')->where('organization_id', $organizationId)->pluck('id')->filter(fn ($projectId) => $this->authorization->canCurrent($actor, 'workforce.timekeeping.view', ['organization_id' => $organizationId, 'project_id' => (int) $projectId]))->all();
        $query->where(fn ($scope) => $scope->whereNull('project_id')->orWhereIn('project_id', $allowedProjects));
        $paginator = $query->orderByDesc('month')->orderByDesc('id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
        $paginator->setCollection($paginator->getCollection()->map(fn ($period) => $this->projectPeriod($period, $actor, false)));

        return $paginator;
    }

    public function store(int $organizationId, User $actor, array $input): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.timekeeping.manage');
        $projectId = isset($input['project_id']) ? (int) $input['project_id'] : null;
        $this->project($organizationId, $actor, $projectId, 'workforce.timekeeping.manage');

        return DB::transaction(function () use ($organizationId, $actor, $input, $projectId): array {
            $this->lockOrganization($organizationId);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['timekeeping:'.$organizationId.':'.$input['month'].':'.($projectId ?? 0)]);
            $existing = TimekeepingPeriod::query()->where('organization_id', $organizationId)->where('month', $input['month'].'-01')->where('project_id', $projectId)->orderByDesc('id')->first();
            if ($existing !== null) {
                return $this->projectPeriod($existing, $actor);
            }
            $rules = TimekeepingRules::snapshot($this->policies->forOrganization($organizationId));
            $period = TimekeepingPeriod::query()->create(['organization_id' => $organizationId, 'project_id' => $projectId, 'month' => $input['month'].'-01', 'status' => 'draft', 'revision' => 1, 'rule_snapshot' => $rules, 'snapshot' => [], 'snapshot_sha256' => hash('sha256', '[]'), 'created_by' => $actor->id]);
            $this->recalculate($period, $actor);

            return $this->projectPeriod($period, $actor);
        });
    }

    public function show(int $organizationId, User $actor, int $id): array
    {
        return $this->projectPeriod($this->period($organizationId, $actor, $id, 'workforce.timekeeping.view'), $actor);
    }

    public function refresh(int $organizationId, User $actor, int $id, int $expectedRevision): array
    {
        return DB::transaction(function () use ($organizationId, $actor, $id, $expectedRevision): array {
            $this->lockOrganization($organizationId);
            $period = $this->period($organizationId, $actor, $id, 'workforce.timekeeping.manage', true);
            $this->editable($period, $expectedRevision, $actor);
            $period->revision++;
            $period->rule_snapshot = TimekeepingRules::snapshot($this->policies->forOrganization($organizationId));
            $this->recalculate($period, $actor);

            return $this->projectPeriod($period, $actor);
        });
    }

    public function cell(int $organizationId, User $actor, int $id, array $input): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.private.write');

        return DB::transaction(function () use ($organizationId, $actor, $id, $input): array {
            $this->lockOrganization($organizationId);
            $period = $this->period($organizationId, $actor, $id, 'workforce.timekeeping.manage', true);
            $this->editable($period, (int) $input['expected_revision'], $actor);
            $employee = DB::table('workforce_employees')->where('organization_id', $organizationId)->where('id', $input['employee_id'])->whereNull('deleted_at')->first();
            if ($employee === null || ! in_array((int) $employee->id, array_column($period->snapshot['rows'], 'employee_id'), true) || substr($input['work_date'], 0, 7) !== substr($period->month, 0, 7)) {
                $this->fail('scope_invalid');
            }
            $contractId = isset($input['employment_contract_id']) ? (int) $input['employment_contract_id'] : null;
            $contracts = DB::table('workforce_employment_contracts')->where('organization_id', $organizationId)->where('employee_id', $employee->id)->where('status', 'active')->where('start_date', '<=', $input['work_date'])->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $input['work_date']))->whereNull('deleted_at')->get();
            if ($contractId === null && $contracts->count() === 1) {
                $contractId = (int) $contracts->first()->id;
            }
            if ($contractId === null || ! $contracts->contains(fn ($c) => (int) $c->id === $contractId && $c->relation_type === 'employment')) {
                $this->fail('scope_invalid');
            }
            $contract = $contracts->first(fn ($c) => (int) $c->id === $contractId);
            if (empty($input['reason_code']) || empty($input['basis_reference'])) {
                $this->fail('reason_required');
            }
            if (! empty($input['basis_document_id'])) {
                if (! DB::table('workforce_hr_documents')->where('organization_id', $organizationId)->where('employee_id', $employee->id)->where('id', $input['basis_document_id'])->exists()) {
                    $this->fail('scope_invalid');
                }
            }
            foreach ($input['intervals'] ?? [] as $interval) {
                try {
                    $measured = WorkTimeInterval::fromInput($interval);
                    if ($measured->requiresReconciliation || $measured->timezone !== $contract->timezone || ! isset($measured->splitByLocalDate()[$input['work_date']])) {
                        $this->fail('interval_invalid');
                    }
                    foreach (array_keys($measured->splitByLocalDate()) as $localDate) {
                        if (substr($localDate, 0, 7) !== substr($period->month, 0, 7) || $localDate < $contract->start_date || ($contract->end_date !== null && $localDate > $contract->end_date)) {
                            $this->fail('interval_invalid');
                        }
                    }
                    if ($measured->netMinutes() > 24 * 60) {
                        $this->fail('interval_invalid');
                    }
                } catch (\InvalidArgumentException) {
                    $this->fail('interval_invalid');
                }
            }
            $period->revision++;
            $payload = array_intersect_key($input, array_flip(['reason_code', 'basis_reference', 'basis_document_id', 'intervals', 'comment']));
            $payload['replaced_source_refs'] = [];
            foreach ($period->snapshot['rows'] as $sourceRow) {
                if ($sourceRow['employee_id'] !== (int) $employee->id) {
                    continue;
                }
                foreach ($sourceRow['days'] as $sourceDay) {
                    if ($sourceDay['work_date'] === $input['work_date']) {
                        $payload['replaced_source_refs'] = array_merge($payload['replaced_source_refs'], $sourceDay['sources'], array_map(static fn (array $fact): array => ['type' => $fact['source_type'] ?? 'unknown', 'id' => $fact['source_id'] ?? null], $sourceDay['unresolved_facts'] ?? []));
                    }
                }
            }
            if (isset($input['review'])) {
                $payload['review'] = $this->reviewEvidence($organizationId, (int) $employee->id, $input['work_date'], $input['review'], $period, $contractId, $actor);
            }
            DB::table('workforce_timekeeping_cells')->insert(['period_id' => $period->id, 'employee_id' => $employee->id, 'employment_contract_id' => $contractId, 'work_date' => $input['work_date'], 'revision' => $period->revision, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'actor_id' => $actor->id, 'created_at' => now()]);
            $this->recalculate($period, $actor);

            return $this->projectPeriod($period, $actor);
        });
    }

    public function action(int $organizationId, User $actor, int $id, array $input): array
    {
        $permission = match ($input['action']) {
            'verify' => 'workforce.timekeeping.verify', 'approve' => 'workforce.timekeeping.approve', 'close' => 'workforce.timekeeping.close', default => 'workforce.timekeeping.manage'
        };

        return DB::transaction(function () use ($organizationId, $actor, $id, $input, $permission): array {
            $this->lockOrganization($organizationId);
            $period = $this->period($organizationId, $actor, $id, $permission, true);
            if ($period->revision !== (int) $input['expected_revision']) {
                $this->fail('revision_conflict');
            }
            try {
                $next = TimekeepingState::next($period->status, $input['action']);
            } catch (\DomainException) {
                $this->fail('transition_invalid');
            }
            $from = $period->status;
            $evidence = null;
            if ($input['action'] === 'reopen') {
                if (empty($input['basis_reference'])) {
                    $this->fail('reason_required');
                }
                if (TimekeepingPeriod::query()->where('supersedes_id', $period->id)->exists()) {
                    $this->fail('revision_conflict');
                }
                $predecessor = $period;
                $period = TimekeepingPeriod::query()->create(['organization_id' => $organizationId, 'project_id' => $period->project_id, 'month' => $period->month, 'supersedes_id' => $period->id, 'status' => 'draft', 'revision' => 1, 'rule_snapshot' => TimekeepingRules::snapshot($this->policies->forOrganization($organizationId)), 'snapshot' => [], 'snapshot_sha256' => hash('sha256', '[]'), 'created_by' => $actor->id]);
                $this->copyCorrections($predecessor, $period, $actor);
                $this->recalculate($period, $actor);
            } else {
                if (in_array($input['action'], ['verify', 'approve'], true)) {
                    $current = $this->calculator->calculate(substr($period->month, 0, 7), $this->sources->read($organizationId, substr($period->month, 0, 7), $period->project_id ? (int) $period->project_id : null, (int) $period->id), $period->rule_snapshot);
                    $currentHash = TimekeepingSnapshotHash::calculate($current);
                    if (! hash_equals($period->snapshot_sha256, $currentHash)) {
                        $this->fail('source_changed');
                    }
                }
                if (in_array($input['action'], ['verify', 'approve', 'close'], true) && ($period->snapshot['summary']['blocking_issue_count'] ?? 1) > 0) {
                    $this->fail('blocking_issues');
                }
                if ($input['action'] === 'approve') {
                    $this->access->assert($organizationId, $actor, 'workforce.hr.private.read');
                    $evidence = $this->paperEvidence($period, $input, $actor);
                    $period->approval = $evidence;
                }
                if ($input['action'] === 'close') {
                    if ($period->approval === null || ($period->approval['snapshot_sha256'] ?? null) !== $period->snapshot_sha256) {
                        $this->fail('evidence_mismatch');
                    }
                    if ((new \DateTimeImmutable($period->month))->modify('first day of next month') > new \DateTimeImmutable('today')) {
                        $this->fail('period_not_finished');
                    }
                    $this->assertLegalPolicy($period);
                    $this->assertFileDigest($organizationId, (int) $period->approval['signed_file_id'], $period->approval['signed_file_sha256']);
                    $approvedDocument = DB::table('workforce_timekeeping_documents')->where('period_id', $period->id)->where('id', $period->approval['document_id'])->first();
                    if ($approvedDocument === null) {
                        $this->fail('evidence_mismatch');
                    }
                    $this->assertFileDigest($organizationId, (int) $approvedDocument->file_id, $period->approval['document_sha256']);
                    $period->closed_at = now();
                    $period->archive_metadata = $this->archive($period, $actor);
                }
                $period->status = $next;
                $period->save();
            }
            DB::table('workforce_timekeeping_transitions')->insert(['period_id' => $period->id, 'revision' => $period->revision, 'action' => $input['action'], 'from_status' => $from, 'to_status' => $next, 'actor_id' => $actor->id, 'comment' => $input['comment'] ?? null, 'basis_reference' => $input['basis_reference'] ?? null, 'evidence' => $evidence ? json_encode($evidence, JSON_THROW_ON_ERROR) : null, 'created_at' => now()]);

            return $this->projectPeriod($period, $actor);
        });
    }

    public function export(int $organizationId, User $actor, int $id, string $format): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.private.read');

        return DB::transaction(function () use ($organizationId, $actor, $id, $format): array {
            $period = $this->period($organizationId, $actor, $id, 'workforce.timekeeping.export', true);
            $rendered = $this->renderer->render($period->toArray(), $format, (string) Organization::query()->findOrFail($organizationId)->name);
            $sha256 = hash('sha256', $rendered['body']);
            $filename = 'MOST-timekeeping-'.substr($period->month, 0, 7).'-v'.$period->revision.'.'.$format;
            $path = 'org-'.$organizationId.'/workforce/timekeeping/'.$period->id.'/'.Str::uuid().'/'.$filename;
            $stored = $this->files->putPrivate($path, $rendered['body'], $rendered['mime'], $sha256);
            $file = File::query()->create(['fileable_type' => TimekeepingPeriod::class, 'fileable_id' => $period->id, 'organization_id' => $organizationId, 'user_id' => $actor->id, 'name' => $filename, 'original_name' => $filename, 'path' => $path, 'mime_type' => $rendered['mime'], 'size' => $stored->sizeBytes, 'disk' => 's3', 'type' => 'document', 'category' => 'workforce_timekeeping', 'additional_info' => ['sha256' => $sha256, 'private' => true, 'period_id' => $period->id, 'revision' => $period->revision, 'snapshot_sha256' => $period->snapshot_sha256]]);
            $retention = $this->retentionYears($period);
            $documentId = DB::table('workforce_timekeeping_documents')->insertGetId(['period_id' => $period->id, 'revision' => $period->revision, 'file_id' => $file->id, 'format' => $format, 'sha256' => $sha256, 'snapshot_sha256' => $period->snapshot_sha256, 'form_version' => $period->rule_snapshot['form']['version'], 'retention_years' => $retention, 'retain_until' => (new \DateTimeImmutable(date('Y').'-12-31'))->modify('+'.$retention.' years')->format('Y-m-d'), 'filename' => $filename, 'actor_id' => $actor->id, 'created_at' => now()]);

            return $this->documentPayload(DB::table('workforce_timekeeping_documents')->find($documentId));
        });
    }

    public function uploadSignedFile(int $organizationId, User $actor, int $id, UploadedFile $upload, array $input): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.private.read');

        return DB::transaction(function () use ($organizationId, $actor, $id, $upload, $input): array {
            $period = $this->period($organizationId, $actor, $id, 'workforce.timekeeping.approve', true);
            if ($period->status !== 'verified' || $period->revision !== (int) $input['expected_revision']) {
                $this->fail('revision_conflict');
            }
            $document = DB::table('workforce_timekeeping_documents')->where('period_id', $period->id)->where('id', $input['document_id'])->where('revision', $period->revision)->first();
            if ($document === null || $document->snapshot_sha256 !== $period->snapshot_sha256) {
                $this->fail('document_not_found');
            }
            $mime = $upload->getMimeType();
            if (! in_array($mime, ['application/pdf', 'image/png', 'image/jpeg'], true) || $upload->getSize() > 20 * 1024 * 1024) {
                $this->fail('paper_evidence_required');
            }
            $body = $upload->getContent();
            $sha256 = hash('sha256', $body);
            $name = 'paper-signed-'.Str::uuid().'.'.match ($mime) {
                'application/pdf' => 'pdf', 'image/png' => 'png', default => 'jpg'
            };
            $path = 'org-'.$organizationId.'/workforce/timekeeping/'.$period->id.'/paper/'.$name;
            $stored = $this->files->putPrivate($path, $body, $mime, $sha256);
            $info = ['private' => true, 'sha256' => $sha256, 'period_id' => $period->id, 'document_id' => $document->id, 'document_revision' => $document->revision, 'document_sha256' => $document->sha256, 'snapshot_sha256' => $period->snapshot_sha256, 'evidence_kind' => 'paper_signed', 'recorded_by' => $actor->id, 'retention_years' => $document->retention_years, 'retain_until' => $document->retain_until];
            $file = File::query()->create(['fileable_type' => TimekeepingPeriod::class, 'fileable_id' => $period->id, 'organization_id' => $organizationId, 'user_id' => $actor->id, 'name' => $name, 'original_name' => $upload->getClientOriginalName(), 'path' => $path, 'mime_type' => $mime, 'size' => $stored->sizeBytes, 'disk' => 's3', 'type' => 'document', 'category' => 'workforce_timekeeping_paper', 'additional_info' => $info]);

            return array_merge($info, ['file_id' => $file->id, 'name' => $name, 'mime_type' => $mime, 'size' => $stored->sizeBytes]);
        });
    }

    public function download(int $organizationId, User $actor, int $documentId): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.private.read');
        $document = DB::table('workforce_timekeeping_documents as d')->join('workforce_timekeeping_periods as p', 'p.id', '=', 'd.period_id')->where('p.organization_id', $organizationId)->where('d.id', $documentId)->select('d.*')->first();
        if ($document === null) {
            $this->fail('document_not_found');
        }
        $this->period($organizationId, $actor, (int) $document->period_id, 'workforce.timekeeping.export');
        $file = File::query()->where('organization_id', $organizationId)->findOrFail($document->file_id);
        $this->assertFileDigest($organizationId, (int) $file->id, $document->sha256);

        return ['url' => $this->files->temporaryDownloadUrl($file->path, 300), 'expires_in' => 300];
    }

    public function ownApproved(int $organizationId, User $actor, int $employeeId, string $month): array
    {
        if (! $actor->belongsToOrganization($organizationId) || ! DB::table('workforce_employees')->where('organization_id', $organizationId)->where('user_id', $actor->id)->where('id', $employeeId)->whereNull('deleted_at')->exists()) {
            $this->fail('scope_invalid');
        }
        $periods = TimekeepingPeriod::query()->where('organization_id', $organizationId)->where('month', $month.'-01')->whereIn('status', ['approved', 'closed'])->orderByDesc('id')->get();
        $result = [];
        $scopes = [];
        foreach ($periods as $period) {
            $scope = $period->project_id ?? 0;
            if (isset($scopes[$scope])) {
                continue;
            }
            foreach ($period->snapshot['rows'] ?? [] as $row) {
                if ($row['employee_id'] !== $employeeId) {
                    continue;
                }
                $scopes[$scope] = true;
                $days = array_map(static fn (array $day): array => array_intersect_key($day, array_flip(['work_date', 'norm_minutes', 'planned_minutes', 'fact_minutes', 'night_minutes', 'weekend_minutes', 'overtime_minutes'])), $row['days']);
                $result[] = ['id' => $period->id, 'project_id' => $period->project_id, 'employment_contract_id' => $row['employment_contract_id'] ?? null, 'contract_number' => $row['contract_number'] ?? null, 'status' => $period->status, 'revision' => $period->revision, 'days' => $days, 'summary' => $row['summary']];
            }
        }

        return ['month' => $month, 'periods' => $result];
    }

    private function recalculate(TimekeepingPeriod $period, User $actor): void
    {
        $snapshot = $this->calculator->calculate(substr($period->month, 0, 7), $this->sources->read((int) $period->organization_id, substr($period->month, 0, 7), $period->project_id ? (int) $period->project_id : null, (int) $period->id), $period->rule_snapshot);
        $period->snapshot = $snapshot;
        $period->snapshot_sha256 = TimekeepingSnapshotHash::calculate($snapshot);
        $period->save();
        DB::table('workforce_timekeeping_revisions')->insert(['period_id' => $period->id, 'revision' => $period->revision, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'sha256' => $period->snapshot_sha256, 'actor_id' => $actor->id, 'created_at' => now()]);
    }

    /** Source writers take this same lock before checking frozen periods and mutating facts. */
    private function lockOrganization(int $organizationId): void
    {
        Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
    }

    private function period(int $organizationId, User $actor, int $id, string $permission, bool $lock = false): TimekeepingPeriod
    {
        $this->access->assert($organizationId, $actor, $permission);
        $period = TimekeepingPeriod::query()->where('organization_id', $organizationId)->when($lock, fn ($q) => $q->lockForUpdate())->find($id);
        if ($period === null) {
            $this->fail('scope_invalid');
        }
        $this->project($organizationId, $actor, $period->project_id ? (int) $period->project_id : null, $permission);

        return $period;
    }

    private function project(int $organizationId, User $actor, ?int $projectId, string $permission): void
    {
        if ($projectId !== null && (! DB::table('projects')->where('organization_id', $organizationId)->where('id', $projectId)->exists() || ! $this->authorization->canCurrent($actor, $permission, ['organization_id' => $organizationId, 'project_id' => $projectId]))) {
            $this->fail('scope_invalid');
        }
    }

    private function editable(TimekeepingPeriod $period, int $expectedRevision, User $actor): void
    {
        try {
            TimekeepingState::editable($period->status, $period->revision, $expectedRevision);
        } catch (\DomainException $e) {
            $this->fail(str_replace('timekeeping_', '', $e->getMessage()));
        }
        if ($period->status !== 'draft') {
            DB::table('workforce_timekeeping_transitions')->insert(['period_id' => $period->id, 'revision' => $period->revision, 'action' => 'revise', 'from_status' => $period->status, 'to_status' => 'draft', 'actor_id' => $actor->id, 'created_at' => now()]);
            $period->status = 'draft';
        }
    }

    private function paperEvidence(TimekeepingPeriod $period, array $input, User $actor): array
    {
        $this->assertLegalPolicy($period);
        if (empty($input['document_id']) || empty($input['document_revision']) || empty($input['signed_file_id']) || empty($input['signer_name']) || empty($input['signed_at']) || empty($input['signed_on']) || empty($input['paper_original_location'])) {
            $this->fail('paper_evidence_required');
        }
        $document = DB::table('workforce_timekeeping_documents')->where('period_id', $period->id)->where('id', $input['document_id'])->first();
        $file = File::query()->where('organization_id', $period->organization_id)->find($input['signed_file_id']);
        $info = $file?->additional_info ?? [];
        if ($document === null || $document->revision !== (int) $input['document_revision'] || $document->revision !== $period->revision || $document->snapshot_sha256 !== $period->snapshot_sha256 || ($info['document_id'] ?? null) !== $document->id || ($info['document_sha256'] ?? null) !== $document->sha256 || ($info['snapshot_sha256'] ?? null) !== $period->snapshot_sha256 || ($info['evidence_kind'] ?? null) !== 'paper_signed') {
            $this->fail('evidence_mismatch');
        }
        $stream = $this->files->readCurrent($file->path);
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);
        if (! hash_equals($info['sha256'], hash_final($context))) {
            $this->fail('evidence_mismatch');
        }
        if ($input['signed_on'] !== substr($input['signed_at'], 0, 10) || new \DateTimeImmutable($input['signed_at']) > new \DateTimeImmutable || $input['signed_on'] < substr((string) $document->created_at, 0, 10)) {
            $this->fail('paper_evidence_required');
        }

        return ['signing_method' => 'paper', 'signed_file_id' => $file->id, 'signed_file_sha256' => $info['sha256'], 'document_id' => $document->id, 'document_revision' => $document->revision, 'document_sha256' => $document->sha256, 'snapshot_sha256' => $period->snapshot_sha256, 'signer_name' => $input['signer_name'], 'signed_at' => $input['signed_at'], 'signed_on' => $input['signed_on'], 'paper_original_location' => $input['paper_original_location'], 'declaration' => 'operator_registered_paper_evidence', 'recorded_by' => $actor->id, 'recorded_at' => now()->toIso8601String()];
    }

    private function assertLegalPolicy(TimekeepingPeriod $period): void
    {
        if (empty($period->rule_snapshot['form']['approved_basis_reference']) || empty($period->rule_snapshot['form']['approved_at'])) {
            $this->fail('form_unapproved');
        }
        if (empty($period->rule_snapshot['retention']['approved_basis_reference'])) {
            $this->fail('retention_policy_required');
        }
    }

    private function archive(TimekeepingPeriod $period, User $actor): array
    {
        $years = $this->retentionYears($period);
        $retainUntil = (new \DateTimeImmutable(date('Y').'-12-31'))->modify('+'.$years.' years')->format('Y-m-d');
        $manifest = ['schema_version' => 'MOST-TIME-ARCHIVE-1', 'snapshot_hash_algorithm' => 'sha256-canonical-json-v1', 'organization_id' => $period->organization_id, 'period_id' => $period->id, 'revision' => $period->revision, 'case_closed_at' => $period->closed_at->toIso8601String(), 'snapshot_sha256' => $period->snapshot_sha256, 'snapshot' => $period->snapshot, 'rule_snapshot' => $period->rule_snapshot, 'corrections' => DB::table('workforce_timekeeping_cells')->where('period_id', $period->id)->get()->map(static fn ($cell) => (array) $cell)->all(), 'approval' => $period->approval, 'documents' => DB::table('workforce_timekeeping_documents')->where('period_id', $period->id)->get()->map(static fn ($d) => (array) $d)->all(), 'retention_years' => $years, 'retain_until' => $retainUntil, 'disposal_requires_expert_review' => true];
        $body = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sha256 = hash('sha256', $body);
        $filename = 'MOST-timekeeping-archive-'.$period->id.'-v'.$period->revision.'.json';
        $path = 'org-'.$period->organization_id.'/workforce/timekeeping/'.$period->id.'/archive/'.Str::uuid().'/'.$filename;
        $stored = $this->files->putPrivate($path, $body, 'application/json', $sha256);
        $file = File::query()->create(['fileable_type' => TimekeepingPeriod::class, 'fileable_id' => $period->id, 'organization_id' => $period->organization_id, 'user_id' => $actor->id, 'name' => $filename, 'original_name' => $filename, 'path' => $path, 'mime_type' => 'application/json', 'size' => $stored->sizeBytes, 'disk' => 's3', 'type' => 'document', 'category' => 'workforce_timekeeping_archive', 'additional_info' => ['sha256' => $sha256, 'private' => true, 'period_id' => $period->id, 'revision' => $period->revision, 'snapshot_sha256' => $period->snapshot_sha256, 'retain_until' => $retainUntil, 'disposal_requires_expert_review' => true]]);
        $documentId = DB::table('workforce_timekeeping_documents')->insertGetId(['period_id' => $period->id, 'revision' => $period->revision, 'file_id' => $file->id, 'format' => 'json', 'sha256' => $sha256, 'snapshot_sha256' => $period->snapshot_sha256, 'form_version' => $period->rule_snapshot['form']['version'], 'retention_years' => $years, 'retain_until' => $retainUntil, 'filename' => $filename, 'actor_id' => $actor->id, 'created_at' => now()]);

        return ['file_id' => $file->id, 'document_id' => $documentId, 'sha256' => $sha256, 'case_closed_at' => $period->closed_at->toIso8601String(), 'retention_years' => $years, 'retain_until' => $retainUntil, 'disposal_requires_expert_review' => true];
    }

    private function retentionYears(TimekeepingPeriod $period): int
    {
        $hazardous = in_array('hazardous', array_column($period->snapshot['rows'] ?? [], 'retention_class'), true);
        if (! $hazardous) {
            return (int) $period->rule_snapshot['retention']['ordinary_years'];
        }

        return (int) $period->rule_snapshot['retention'][(int) date('Y') < 2003 ? 'pre2003_hazardous_years' : 'hazardous_years'];
    }

    private function projectPeriod(TimekeepingPeriod $period, User $actor, bool $detail = true): array
    {
        $private = $this->access->allows((int) $period->organization_id, $actor, 'workforce.hr.private.read');
        $snapshot = $period->snapshot;
        if (! $private && isset($snapshot['rows'])) {
            foreach ($snapshot['rows'] as &$row) {
                foreach ($row['days'] as &$day) {
                    if ($day['absence_code'] !== null) {
                        $day['absence_code'] = 'absence';
                    } unset($day['correction'], $day['intervals'], $day['unresolved_facts'], $day['absence_ground']);
                    $day['sources'] = array_values(array_filter($day['sources'], static fn (array $source): bool => ! in_array($source['type'], ['absence', 'business_trip', 'manual_cell'], true)));
                } unset($day);
            } unset($row);
        }
        $result = array_merge($snapshot, ['id' => $period->id, 'organization_id' => $period->organization_id, 'project_id' => $period->project_id, 'month' => substr($period->month, 0, 7), 'status' => $period->status, 'revision' => $period->revision, 'supersedes_id' => $period->supersedes_id, 'snapshot_sha256' => $period->snapshot_sha256, 'approval' => $private ? $period->approval : null, 'archive_metadata' => $private ? $period->archive_metadata : null, 'closed_at' => $period->closed_at?->toIso8601String()]);
        $result['actions'] = [];
        foreach (TimekeepingState::TRANSITIONS as $action => $transition) {
            $permission = match ($action) {
                'verify' => 'workforce.timekeeping.verify', 'approve' => 'workforce.timekeeping.approve', 'close' => 'workforce.timekeeping.close', default => 'workforce.timekeeping.manage'
            };
            $enabled = $transition[0] === $period->status && $this->access->allows((int) $period->organization_id, $actor, $permission);
            $result['actions'][] = ['code' => $action, 'enabled' => $enabled, 'reason' => $enabled ? null : 'state_or_permission'];
        }
        if (! $detail) {
            unset($result['rows']);

            return $result;
        }
        $result['documents'] = $private && $this->access->allows((int) $period->organization_id, $actor, 'workforce.timekeeping.export') ? DB::table('workforce_timekeeping_documents')->where('period_id', $period->id)->orderByDesc('id')->get()->map(fn ($d) => $this->documentPayload($d))->all() : [];
        $result['transitions'] = DB::table('workforce_timekeeping_transitions')->where('period_id', $period->id)->orderBy('id')->get()->map(static function ($t) use ($private): array {
            $t = (array) $t;
            unset($t['evidence']);
            if (! $private) {
                unset($t['comment'], $t['basis_reference']);
            }

            return $t;
        })->all();

        return $result;
    }

    private function documentPayload(object $document): array
    {
        $payload = (array) $document;
        $payload['signing_method'] = 'paper';

        return $payload;
    }

    private function assertFileDigest(int $organizationId, int $fileId, string $sha256): void
    {
        $file = File::query()->where('organization_id', $organizationId)->find($fileId);
        if ($file === null) {
            $this->fail('evidence_mismatch');
        }
        $stream = $this->files->readCurrent($file->path);
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);
        if (! hash_equals($sha256, hash_final($context))) {
            $this->fail('evidence_mismatch');
        }
    }

    private function reviewEvidence(int $organizationId, int $employeeId, string $date, array $review, TimekeepingPeriod $period, int $contractId, User $actor): array
    {
        $types = ['annual_baseline_document_id' => 'overtime_ledger', 'overtime_consent_document_id' => 'overtime_consent', 'overtime_order_document_id' => 'overtime_order', 'weekend_basis_document_id' => 'weekend_work_order', 'collective_agreement_document_id' => 'collective_agreement', 'accounting_period_review_document_id' => 'summed_accounting_review', 'business_trip_work_document_id' => 'business_trip_order'];
        $validated = array_intersect_key($review, array_flip(['annual_minutes_before_month', 'accounting_period_overtime_minutes', 'overtime_minutes']));
        foreach ($types as $field => $type) {
            if (empty($review[$field])) {
                continue;
            }
            $document = DB::table('workforce_hr_documents')->where('organization_id', $organizationId)->where('employee_id', $employeeId)->where('id', $review[$field])->where('document_type', $type)->where('evidence_kind', 'paper_signed')->first();
            if ($document === null || $document->evidence_file_id === null) {
                $this->fail('evidence_mismatch');
            }
            $metadata = json_decode($document->paper_evidence_metadata ?? '{}', true) ?: [];
            if ((int) ($metadata['matching_revision'] ?? 0) !== (int) $document->revision
                || empty($metadata['signed_on']) || $metadata['signed_on'] < $document->document_date
                || $metadata['signed_on'] > date('Y-m-d') || empty($metadata['paper_original_location'])
                || DB::table('workforce_hr_documents')->where('organization_id', $organizationId)->where('supersedes_id', $document->id)->exists()) {
                $this->fail('evidence_mismatch');
            }
            if (! in_array($type, ['overtime_ledger', 'summed_accounting_review'], true) && (! isset($metadata['signed_on']) || $metadata['signed_on'] > $date)) {
                $this->fail('evidence_mismatch');
            }
            $evidenceFile = File::query()->where('organization_id', $organizationId)->find($document->evidence_file_id);
            if ($evidenceFile === null || $evidenceFile->disk !== 's3'
                || ! str_starts_with($evidenceFile->path, 'org-'.$organizationId.'/workforce/hr/')
                || ($evidenceFile->additional_info['visibility'] ?? null) !== 'private'
                || (int) ($evidenceFile->additional_info['hr_employee_id'] ?? 0) !== $employeeId
                || $evidenceFile->fileable_type !== WorkforceEmployee::class
                || (int) $evidenceFile->fileable_id !== $employeeId
                || ! in_array($evidenceFile->mime_type, ['application/pdf', 'image/png', 'image/jpeg'], true)
                || (int) $document->file_id === (int) $evidenceFile->id) {
                $this->fail('evidence_mismatch');
            }
            $stream = $this->files->readCurrent($evidenceFile->path);
            $digest = hash_init('sha256');
            hash_update_stream($digest, $stream);
            fclose($stream);
            $sha256 = hash_final($digest);
            if (! hash_equals((string) ($evidenceFile->additional_info['sha256'] ?? ''), $sha256)) {
                $this->fail('evidence_mismatch');
            }
            $validated[$field] = ['id' => $document->id, 'revision' => $document->revision, 'evidence_file_id' => $document->evidence_file_id, 'evidence_sha256' => $sha256, 'document_type' => $type];
        }
        if (isset($validated['annual_minutes_before_month']) && ! isset($validated['annual_baseline_document_id'])) {
            $this->fail('evidence_mismatch');
        }
        if ((isset($validated['accounting_period_overtime_minutes']) || isset($validated['overtime_minutes'])) && ! isset($validated['accounting_period_review_document_id'])) {
            $this->fail('evidence_mismatch');
        }

        $validated['scope'] = ['period_id' => $period->id, 'month' => substr($period->month, 0, 7), 'employment_contract_id' => $contractId, 'work_date' => $date, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->toIso8601String(), 'declaration' => 'operator_reviewed_grounds_for_contract_and_date'];

        return $validated;
    }

    private function copyCorrections(TimekeepingPeriod $predecessor, TimekeepingPeriod $successor, User $actor): void
    {
        $cells = DB::table('workforce_timekeeping_cells')->where('period_id', $predecessor->id)->orderByDesc('revision')->get()->unique(fn ($cell) => $cell->employee_id.':'.($cell->employment_contract_id ?? 0).':'.$cell->work_date);
        foreach ($cells as $cell) {
            $payload = json_decode($cell->payload, true, 512, JSON_THROW_ON_ERROR);
            $payload['supersedes_cell_id'] = $cell->id;
            DB::table('workforce_timekeeping_cells')->insert(['period_id' => $successor->id, 'employee_id' => $cell->employee_id, 'employment_contract_id' => $cell->employment_contract_id, 'work_date' => $cell->work_date, 'revision' => 1, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'actor_id' => $actor->id, 'created_at' => now()]);
        }
    }

    private function fail(string $code): never
    {
        throw new \DomainException(trans_message('workforce.errors.timekeeping_'.$code));
    }
}
