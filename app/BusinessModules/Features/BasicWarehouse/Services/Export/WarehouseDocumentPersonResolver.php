<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export;

use App\BusinessModules\Features\WorkforceManagement\Contracts\WorkforcePersonNameProvider;
use App\Models\User;
use DateTimeInterface;

final class WarehouseDocumentPersonResolver
{
    public function __construct(private readonly WorkforcePersonNameProvider $personNameProvider) {}

    public function resolve(?User $user, int $organizationId, DateTimeInterface $documentDate): string
    {
        if ($user === null) {
            return trans_message('warehouse_basic.document_person_not_specified');
        }

        $fullName = $this->personNameProvider->employeeNameAt(
            $organizationId,
            (int) $user->id,
            $documentDate,
        );

        return $fullName !== null
            ? $fullName
            : trans_message('warehouse_basic.document_person_not_specified');
    }
}
