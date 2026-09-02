<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export;

use App\BusinessModules\Features\BasicWarehouse\Services\WarehousePersonIdentityResolver;
use App\Models\User;
use DateTimeInterface;

final class WarehouseDocumentPersonResolver
{
    public function __construct(private readonly WarehousePersonIdentityResolver $personIdentityResolver) {}

    public function resolve(?User $user, int $organizationId, DateTimeInterface $documentDate): string
    {
        if ($user === null) {
            return trans_message('warehouse_basic.document_person_not_specified');
        }

        $identity = $this->personIdentityResolver->resolve(
            $organizationId,
            (int) $user->id,
            $documentDate,
        );

        return $identity['name'];
    }
}
