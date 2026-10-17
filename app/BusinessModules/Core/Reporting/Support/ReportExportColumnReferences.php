<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Support;

final class ReportExportColumnReferences
{
    public static function all(array $columns, string $sortField): array
    {
        return array_values(array_unique([...$columns, $sortField]));
    }
}
