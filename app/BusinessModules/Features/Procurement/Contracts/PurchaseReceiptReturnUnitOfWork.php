<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Contracts;

use Closure;

interface PurchaseReceiptReturnUnitOfWork
{
    public function run(
        int $organizationId,
        string $idempotencyKey,
        Closure $operation,
    ): mixed;
}
