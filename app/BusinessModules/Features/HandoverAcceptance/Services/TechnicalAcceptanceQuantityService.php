<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Services;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScopeWorkQuantity;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\ContractPerformanceAct;
use App\Models\Project;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

use function trans_message;

final readonly class TechnicalAcceptanceQuantityService
{
    public function draft(
        int $organizationId,
        int $actorId,
        int $scopeId,
        array $lines,
        int $expectedRevision,
        string $idempotencyKey,
    ): Collection {
        if ($expectedRevision < 0 || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 160 || $lines === [] || count($lines) > 500) {
            throw new BusinessLogicException(trans_message('technical_acceptance.errors.invalid_operation'), 422);
        }

        return DB::transaction(function () use ($organizationId, $actorId, $scopeId, $lines, $expectedRevision, $idempotencyKey): Collection {
            $scope = AcceptanceScope::query()->whereKey($scopeId)->where('organization_id', $organizationId)->firstOrFail();
            $canonicalLines = $this->canonicalLines($lines);
            $payloadHash = hash('sha256', json_encode($canonicalLines, JSON_THROW_ON_ERROR));
            $workIds = array_keys($canonicalLines);
            $project = Project::query()->whereKey($scope->project_id)->where('organization_id', $organizationId)->lockForUpdate()->firstOrFail();
            $works = CompletedWork::query()
                ->with(['estimateItem.measurementUnit', 'workType.measurementUnit'])
                ->where('organization_id', $organizationId)
                ->where('project_id', $project->id)
                ->whereIn('id', $workIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($works->count() !== count($workIds)) {
                throw new BusinessLogicException(trans_message('technical_acceptance.errors.work_unavailable'), 404);
            }
            $lockedScope = AcceptanceScope::query()->whereKey($scope->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedScope->organization_id !== $organizationId || (int) $lockedScope->project_id !== (int) $project->id) {
                throw new BusinessLogicException(trans_message('technical_acceptance.errors.work_unavailable'), 404);
            }
            $lockedScope->setRelation('project', $project);
            $this->assertActor($lockedScope, $actorId);
            $operation = DB::table('acceptance_scope_work_quantity_operations')
                ->where('acceptance_scope_id', $lockedScope->id)->where('operation_key', $idempotencyKey)->first();
            if ($operation !== null) {
                if ((string) $operation->payload_hash !== $payloadHash || (int) $operation->actor_id !== $actorId
                    || (int) $operation->expected_revision !== $expectedRevision) {
                    throw new BusinessLogicException(trans_message('technical_acceptance.errors.operation_conflict'), 409);
                }
                $snapshot = json_decode((string) $operation->result_snapshot, true, 512, JSON_THROW_ON_ERROR);

                return new Collection(array_map(static function (array $attributes): AcceptanceScopeWorkQuantity {
                    return (new AcceptanceScopeWorkQuantity)->newFromBuilder($attributes);
                }, $snapshot));
            }
            if (! in_array((string) $lockedScope->status, ['planned', 'in_progress', 'findings_open', 'ready_for_reinspection', 'rejected', 'reopened'], true)) {
                throw new BusinessLogicException(trans_message('technical_acceptance.errors.scope_immutable'), 409);
            }
            $currentRevision = (int) AcceptanceScopeWorkQuantity::query()->where('acceptance_scope_id', $scope->id)->max('revision');
            if ($currentRevision !== $expectedRevision) {
                throw new BusinessLogicException(trans_message('technical_acceptance.errors.revision_conflict'), 409);
            }

            $this->assertTotals($organizationId, $project->id, $works, $canonicalLines, $scope->id);
            $nextRevision = $currentRevision + 1;
            $result = new Collection;
            $snapshot = [];
            DB::table('acceptance_scope_work_quantity_operations')->insert([
                'organization_id' => $organizationId, 'acceptance_scope_id' => $lockedScope->id,
                'operation_key' => $idempotencyKey, 'payload_hash' => $payloadHash,
                'expected_revision' => $expectedRevision, 'resulting_revision' => $nextRevision,
                'actor_id' => $actorId, 'result_snapshot' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($canonicalLines as $workId => $line) {
                $work = $works->get($workId);
                $unitId = (int) ($work->estimateItem?->measurement_unit_id ?? $work->workType?->measurement_unit_id ?? 0);
                if ($unitId < 1 || $unitId !== (int) $line['unit_id']) {
                    throw new BusinessLogicException(trans_message('technical_acceptance.errors.unit_mismatch'), 422);
                }
                $row = AcceptanceScopeWorkQuantity::query()->firstOrNew(
                    ['acceptance_scope_id' => $lockedScope->id, 'completed_work_id' => $workId],
                );
                if (! $row->exists) {
                    $row->created_by_user_id = $actorId;
                }
                $row->fill([
                    'organization_id' => $organizationId,
                    'project_id' => $project->id,
                    'unit_id' => $unitId,
                    'presented_quantity' => $line['presented_quantity'],
                    'accepted_quantity' => $line['accepted_quantity'],
                    'defect_quantity' => $line['defect_quantity'],
                    'defect_reason' => $line['defect_reason'],
                    'revision' => $nextRevision,
                    'updated_by_user_id' => $actorId,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                ]);
                $row->save();
                $result->push($row);
                $snapshot[] = $row->getAttributes();
            }

            DB::table('acceptance_scope_work_quantity_operations')
                ->where('acceptance_scope_id', $lockedScope->id)->where('operation_key', $idempotencyKey)
                ->update(['result_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $result;
        });
    }

    public function lockScopeForDecision(AcceptanceScope $scope, array $completedWorkIds = []): AcceptanceScope
    {
        $project = Project::query()->whereKey($scope->project_id)->lockForUpdate()->firstOrFail();
        $ids = collect($completedWorkIds)->map(static fn ($id): int => (int) $id)->filter()->sort()->values();
        if ($ids->isEmpty()) {
            $ids = AcceptanceScopeWorkQuantity::query()
                ->where('acceptance_scope_id', $scope->id)
                ->orderBy('completed_work_id')
                ->pluck('completed_work_id');
        }
        if ($ids->isNotEmpty()) {
            CompletedWork::query()->where('organization_id', $scope->organization_id)->where('project_id', $project->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        }

        return AcceptanceScope::query()->whereKey($scope->id)->lockForUpdate()->firstOrFail();
    }

    public function assertActFits(ContractPerformanceAct $act, array $policy): void
    {
        if (! $this->acceptedOnly($policy)) {
            return;
        }
        $lines = $act->lines()->whereNotNull('completed_work_id')->get(['completed_work_id', 'quantity']);
        if ($lines->isEmpty()) {
            $lines = $act->completedWorks()->get()->map(static fn ($work) => [
                'completed_work_id' => $work->id, 'quantity' => $work->pivot->included_quantity,
            ]);
        }
        $works = CompletedWork::query()->whereIn('id', $lines->pluck('completed_work_id')->unique())
            ->where('organization_id', $act->contract->organization_id)->where('project_id', $act->project_id)
            ->orderBy('id')->lockForUpdate()->get();
        $requestedQuantities = [];
        foreach ($lines->groupBy('completed_work_id') as $workId => $workLines) {
            $requested = BigDecimal::zero();
            foreach ($workLines as $line) {
                if ($line['quantity'] === null) {
                    throw new BusinessLogicException(trans_message('technical_acceptance.errors.act_quantity_exceeded'), 422);
                }
                $requested = $requested->plus((string) $line['quantity']);
            }
            $requestedQuantities[(int) $workId] = (string) $requested;
        }
        $reservations = app(\App\Services\Acting\ActingQuantityReservationService::class);
        $reservations->assertAvailable($requestedQuantities, $reservations->availableQuantities($works, (int) $act->id, $policy));
    }

    public function acceptedQuantities(Collection $works, array $policy): array
    {
        if (! $this->acceptedOnly($policy) || $works->isEmpty()) {
            return [];
        }
        $decimalQuantities = $this->acceptedQuantityDecimals($works, $policy);

        return $works->mapWithKeys(static fn (CompletedWork $work): array => [
            (int) $work->id => self::scaledFloor($decimalQuantities[(int) $work->id] ?? '0'),
        ])->all();
    }

    public function acceptedQuantityDecimals(Collection $works, array $policy): array
    {
        if (! $this->acceptedOnly($policy) || $works->isEmpty()) {
            return [];
        }
        $breakdown = $this->acceptanceBreakdown($works);

        return $works->mapWithKeys(static fn (CompletedWork $work): array => [
            (int) $work->id => $breakdown[(int) $work->id]['accepted_quantity'] ?? '0',
        ])->all();
    }

    /**
     * @return array<int, array{presented_quantity: string, accepted_quantity: string, with_remarks_quantity: string}>
     */
    public function acceptanceBreakdown(Collection $works): array
    {
        if ($works->isEmpty()) {
            return [];
        }
        if (! Schema::hasTable('acceptance_scope_work_quantities') || ! Schema::hasTable('acceptance_scopes')) {
            return $works->mapWithKeys(static fn (CompletedWork $work): array => [
                (int) $work->id => [
                    'presented_quantity' => (string) $work->effectiveCompletedQuantity(),
                    'accepted_quantity' => '0',
                    'with_remarks_quantity' => '0',
                ],
            ])->all();
        }
        $rows = AcceptanceScopeWorkQuantity::query()
            ->join('acceptance_scopes', 'acceptance_scopes.id', '=', 'acceptance_scope_work_quantities.acceptance_scope_id')
            ->whereIn('acceptance_scope_work_quantities.completed_work_id', $works->pluck('id')->all())
            ->whereIn('acceptance_scope_work_quantities.organization_id', $works->pluck('organization_id')->unique()->all())
            ->whereIn('acceptance_scope_work_quantities.project_id', $works->pluck('project_id')->unique()->all())
            ->whereIn('acceptance_scopes.status', ['accepted', 'handed_over'])
            ->whereNull('acceptance_scopes.deleted_at')
            ->groupBy('acceptance_scope_work_quantities.completed_work_id')
            ->selectRaw(
                'acceptance_scope_work_quantities.completed_work_id,
                COALESCE(SUM(acceptance_scope_work_quantities.presented_quantity), 0) AS presented_quantity,
                COALESCE(SUM(acceptance_scope_work_quantities.accepted_quantity), 0) AS accepted_quantity,
                COALESCE(SUM(acceptance_scope_work_quantities.defect_quantity), 0) AS with_remarks_quantity'
            )
            ->get()
            ->keyBy('completed_work_id');

        return $works->mapWithKeys(static function (CompletedWork $work) use ($rows): array {
            $row = $rows->get($work->id);

            return [(int) $work->id => [
                'presented_quantity' => $row !== null
                    ? (string) $row->presented_quantity
                    : (string) $work->effectiveCompletedQuantity(),
                'accepted_quantity' => $row !== null ? (string) $row->accepted_quantity : '0',
                'with_remarks_quantity' => $row !== null ? (string) $row->with_remarks_quantity : '0',
            ]];
        })->all();
    }

    private function acceptedOnly(array $policy): bool
    {
        return data_get($policy, 'settings.technical_acceptance.mode') === 'accepted_only';
    }

    private function canonicalLines(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $id = is_array($line) ? filter_var($line['completed_work_id'] ?? null, FILTER_VALIDATE_INT) : false;
            $unitId = is_array($line) ? filter_var($line['unit_id'] ?? null, FILTER_VALIDATE_INT) : false;
            if ($id === false || $id < 1 || $unitId === false || $unitId < 1
                || is_bool($line['completed_work_id'] ?? null) || is_bool($line['unit_id'] ?? null)
                || (isset($line['defect_reason']) && ! is_string($line['defect_reason']))) {
                throw new BusinessLogicException(trans_message('technical_acceptance.errors.work_invalid'), 422);
            }
            if (array_key_exists($id, $result)) {
                throw new BusinessLogicException(trans_message('technical_acceptance.errors.work_duplicate'), 422);
            }
            $presented = $this->decimal($line['presented_quantity'] ?? null);
            $accepted = $this->decimal($line['accepted_quantity'] ?? null);
            $defect = $this->decimal($line['defect_quantity'] ?? null);
            $defectReason = trim((string) ($line['defect_reason'] ?? ''));
            if ($accepted->plus($defect)->isEqualTo($presented) === false || $accepted->isNegative() || $defect->isNegative()
                || mb_strlen($defectReason) > 2000 || ($defect->isPositive() && $defectReason === '')) {
                throw new BusinessLogicException(trans_message('technical_acceptance.errors.quantities_invalid'), 422);
            }
            $result[$id] = [
                'completed_work_id' => $id,
                'presented_quantity' => (string) $presented->toScale(6, RoundingMode::HalfUp),
                'accepted_quantity' => (string) $accepted->toScale(6, RoundingMode::HalfUp),
                'defect_quantity' => (string) $defect->toScale(6, RoundingMode::HalfUp),
                'defect_reason' => $defectReason !== '' ? $defectReason : null,
                'unit_id' => $unitId,
            ];
        }
        if ($result === []) {
            throw new BusinessLogicException(trans_message('technical_acceptance.errors.lines_required'), 422);
        }
        ksort($result);

        return $result;
    }

    private function assertTotals(int $organizationId, int $projectId, Collection $works, array $lines, int $scopeId): void
    {
        $other = AcceptanceScopeWorkQuantity::query()
            ->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->whereIn('completed_work_id', $works->pluck('id')->all())->where('acceptance_scope_id', '!=', $scopeId)
            ->selectRaw('completed_work_id, COALESCE(SUM(presented_quantity), 0) AS presented_quantity')
            ->groupBy('completed_work_id')->pluck('presented_quantity', 'completed_work_id');
        foreach ($works as $work) {
            $line = $lines[(int) $work->id];
            $otherQuantity = $other->get($work->id, '0');
            if (BigDecimal::of((string) $otherQuantity)->plus($line['presented_quantity'])->isGreaterThan((string) $work->effectiveCompletedQuantity())) {
                throw new BusinessLogicException(trans_message('technical_acceptance.errors.quantity_exceeded'), 422);
            }
        }
    }

    private function decimal(mixed $value): BigDecimal
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/^\d{1,18}(\.\d{1,6})?$/D', (string) $value) !== 1) {
            throw new BusinessLogicException(trans_message('technical_acceptance.errors.quantity_invalid'), 422);
        }
        try {
            $decimal = BigDecimal::of((string) $value);
            if ($decimal->isNegative() || $decimal->getScale() > 6) {
                throw new InvalidArgumentException;
            }

            return $decimal;
        } catch (InvalidArgumentException) {
            throw new BusinessLogicException(trans_message('technical_acceptance.errors.quantity_invalid'), 422);
        }
    }

    private static function scaledFloor(string $value): int
    {
        return (int) BigDecimal::of($value)->toScale(4, RoundingMode::Down)->multipliedBy('10000')->toInt();
    }

    private function assertActor(AcceptanceScope $scope, int $actorId): void
    {
        app(HandoverAcceptanceMutationGuard::class)->assertActor($scope, $actorId, 'handover-acceptance.edit');
    }
}
