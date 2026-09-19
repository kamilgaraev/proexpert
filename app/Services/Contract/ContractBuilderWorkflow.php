<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Models\Contract;

final class ContractBuilderWorkflow
{
    public static function canRevise(Contract $contract, object $instance): bool
    {
        $status = $contract->getRawOriginal('status');

        return $status === 'draft' || ($status === 'active' && ($instance->effective_revision_id !== null || $instance->legacy_adoption_id !== null));
    }
}
