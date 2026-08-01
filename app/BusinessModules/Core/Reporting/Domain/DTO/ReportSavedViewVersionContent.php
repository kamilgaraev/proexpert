<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportSavedViewVersionContent
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    public string $name;

    public string $visibility;

    public ReportFilterSet $filters;

    public array $comparison;

    public ReportWindowSort $sort;

    public array $columns;

    private function __construct(
        public string $reportCode,
        public string $contractVersion,
        ReportSavedViewVersionPresentation $presentation,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $reportCode) !== 1
            || trim($contractVersion) === ''
            || mb_strlen($contractVersion) > 32) {
            throw new InvalidArgumentException('report_saved_view_version_content_invalid');
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
        $this->name = $presentation->name;
        $this->visibility = $presentation->visibility;
        $this->filters = $presentation->filters;
        $this->comparison = $presentation->comparison;
        $this->sort = $presentation->sort;
        $this->columns = $presentation->columns;
    }

    public static function fromPublishedDefinition(
        PublishedReportDefinition $definition,
        ReportSavedViewVersionPresentation $presentation,
    ): self {
        return new self(
            $definition->code,
            $definition->payload()->contractVersion,
            $presentation,
        );
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
            'schema_version',
            'sort',
            'visibility',
        ]
            || ! is_int($content['schema_version'])
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

        if ($content['schema_version'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('report_saved_view_version_schema_unsupported');
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
            new ReportSavedViewVersionPresentation(
                $content['name'],
                $content['visibility'],
                new ReportFilterSet($content['filters']),
                $content['comparison'],
                new ReportWindowSort($content['sort']['field'], $direction),
                $content['columns'],
            ),
        );
    }

    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
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
}
