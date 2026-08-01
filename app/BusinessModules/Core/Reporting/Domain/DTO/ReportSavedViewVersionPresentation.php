<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;
use JsonException;

final readonly class ReportSavedViewVersionPresentation
{
    public array $comparison;

    public function __construct(
        public string $name,
        public string $visibility,
        public ReportFilterSet $filters,
        array $comparison,
        public ReportWindowSort $sort,
        public array $columns,
    ) {
        if (trim($name) === ''
            || mb_strlen($name) > 120
            || ! in_array($visibility, ['private', 'organization'], true)
            || ! self::validColumns($columns)) {
            throw new InvalidArgumentException('report_saved_view_version_content_invalid');
        }

        $this->comparison = self::canonicalArray($comparison);
    }

    private static function canonicalArray(array $value): array
    {
        try {
            $canonical = json_decode(CanonicalJson::encode($value), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('report_saved_view_version_content_invalid', 0, $exception);
        }

        if (! is_array($canonical)) {
            throw new InvalidArgumentException('report_saved_view_version_content_invalid');
        }

        return $canonical;
    }

    private static function validColumns(array $columns): bool
    {
        if (! array_is_list($columns) || $columns === []) {
            return false;
        }

        $seen = [];
        foreach ($columns as $column) {
            if (! is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || isset($seen[$column])) {
                return false;
            }
            $seen[$column] = true;
        }

        return true;
    }
}
