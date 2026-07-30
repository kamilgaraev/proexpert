<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Contracts\PurchaseReceiptReturnUnitOfWork;
use Closure;
use Illuminate\Support\Facades\DB;

final readonly class DatabasePurchaseReceiptReturnUnitOfWork implements PurchaseReceiptReturnUnitOfWork
{
    public const LOCK_SQL = 'SELECT pg_advisory_xact_lock(hashtextextended(?, ?))';

    public function run(
        int $organizationId,
        string $idempotencyKey,
        Closure $operation,
    ): mixed {
        return DB::transaction(function () use ($organizationId, $idempotencyKey, $operation): mixed {
            DB::selectOne(
                self::LOCK_SQL,
                ['purchase-receipt-return:'.$idempotencyKey, $organizationId],
            );

            return $operation();
        }, 3);
    }
}
