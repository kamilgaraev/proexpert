<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportCandidateValidationItem
{
    public array $failureCodes;

    public function __construct(
        public string $code,
        public Sha256Hash $definitionHash,
        public bool $passed,
        array $failureCodes,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $code) !== 1 || !array_is_list($failureCodes)) {
            throw new InvalidArgumentException('report_candidate_validation_item_invalid');
        }

        $normalized = [];
        foreach ($failureCodes as $failureCode) {
            if (!is_string($failureCode) || preg_match('/^[A-Z][A-Z0-9_]{2,127}$/', $failureCode) !== 1 || isset($normalized[$failureCode])) {
                throw new InvalidArgumentException('report_candidate_validation_item_invalid');
            }
            $normalized[$failureCode] = $failureCode;
        }
        sort($normalized, SORT_STRING);

        if (($passed && $normalized !== []) || (!$passed && $normalized === [])) {
            throw new InvalidArgumentException('report_candidate_validation_item_invalid');
        }

        $this->failureCodes = array_values($normalized);
    }
}
