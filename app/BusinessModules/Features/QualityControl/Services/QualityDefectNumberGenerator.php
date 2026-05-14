<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Services;

use App\BusinessModules\Features\QualityControl\Models\QualityDefect;

final class QualityDefectNumberGenerator
{
    public function generate(int $organizationId): string
    {
        $prefix = 'QD-' . now()->format('Ym') . '-';
        $count = QualityDefect::query()
            ->where('organization_id', $organizationId)
            ->where('defect_number', 'like', $prefix . '%')
            ->count();

        return $prefix . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}
