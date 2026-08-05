<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;
use JsonException;

final readonly class ReportDefinition
{
    public array $filters;

    public array $columns;

    public array $sorts;

    public array $formats;

    public function __construct(
        public string $code,
        public Sha256Hash $definitionHash,
        public string $contractVersion,
        public string $formulaVersion,
        public string $sourceSchemaVersion,
        public string $rendererVersion,
        array $filters,
        array $columns,
        array $sorts,
        array $formats,
        public ReportPermissionPolicy $permissionPolicy,
        public ReportSnapshotClassification $snapshotClassification,
        public ReportOutputClassification $outputClassification,
        public ReportPublicationReadiness $publicationReadiness,
        public bool $supportsSubscriptions,
        public string $sourceModule,
        public ReportCoreAccessMode $coreAccessMode,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $code) !== 1) {
            throw new InvalidArgumentException('report_code_invalid');
        }

        foreach ([$contractVersion, $formulaVersion, $sourceSchemaVersion, $rendererVersion] as $version) {
            if (trim($version) === '') {
                throw new InvalidArgumentException('report_definition_version_invalid');
            }
        }

        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $sourceModule) !== 1
            || ($coreAccessMode === ReportCoreAccessMode::REPORTING_WORKSPACE && $sourceModule !== 'reports')
            || ($coreAccessMode === ReportCoreAccessMode::SOURCE_MODULE_REPORT && $sourceModule === 'reports')) {
            throw new InvalidArgumentException('report_core_access_contract_invalid');
        }

        $this->filters = self::normalizeItems($filters);
        $this->columns = self::normalizeItems($columns);
        $this->sorts = self::normalizeItems($sorts);
        $this->formats = self::normalizeFormats($formats);
        $columnIds = array_fill_keys(array_column($this->columns, 'id'), true);
        foreach (array_merge(
            $outputClassification->sensitiveColumnIds,
            $outputClassification->auditColumnIds,
        ) as $classifiedColumnId) {
            if (! isset($columnIds[$classifiedColumnId])) {
                throw new InvalidArgumentException('report_output_classification_column_invalid');
            }
        }

        if (in_array($publicationReadiness, [ReportPublicationReadiness::CANDIDATE, ReportPublicationReadiness::PUBLISHED], true)
            && ($this->filters === [] || $this->columns === [] || $this->sorts === [] || $this->formats === [])) {
            throw new InvalidArgumentException('report_definition_collections_required');
        }
    }

    public function validatedSelectedColumnIds(array $columnIds): array
    {
        if (! array_is_list($columnIds)) {
            throw new InvalidArgumentException('report_selected_columns_invalid');
        }

        $definitionColumnIds = array_fill_keys(array_column($this->columns, 'id'), true);
        $seen = [];
        foreach ($columnIds as $columnId) {
            if (! is_string($columnId)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $columnId) !== 1
                || isset($seen[$columnId])
                || ! isset($definitionColumnIds[$columnId])) {
                throw new InvalidArgumentException('report_selected_columns_invalid');
            }
            $seen[$columnId] = true;
        }

        sort($columnIds, SORT_STRING);

        return $columnIds;
    }

    private static function normalizeItems(array $items): array
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('report_definition_items_invalid');
        }

        $normalized = [];
        $ids = [];

        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item) || ! isset($item['id']) || ! is_string($item['id']) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $item['id']) !== 1 || isset($ids[$item['id']])) {
                throw new InvalidArgumentException('report_definition_items_invalid');
            }

            $ids[$item['id']] = true;
            $normalized[] = self::canonicalize($item);
        }

        return $normalized;
    }

    private static function normalizeFormats(array $formats): array
    {
        if (! array_is_list($formats)) {
            throw new InvalidArgumentException('report_definition_formats_invalid');
        }

        $seen = [];

        foreach ($formats as $format) {
            if (! is_string($format) || ! in_array($format, ['csv', 'xlsx', 'pdf'], true) || isset($seen[$format])) {
                throw new InvalidArgumentException('report_definition_formats_invalid');
            }

            $seen[$format] = true;
        }

        return $formats;
    }

    private static function canonicalize(array $value): array
    {
        try {
            return json_decode(CanonicalJson::encode($value), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('report_definition_items_invalid');
        }
    }
}
