<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\ProjectOrganizationRole;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class ContractPartyPreviewService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ProjectContractPartyResolver $participants,
        private readonly ContractPartySnapshotService $snapshots,
    ) {}

    public function preview(User $actor, int $organizationId, array $input): array
    {
        $projects = $this->projects($actor, $organizationId, $input);
        $projectData = array_map(static fn (Project $project): array => ['id' => (int) $project->id, 'name' => $project->name], $projects);
        if (($input['direct_party'] ?? null) !== null) {
            ContractDirectProjectAccess::assertAllowed($organizationId, $projects);
            if (!is_array($input['direct_party']) || ($input['contract_side_type'] ?? null) !== 'contract'
                || !empty($input['contractor_id']) || !empty($input['supplier_id']) || !empty($input['superior_organization_id']) || !empty($input['is_self_execution'])) {
                throw ValidationException::withMessages(['direct_party' => 'Проверьте прямой выбор сторон договора.']);
            }
            [$payer, $executor] = ContractDirectParties::resolve($organizationId, $input['direct_party'], $input['direction']);
            return ['direction' => $input['direction'], 'superior_role' => null, 'superior_candidates' => [],
                'first_party' => $payer->toArray(), 'second_party' => $executor->toArray(), 'reason' => null, 'projects' => $projectData];
        }
        if (count($projects) > 1) {
            $result = null;
            foreach ($projects as $candidate) {
                $current = $this->preview($actor, $organizationId, [...$input, 'project_id' => (int) $candidate->id, 'is_multi_project' => false, 'project_ids' => []]);
                if ($current['reason'] !== null) return [...$current, 'projects' => $projectData];
                if ($result !== null && [$result['first_party'], $result['second_party']] !== [$current['first_party'], $current['second_party']]) {
                    throw ValidationException::withMessages(['project_ids' => 'В проектах определены разные стороны. Выберите общие стороны напрямую или создайте отдельные договоры.']);
                }
                $result = $current;
            }
            return [...$result, 'projects' => $projectData];
        }
        $project = $projects[0] ?? throw ValidationException::withMessages(['project_id' => 'Выберите проект или укажите стороны напрямую.']);
        $role = $this->participants->assignedRole($project, $organizationId);
        if ((int) $actor->current_organization_id !== $organizationId
            || ((int) $project->organization_id !== $organizationId && $role === null)
            || !$this->authorization->can($actor, 'contracts.create', ['organization_id' => $organizationId, 'project_id' => $project->id])) {
            throw new AuthorizationException;
        }
        $owner = Organization::query()->findOrFail($organizationId);
        $type = ContractSideTypeEnum::from($input['contract_side_type']);
        $income = $input['direction'] === 'income';
        $superiorRole = $income ? match ($type) {
            ContractSideTypeEnum::GENERAL_CONTRACT => ProjectOrganizationRole::CUSTOMER,
            ContractSideTypeEnum::CONTRACT => $this->participants->superiorRole($project, $type),
            ContractSideTypeEnum::SUBCONTRACT => ProjectOrganizationRole::CONTRACTOR,
            default => null,
        } : null;
        $candidates = $superiorRole === null ? collect() : $this->participants->activeParticipants($project, $superiorRole);
        $result = [
            'direction' => $input['direction'],
            'superior_role' => $superiorRole?->value,
            'superior_candidates' => $candidates->map(fn (Organization $organization): array => [
                'id' => $organization->id, 'name' => $organization->name,
            ])->values()->all(),
            'first_party' => null, 'second_party' => null, 'reason' => null,
            'projects' => $projectData,
        ];
        if ($superiorRole !== null && $candidates->isEmpty() && $type !== ContractSideTypeEnum::GENERAL_CONTRACT) {
            $result['reason'] = trans_message('contracts.superior_missing', ['role' => $superiorRole->label()]);

            return $result;
        }
        $contract = new Contract;
        $contract->forceFill([
            'organization_id' => $organizationId, 'contract_side_type' => $type,
            'superior_organization_id' => $income ? ($input['superior_organization_id'] ?? null) : null,
        ]);
        $contract->setRelation('organization', $owner);
        $contract->setRelation('project', $project);
        $contractor = $income ? new Contractor(['source_organization_id' => $organizationId, 'name' => $owner->name, 'inn' => $owner->tax_number]) : null;
        if (!$income && !empty($input['contractor_id'])
            && app(ContractorSharingInterface::class)->canUseContractor((int) $input['contractor_id'], $organizationId)) {
            $contractor = Contractor::query()->find($input['contractor_id']);
        }
        $contract->setRelation('contractor', $contractor);
        $contract->setRelation('supplier', Supplier::query()->where('organization_id', $organizationId)->find($input['supplier_id'] ?? null));
        if ($income && $type === ContractSideTypeEnum::GENERAL_CONTRACT
            && ($candidates->count() > 1 || (!empty($input['superior_organization_id'])
                && !$candidates->contains('id', (int) $input['superior_organization_id'])))) {
            $result['reason'] = trans_message('contracts.superior_required');

            return $result;
        }
        if (($type->requiresSupplier() && $contract->supplier === null) || (!$income && !$type->requiresSupplier() && $contractor === null)) {
            $result['reason'] = trans_message('contracts.preview_counterparty_required');

            return $result;
        }
        try {
            [$payer, $executor] = $this->snapshots->resolveParties($contract, $type);
            if (($income && ($executor->linkedOrganizationId !== $organizationId || $payer->linkedOrganizationId === $organizationId))
                || (!$income && ($payer->linkedOrganizationId !== $organizationId || $executor->linkedOrganizationId === $organizationId))) {
                $result['reason'] = trans_message('contracts.direction_mismatch');

                return $result;
            }
            $result['first_party'] = $payer->toArray();
            $result['second_party'] = $executor->toArray();
        } catch (\DomainException) {
            $result['reason'] = trans_message('contracts.superior_required');
        }

        return $result;
    }

    private function projects(User $actor, int $organizationId, array $input): array
    {
        if ((int) $actor->current_organization_id !== $organizationId) throw new AuthorizationException;
        $multi = (bool) ($input['is_multi_project'] ?? false);
        $ids = $multi ? ($input['project_ids'] ?? []) : (isset($input['project_id']) ? [$input['project_id']] : []);
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 50 || ($multi && count($ids) < 2)) {
            throw ValidationException::withMessages(['project_ids' => 'Выберите от 2 до 50 проектов для многопроектного договора.']);
        }
        foreach ($ids as $id) { if ((!is_int($id) && (!is_string($id) || preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1)) || (int) $id < 1) throw ValidationException::withMessages(['project_ids' => 'Выберите доступные проекты.']); }
        $ids = array_map('intval', $ids);
        if (count($ids) !== count(array_unique($ids)) || (!$multi && !empty($input['project_ids']))
            || ($multi && isset($input['project_id']) && !in_array((int) $input['project_id'], $ids, true))) {
            throw ValidationException::withMessages(['project_ids' => 'Проверьте список проектов: не добавляйте повторы или проект вне выбранного списка.']);
        }
        sort($ids);
        if ($ids === []) {
            if (!$this->authorization->can($actor, 'contracts.create', ['organization_id' => $organizationId])) throw new AuthorizationException;
            return [];
        }
        $projects = [];
        foreach ($ids as $id) {
            $project = Project::findOrFail($id);
            if (((int) $project->organization_id !== $organizationId && $this->participants->assignedRole($project, $organizationId) === null)
                || !$this->authorization->can($actor, 'contracts.create', ['organization_id' => $organizationId, 'project_id' => $id])) throw new AuthorizationException;
            $projects[] = $project;
        }
        return $projects;
    }
}
