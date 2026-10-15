<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Services;

use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalExport;

final readonly class CommercialProposalAmountSnapshot
{
    public function __construct(
        public int $organizationId,
        public bool $isMember,
        public bool $canViewAmounts,
    ) {
    }

    public function canDownload(CommercialProposalExport $export): bool
    {
        return (int) $export->organization_id === $this->organizationId
            && $export->status === 'ready'
            && is_string($export->storage_path)
            && $export->storage_path !== ''
            && $this->isMember
            && ($export->amounts_visible === false || $this->canViewAmounts);
    }
}
