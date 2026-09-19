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

final class ContractPartyPreviewService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ProjectContractPartyResolver $participants,
        private readonly ContractPartySnapshotService $snapshots,
    ) {}

    public function preview(User $actor, int $organizationId, array $input): array
    {
        $project = Project::query()->findOrFail($input['project_id']);
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
}
