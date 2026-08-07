<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO;

final readonly class ChangeClaimReadinessSnapshot
{
    public function __construct(
        public int $factCount,
        public bool $hasCheckpoint,
        public bool $historyComplete,
        public array $projectIds = [],
        public array $contractIds = [],
        public array $allocationIds = [],
        public array $changeRequestIds = [],
        public array $claimIds = [],
        public array $statuses = [],
        public array $currencies = [],
        public array $initiatorTypes = [],
        public array $initiatorUserIds = [],
        public array $ownerUserIds = [],
        public array $reasons = [],
        public array $sourceTypes = [],
    ) {}
}
