<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class HoldingAllocationFactsProjected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $contractId,
        public readonly array $factIds,
    ) {
    }
}
