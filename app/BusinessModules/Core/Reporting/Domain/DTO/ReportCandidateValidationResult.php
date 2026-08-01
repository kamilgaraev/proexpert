<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use InvalidArgumentException;

final readonly class ReportCandidateValidationResult
{
    public array $items;

    public function __construct(array $items)
    {
        $indexed = [];
        foreach ($items as $item) {
            if (! $item instanceof ReportCandidateValidationItem || isset($indexed[$item->code])) {
                throw new InvalidArgumentException('report_candidate_validation_result_invalid');
            }
            $indexed[$item->code] = $item;
        }
        $this->items = array_values($indexed);
    }

    public function passed(): bool
    {
        foreach ($this->items as $item) {
            if (! $item->passed) {
                return false;
            }
        }

        return true;
    }

    public function item(string $code): ReportCandidateValidationItem
    {
        foreach ($this->items as $item) {
            if ($item->code === $code) {
                return $item;
            }
        }
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }
}
