<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;

final class ExecutiveDocumentNumberGenerator
{
    public function generateSetNumber(int $organizationId): string
    {
        $prefix = 'ED-' . now()->format('Ym') . '-';
        $count = ExecutiveDocumentSet::query()
            ->where('organization_id', $organizationId)
            ->where('set_number', 'like', $prefix . '%')
            ->count();

        return $prefix . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}
