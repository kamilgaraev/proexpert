<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Services;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScopeWorkQuantity;
use App\BusinessModules\Features\HandoverAcceptance\Models\WorkRework;
use App\Models\CompletedWork;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class WorkReworkService
{
    public function __construct(
        private TechnicalAcceptanceQuantityService $quantities,
        private HandoverAcceptanceMutationGuard $guard,
        private UserProjectAccessService $projectAccess,
        private WorkReworkFinancialImpact $financialImpact,
    ) {}

    public function create(AcceptanceScope $scope, int $actorId, array $data): WorkRework
    {
        $this->validateOperation($data, [
            'quantity_line_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'string', 'regex:/^\d{1,18}(\.\d{1,6})?$/D'],
            'responsible_user_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return $this->operation($scope, $actorId, 'create', $data, null, function (AcceptanceScope $locked) use ($actorId, $data): WorkRework {
            $line = AcceptanceScopeWorkQuantity::query()->where('acceptance_scope_id', $locked->id)->find($data['quantity_line_id']);
            if ($line === null) {
                $this->fail('not_found', 404);
            }
            $this->assertSource($locked, $line);
            if ((int) $line->revision !== (int) $data['expected_revision']) {
                $this->fail('revision_conflict', 409);
            }
            $quantity = BigDecimal::of($data['quantity']);
            $reserved = WorkRework::query()->where('quantity_line_id', $line->id)->where('status', '!=', 'accepted')->sum('quantity');
            if (! $quantity->isPositive() || $quantity->plus((string) $reserved)->isGreaterThan($line->defect_quantity)) {
                $this->fail('quantity_exceeded', 422);
            }
            $responsible = User::query()->find($data['responsible_user_id']);
            if ($responsible === null || ! $responsible->belongsToOrganization((int) $locked->organization_id)
                || ! $this->projectAccess->canAccessProject($responsible, $locked->project, (int) $locked->organization_id)) {
                $this->fail('responsible_invalid', 422);
            }

            return WorkRework::query()->create([
                'organization_id' => $locked->organization_id, 'project_id' => $locked->project_id,
                'acceptance_scope_id' => $locked->id, 'quantity_line_id' => $line->id,
                'quantity' => (string) $quantity->toScale(6), 'unit_id' => $line->unit_id, 'responsible_user_id' => $responsible->id,
                'created_by_user_id' => $actorId, 'reason' => trim($data['reason']), 'status' => 'open', 'revision' => 1,
                'financial_impact' => $this->financialImpact->snapshot($line),
            ]);
        });
    }

    public function listForScope(AcceptanceScope $scope, int $actorId): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $this->guard->assertActor($scope, $actorId, 'handover-acceptance.view');

        return $scope->workReworks()->with('quantityLine')->orderByDesc('id')->paginate(50);
    }

    public function findForActor(int $organizationId, int $actorId, int $id): WorkRework
    {
        $rework = WorkRework::query()->where('organization_id', $organizationId)->with(['scope', 'quantityLine'])->find($id);
        if ($rework === null) {
            $this->fail('not_found', 404);
        }
        $this->guard->assertActor($rework->scope, $actorId, 'handover-acceptance.view');

        return $rework;
    }

    public function history(WorkRework $rework, int $actorId): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $this->guard->assertActor($rework->scope, $actorId, 'handover-acceptance.view');

        return DB::table('work_rework_events')->where('organization_id', $rework->organization_id)
            ->where('work_rework_id', $rework->id)->orderBy('id')->paginate(50);
    }

    public function submit(WorkRework $rework, int $actorId, array $data): WorkRework
    {
        $this->validateOperation($data, [
            'description' => ['required', 'string', 'max:4000'],
            'evidence_file_ids' => ['present', 'array', 'max:30'],
            'evidence_file_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);

        return $this->operation($rework->scope, $actorId, 'submit', $data, $rework->id, function (AcceptanceScope $scope, WorkRework $locked) use ($data): WorkRework {
            if (! in_array($locked->status, ['open', 'rejected'], true)) {
                $this->fail('invalid_status', 409);
            }
            $this->assertSource($scope, $locked->quantityLine, (int) $locked->unit_id);
            $files = File::query()->where('organization_id', $scope->organization_id)->whereIn('id', $data['evidence_file_ids'])->lockForUpdate()->get();
            if ($files->count() !== count($data['evidence_file_ids'])) {
                $this->fail('evidence_invalid', 422);
            }
            $allowed = [
                (new Project)->getMorphClass() => (int) $scope->project_id,
                (new AcceptanceScope)->getMorphClass() => (int) $scope->id,
                (new CompletedWork)->getMorphClass() => (int) $locked->quantityLine->completed_work_id,
                $locked->getMorphClass() => (int) $locked->id,
            ];
            foreach ($files as $file) {
                if (($allowed[$file->fileable_type] ?? null) !== (int) $file->fileable_id) {
                    $this->fail('evidence_invalid', 422);
                }
            }
            $locked->update([
                'status' => 'submitted', 'revision' => $locked->revision + 1,
                'correction_description' => trim($data['description']), 'submitted_at' => now(),
                'verified_at' => null, 'verified_by_user_id' => null,
                'evidence_snapshot' => $files->map(fn (File $file): array => $file->only(['id', 'fileable_type', 'fileable_id', 'name', 'path', 'disk', 'size', 'updated_at']))->all(),
            ]);

            return $locked->fresh();
        });
    }

    public function verify(WorkRework $rework, int $actorId, array $data): WorkRework
    {
        $this->validateOperation($data, [
            'decision' => ['required', 'in:accepted,rejected'], 'comment' => ['required', 'string', 'max:2000'],
        ]);

        return $this->operation($rework->scope, $actorId, 'verify', $data, $rework->id, function (AcceptanceScope $scope, WorkRework $locked) use ($actorId, $data): WorkRework {
            if ($locked->status !== 'submitted') {
                $this->fail('invalid_status', 409);
            }
            $line = $locked->quantityLine;
            $this->assertSource($scope, $line, (int) $locked->unit_id);
            $before = $line->getAttributes();
            if ($data['decision'] === 'accepted') {
                if ($locked->findings()->where('status', '!=', 'resolved')->exists()) {
                    $this->fail('findings_open', 422);
                }
                foreach ($locked->findings()->with('qualityDefect')->get() as $finding) {
                    $defect = $finding->qualityDefect;
                    if ($defect !== null && (! in_array($defect->status->value, ['resolved', 'cancelled'], true)
                        || ($defect->status->value === 'resolved' && $defect->inspection_required && $defect->verified_at === null))) {
                        $this->fail('quality_unverified', 422);
                    }
                }
                if (BigDecimal::of($locked->quantity)->isGreaterThan($line->defect_quantity)) {
                    $this->fail('quantity_exceeded', 409);
                }
                $line->update([
                    'accepted_quantity' => (string) BigDecimal::of($line->accepted_quantity)->plus($locked->quantity)->toScale(6),
                    'defect_quantity' => (string) BigDecimal::of($line->defect_quantity)->minus($locked->quantity)->toScale(6),
                    'revision' => (int) $scope->workQuantities()->max('revision') + 1,
                    'updated_by_user_id' => $actorId,
                ]);
            }
            $impact = $this->financialImpact->snapshot($line);
            $locked->update([
                'status' => $data['decision'], 'revision' => $locked->revision + 1,
                'verified_by_user_id' => $actorId, 'verified_at' => now(), 'financial_impact' => $impact,
            ]);
            $scope->signoffs()->create([
                'organization_id' => $scope->organization_id, 'project_id' => $scope->project_id,
                'signed_by_user_id' => $actorId, 'status' => 'rework_'.$data['decision'],
                'signed_at' => now(), 'comment' => $data['comment'],
                'evidence_snapshot' => ['work_rework_id' => $locked->id, 'quantity' => $locked->quantity,
                    'before' => $before, 'after' => $line->fresh()->getAttributes(), 'financial_impact' => $impact,
                    'evidence' => $locked->evidence_snapshot, 'findings' => $locked->findings()->get()->toArray()],
            ]);

            return $locked->fresh();
        });
    }

    private function operation(AcceptanceScope $scope, int $actorId, string $action, array $data, ?int $reworkId, callable $change): WorkRework
    {
        return DB::transaction(function () use ($scope, $actorId, $action, $data, $reworkId, $change): WorkRework {
            $scope = $this->quantities->lockScopeForDecision($scope);
            $this->guard->assertActor($scope, $actorId, $action === 'verify' ? 'handover-acceptance.approve' : 'handover-acceptance.edit');
            $hash = hash('sha256', json_encode([$action, $reworkId, $actorId, $this->canonicalData($data)], JSON_THROW_ON_ERROR));
            $event = DB::table('work_rework_events')->where('acceptance_scope_id', $scope->id)->where('operation_key', $data['operation_key'])->first();
            if ($event !== null) {
                if (! hash_equals($event->payload_hash, $hash)) {
                    $this->fail('operation_conflict', 409);
                }

                return (new WorkRework)->newFromBuilder(json_decode($event->result_snapshot, true, 512, JSON_THROW_ON_ERROR));
            }
            $locked = null;
            if ($reworkId !== null) {
                $locked = WorkRework::query()->where('acceptance_scope_id', $scope->id)->where('organization_id', $scope->organization_id)->lockForUpdate()->find($reworkId);
                if ($locked === null) {
                    $this->fail('not_found', 404);
                }
                if ($locked->revision !== (int) $data['expected_revision']) {
                    $this->fail('revision_conflict', 409);
                }
            }
            $result = $change($scope, $locked);
            DB::table('work_rework_events')->insert([
                'organization_id' => $scope->organization_id, 'acceptance_scope_id' => $scope->id,
                'work_rework_id' => $result->id, 'actor_id' => $actorId, 'action' => $action,
                'operation_key' => $data['operation_key'], 'payload_hash' => $hash,
                'payload' => json_encode($data, JSON_THROW_ON_ERROR),
                'result_snapshot' => json_encode($result->getAttributes(), JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return $result;
        });
    }

    private function assertSource(AcceptanceScope $scope, AcceptanceScopeWorkQuantity $line, ?int $expectedUnitId = null): void
    {
        $work = $line->completedWork;
        if ($work === null || $work->status !== CompletedWork::STATUS_CONFIRMED
            || ($expectedUnitId !== null && (int) $line->unit_id !== $expectedUnitId)
            || (int) $line->organization_id !== (int) $scope->organization_id || (int) $line->project_id !== (int) $scope->project_id
            || (int) $work->organization_id !== (int) $scope->organization_id || (int) $work->project_id !== (int) $scope->project_id
            || (int) $line->unit_id !== (int) ($work->estimateItem?->measurement_unit_id ?? $work->workType?->measurement_unit_id)
            || BigDecimal::of($line->presented_quantity)->isGreaterThan((string) $work->effectiveCompletedQuantity())) {
            $this->fail('source_changed', 409);
        }
    }

    private function validateOperation(array $data, array $rules): void
    {
        Validator::make($data, $rules + [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'operation_key' => ['required', 'string', 'max:160'],
        ])->validate();
    }

    private function canonicalData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonicalData($value);
            }
        }
        if (! array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }

    private function fail(string $key, int $status): never
    {
        throw new WorkReworkException($key, $status);
    }
}
