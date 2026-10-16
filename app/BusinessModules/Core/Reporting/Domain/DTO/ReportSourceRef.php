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
        foreach (['source' => $source, 'snapshot_kind' => $snapshotKind, 'snapshot_id' => $snapshotId, 'schema_version' => $schemaVersion, 'watermark' => $watermark] as $field => $identifier) {
            if (!self::isSafeIdentifier($identifier, $field === 'watermark' ? 128 : 64) || preg_match('/(?:query|secret|password|token|email|sql)/i', $identifier) === 1) {
                throw new InvalidArgumentException('report_source_ref_invalid');
            }
        }

        if ($rowCount < 0 || strlen($watermark) > 128) {
            throw new InvalidArgumentException('report_source_ref_invalid');
        }
    }

    private static function isSafeIdentifier(string $value, int $maxLength): bool
    {
        return preg_match('/^(?:[a-z][a-z0-9_]{0,'.($maxLength - 1).'}|v[0-9]+_[0-9]+_[0-9]+|[0-9]+\\.[0-9]+\\.[0-9]+)$/', $value) === 1;
    }
}
