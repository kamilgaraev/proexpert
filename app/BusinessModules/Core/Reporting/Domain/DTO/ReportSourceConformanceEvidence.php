<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportSourceConformanceEvidence
{
    public array $assertionCodes;

    public function __construct(
        public Sha256Hash $sourceHash,
        public string $snapshotKind,
        public string $snapshotId,
        public int $rowCount,
        public Sha256Hash $rowsHash,
        public bool $passed,
        array $assertionCodes,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $snapshotKind) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $snapshotId) !== 1
            || $rowCount < 0) {
            throw new InvalidArgumentException('report_source_conformance_evidence_invalid');
        }

        $this->assertionCodes = self::normalizeAssertionCodes($assertionCodes, 'source');
        if ($passed !== self::allPassed($this->assertionCodes)) {
            throw new InvalidArgumentException('report_source_conformance_evidence_invalid');
        }
    }

    private static function normalizeAssertionCodes(array $codes, string $group): array
    {
        if (! array_is_list($codes) || $codes === []) {
            throw new InvalidArgumentException('report_source_conformance_evidence_invalid');
        }

        $unique = [];
        foreach ($codes as $code) {
            if (! is_string($code)
                || preg_match('/^'.preg_quote($group, '/').'\.[a-z][a-z0-9_]*\.(?:passed|failed)$/D', $code) !== 1
                || isset($unique[$code])) {
                throw new InvalidArgumentException('report_source_conformance_evidence_invalid');
            }
            $unique[$code] = $code;
        }

        $normalized = array_values($unique);
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    private static function allPassed(array $codes): bool
    {
        foreach ($codes as $code) {
            if (str_ends_with($code, '.failed')) {
                return false;
            }
        }

        return true;
    }
}
