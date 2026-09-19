<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Models\Contract;

final class ContractPartyConsistencyReport
{
    public function issues(Contract $contract): array
    {
        $first = $contract->firstParty;
        $second = $contract->secondParty;
        if ($first === null || $second === null) {
            return ['missing_party_snapshot'];
        }
        $issues = [];
        $firstId = $first->linked_organization_id !== null ? (int) $first->linked_organization_id : null;
        $secondId = $second->linked_organization_id !== null ? (int) $second->linked_organization_id : null;
        if (!$contract->is_self_execution && $firstId !== null && $firstId === $secondId) {
            $issues[] = 'identical_organizations';
        }
        if (!in_array((int) $contract->organization_id, [$firstId, $secondId], true)) {
            $issues[] = 'owner_not_linked_to_parties';
        }
        $executorId = $contract->contractor?->source_organization_id;
        if ($executorId !== null && $secondId !== (int) $executorId) {
            $issues[] = 'executor_identity_mismatch';
        }

        return $issues;
    }
}
