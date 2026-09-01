<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

interface WorkforcePersonNameProvider
{
    public function employeeNameAt(int $organizationId, int $userId, DateTimeInterface $date): ?string;

    /**
     * @param  array<int, array{user_id: int, date: DateTimeInterface}>  $references
     * @return array<int, string|null>
     */
    public function employeeNamesAt(int $organizationId, array $references): array;

    public function orWhereEmployeeNameMatches(
        Builder $query,
        int $organizationId,
        string $search,
        string $userIdColumn,
        string $dateColumn,
    ): void;
}
