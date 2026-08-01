<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportFormulaConformanceEvidence
{
    public array $assertionCodes;

    public function __construct(
        public string $formulaVersion,
        public Sha256Hash $totalsHash,
        public bool $passed,
        array $assertionCodes,
    ) {
        if (trim($formulaVersion) === '' || ! array_is_list($assertionCodes) || $assertionCodes === []) {
            throw new InvalidArgumentException('report_formula_conformance_evidence_invalid');
        }

        $unique = [];
        foreach ($assertionCodes as $code) {
            if (! is_string($code)
                || preg_match('/^formula\.[a-z][a-z0-9_]*\.(?:passed|failed)$/D', $code) !== 1
                || isset($unique[$code])) {
                throw new InvalidArgumentException('report_formula_conformance_evidence_invalid');
            }
            $unique[$code] = $code;
        }
        $normalized = array_values($unique);
        sort($normalized, SORT_STRING);
        $this->assertionCodes = $normalized;

        $allPassed = true;
        foreach ($this->assertionCodes as $code) {
            if (str_ends_with($code, '.failed')) {
                $allPassed = false;
                break;
            }
        }
        if ($passed !== $allPassed) {
            throw new InvalidArgumentException('report_formula_conformance_evidence_invalid');
        }
    }
}
