<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;
use JsonException;

final readonly class ReportSavedViewVersionContent
{
    public array $comparison;

    public function __construct(
        public string $reportCode,
        public string $contractVersion,
        public string $name,
        public string $visibility,
        public ReportFilterSet $filters,
        array $comparison,
        public ReportWindowSort $sort,
        public array $columns,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $reportCode) !== 1
            || trim($contractVersion) === ''
            || mb_strlen($contractVersion) > 32
            || trim($name) === ''
            || mb_strlen($name) > 120
            || ! in_array($visibility, ['private', 'organization'], true)
            || ! self::validColumns($columns)) {
            throw new InvalidArgumentException('report_saved_view_version_content_invalid');
        }

        $this->comparison = self::canonicalArray($comparison);
    }

    public static function fromArray(array $content): self
    {
        $keys = array_keys($content);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'columns',
            'comparison',
            'contract_version',
            'filters',
            'name',
            'report_code',
            'sort',
            'visibility',
        ]
            || ! is_string($content['report_code'])
            || ! is_string($content['contract_version'])
            || ! is_string($content['name'])
            || ! is_string($content['visibility'])
            || ! is_array($content['filters'])
            || ! is_array($content['comparison'])
            || ! is_array($content['sort'])
            || ! is_array($content['columns'])) {
            throw new InvalidArgumentException('report_saved_view_version_content_invalid');
        }

        $sortKeys = array_keys($content['sort']);
        sort($sortKeys, SORT_STRING);
        if ($sortKeys !== ['direction', 'field']
            || ! is_string($content['sort']['field'])
            || ! is_string($content['sort']['direction'])) {
            throw new InvalidArgumentException('report_saved_view_version_content_invalid');
        }

        $direction = ReportSortDirection::tryFrom($content['sort']['direction']);
        if (! $direction instanceof ReportSortDirection) {
            throw new InvalidArgumentException('report_saved_view_version_content_invalid');
        }

        return new self(
            $content['report_code'],
            $content['contract_version'],
            $content['name'],
            $content['visibility'],
            new ReportFilterSet($content['filters']),
            $content['comparison'],
            new ReportWindowSort($content['sort']['field'], $direction),
            $content['columns'],
        );
    }

    public function toArray(): array
    {
        return [
            'report_code' => $this->reportCode,
            'contract_version' => $this->contractVersion,
            'name' => $this->name,
            'visibility' => $this->visibility,
            'filters' => $this->filters->values,
            'comparison' => $this->comparison,
            'sort' => [
                'field' => $this->sort->field,
                'direction' => $this->sort->direction->value,
            ],
            'columns' => $this->columns,
        ];
    }

    public function canonicalBytes(): string
    {
        return CanonicalJson::encode($this->toArray());
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
