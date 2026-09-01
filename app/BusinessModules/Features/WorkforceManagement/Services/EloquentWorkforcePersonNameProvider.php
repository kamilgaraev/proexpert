<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\WorkforceManagement\Contracts\WorkforcePersonNameProvider;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

final class EloquentWorkforcePersonNameProvider implements WorkforcePersonNameProvider
{
    public function employeeNameAt(int $organizationId, int $userId, DateTimeInterface $date): ?string
    {
        $documentDate = $date->format('Y-m-d');
        $employee = WorkforceEmployee::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereDate('hire_date', '<=', $documentDate)
            ->where(function (Builder $query) use ($documentDate): void {
                $query->whereNull('dismissal_date')
                    ->orWhereDate('dismissal_date', '>=', $documentDate);
            })
            ->orderByDesc('hire_date')
            ->orderByDesc('id')
            ->first();
        $fullName = trim((string) ($employee?->full_name ?? ''));

        return $fullName !== '' ? $fullName : null;
    }
}
