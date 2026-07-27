<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportSourceRef
{
    public function __construct(
        public string $source,
        public string $snapshotKind,
        public string $snapshotId,
        public string $schemaVersion,
        public string $watermark,
        public int $rowCount,
        public Sha256Hash $hash,
    ) {
        foreach ([$source, $snapshotKind, $snapshotId, $schemaVersion, $watermark] as $identifier) {
            if (!self::isSafeIdentifier($identifier) || preg_match('/(?:query|secret|password|token|email|sql)/i', $identifier) === 1) {
                throw new InvalidArgumentException('report_source_ref_invalid');
            }
        }

        if ($rowCount < 0) {
            throw new InvalidArgumentException('report_source_ref_invalid');
        }
    }

    private static function isSafeIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1;
    }
}
