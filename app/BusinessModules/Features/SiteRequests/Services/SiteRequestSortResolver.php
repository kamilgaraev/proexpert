<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Services;

final class SiteRequestSortResolver
{
    private const COLUMNS = [
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'deadline' => 'required_date',
        'priority' => 'priority',
    ];

    public function column(string $requested): string
    {
        return self::COLUMNS[$requested] ?? 'created_at';
    }

    public function direction(string $requested): string
    {
        return in_array($requested, ['asc', 'desc'], true) ? $requested : 'desc';
    }
}
