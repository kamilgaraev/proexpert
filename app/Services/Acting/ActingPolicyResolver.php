<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Models\ActingPolicy;
use App\Models\Contract;

class ActingPolicyResolver
{
    public function resolveForContract(Contract $contract): array
    {
        $contractPolicy = ActingPolicy::where('organization_id', $contract->organization_id)
            ->where('contract_id', $contract->id)
            ->latest('id')
            ->first();

        if ($contractPolicy) {
            return $this->toPayload($contractPolicy, 'contract');
        }

        $organizationPolicy = ActingPolicy::where('organization_id', $contract->organization_id)
            ->whereNull('contract_id')
            ->latest('id')
            ->first();

        if ($organizationPolicy) {
            return $this->toPayload($organizationPolicy, 'organization');
        }

        return [
            'id' => null,
            'mode' => ActingPolicy::MODE_OPERATIONAL,
            'allow_manual_lines' => false,
            'require_manual_line_reason' => true,
            'settings' => [],
            'source' => 'system_default',
        ];
    }

    private function toPayload(ActingPolicy $policy, string $source): array
    {
        return [
            'id' => $policy->id,
            'mode' => $policy->mode,
            'allow_manual_lines' => (bool) $policy->allow_manual_lines,
            'require_manual_line_reason' => (bool) $policy->require_manual_line_reason,
            'settings' => $policy->settings ?? [],
            'source' => $source,
        ];
    }
}
