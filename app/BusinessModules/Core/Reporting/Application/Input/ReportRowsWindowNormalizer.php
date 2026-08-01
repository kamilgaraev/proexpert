<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Input;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRowsWindow;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;

final class ReportRowsWindowNormalizer
{
    public function normalize(
        ReportDefinition $definition,
        array $input,
    ): ReportRowsWindow {
        if (!$this->hasExactKeys($input, ['cursor', 'limit', 'sort_by', 'sort_dir'])
            || ($input['cursor'] !== null && !is_string($input['cursor']))
            || !is_int($input['limit'])
            || $input['limit'] < 1
            || $input['limit'] > 100
            || !is_string($input['sort_by'])
            || !is_string($input['sort_dir'])) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        if (!$this->definitionHasId($definition->sorts, $input['sort_by'])) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SORT_UNSUPPORTED);
        }

        $direction = ReportSortDirection::tryFrom($input['sort_dir']);

        if ($direction === null) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        return new ReportRowsWindow(
            $input['cursor'],
            $input['limit'],
            new ReportWindowSort($input['sort_by'], $direction),
        );
    }

    private function hasExactKeys(array $input, array $expected): bool
    {
        $actual = array_keys($input);
        sort($actual);
        sort($expected);

        return $actual === $expected;
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
