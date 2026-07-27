<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Input;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use DateTimeZone;
use Exception;

final class ReportExportNormalizer
{
    public function normalize(
        ReportDefinition $definition,
        array $input,
    ): CreateReportExportData {
        if (!$this->hasExactKeys($input, ['format', 'columns', 'sort_by', 'sort_dir', 'locale', 'timezone'])
            || !is_string($input['format'])
            || !is_array($input['columns'])
            || !is_string($input['sort_by'])
            || !is_string($input['sort_dir'])
            || !is_string($input['locale'])
            || !is_string($input['timezone'])) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        if (!in_array($input['format'], $definition->formats, true)) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_FILTER_UNSUPPORTED,
                ['fields' => 'format'],
            );
        }

        if (!$this->columnsAreValid($input['columns'])) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        if (!$this->columnsAreSupported($definition, $input['columns'])) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_FILTER_UNSUPPORTED,
                ['fields' => 'columns'],
            );
        }

        if (!$this->definitionHasId($definition->sorts, $input['sort_by'])) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SORT_UNSUPPORTED);
        }

        $direction = ReportSortDirection::tryFrom($input['sort_dir']);

        if ($direction === null) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        try {
            $timezone = new DateTimeZone($input['timezone']);
            return new CreateReportExportData(
                $input['format'],
                $input['columns'],
                new ReportWindowSort($input['sort_by'], $direction),
                $input['locale'],
                $timezone,
            );
        } catch (Exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }
    }

    private function hasExactKeys(array $input, array $expected): bool
    {
        $actual = array_keys($input);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    private function columnsAreValid(array $columns): bool
    {
        if (!array_is_list($columns) || $columns === [] || count($columns) !== count(array_unique($columns, SORT_REGULAR))) {
            return false;
        }

        foreach ($columns as $column) {
            if (!is_string($column) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $column) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function columnsAreSupported(ReportDefinition $definition, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!is_string($column) || !$this->definitionHasId($definition->columns, $column)) {
                return false;
            }
        }

        return true;
    }

    private function definitionHasId(array $items, string $id): bool
    {
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return true;
            }
        }

        return false;
    }
}
