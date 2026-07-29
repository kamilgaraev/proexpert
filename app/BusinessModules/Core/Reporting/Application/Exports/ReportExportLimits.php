<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

use InvalidArgumentException;

final readonly class ReportExportLimits
{
    public const ARTIFACT_MAX_BYTES = 524_288_000;

    public function __construct(
        public int $maxRows,
        public int $maxColumns,
        public int $maxBytes,
        public int $maxWorksheetRows,
        public int $maxElapsedSeconds,
        public int $maxChunkRows = 5000,
    ) {
        if ($maxRows < 1
            || $maxColumns < 1
            || $maxBytes < 1
            || $maxWorksheetRows < 1
            || $maxElapsedSeconds < 1
            || $maxChunkRows < 1
            || $maxChunkRows > 5000) {
            throw new InvalidArgumentException('report_export_limits_invalid');
        }
    }

    public static function csv(): self
    {
        return new self(2_000_000, 16_384, self::ARTIFACT_MAX_BYTES, 2_000_002, 3600);
    }

    public static function xlsx(): self
    {
        return new self(1_000_000, 16_384, self::ARTIFACT_MAX_BYTES, 1_048_576, 3600);
    }

    public static function pdf(): self
    {
        return new self(5000, 512, self::ARTIFACT_MAX_BYTES, 5002, 3600);
    }
}
