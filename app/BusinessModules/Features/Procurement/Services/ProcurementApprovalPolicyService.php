<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Models\ProcurementApprovalPolicy;

class ProcurementApprovalPolicyService
{
    public const DEFAULTS = [
        'non_lowest_delta_amount' => 0,
        'non_lowest_delta_percent' => 0,
        'budget_exceed_amount' => 0,
        'external_supplier_requires_identity' => true,
        'prevent_requester_approval' => true,
        'prevent_selector_approval' => true,
        'prevent_intake_author_approval' => true,
        'required_approval_permission' => 'procurement.approvals.resolve',
        'is_active' => true,
    ];

    public function resolveForOrganization(int $organizationId): ProcurementApprovalPolicy
    {
        return ProcurementApprovalPolicy::query()->createOrFirst(
            ['organization_id' => $organizationId],
            self::DEFAULTS
        );
    }

    public function updateForOrganization(int $organizationId, array $data): ProcurementApprovalPolicy
    {
        $policy = $this->resolveForOrganization($organizationId);
        $policy->fill(array_intersect_key($data, self::DEFAULTS));
        $policy->save();

        return $policy->fresh();
    }

    public function resetForOrganization(int $organizationId): ProcurementApprovalPolicy
    {
        return $this->updateForOrganization($organizationId, self::DEFAULTS);
    }

    public function toSettingsArray(ProcurementApprovalPolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'non_lowest_delta_amount' => (float) $policy->non_lowest_delta_amount,
            'non_lowest_delta_percent' => (float) $policy->non_lowest_delta_percent,
            'budget_exceed_amount' => (float) $policy->budget_exceed_amount,
            'external_supplier_requires_identity' => (bool) $policy->external_supplier_requires_identity,
            'prevent_requester_approval' => (bool) $policy->prevent_requester_approval,
            'prevent_selector_approval' => (bool) $policy->prevent_selector_approval,
            'prevent_intake_author_approval' => (bool) $policy->prevent_intake_author_approval,
            'required_approval_permission' => $policy->required_approval_permission,
            'is_active' => (bool) $policy->is_active,
        ];
    }
}
