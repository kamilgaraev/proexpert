<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BudgetingReportSourceWatermark
{
    public function __construct(
        public string $source,
        public DateTimeImmutable $cutoffAt,
        public string $watermark,
        public string $sourceSchemaVersion,
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,127}$/', $this->source)
            || trim($this->watermark) === ''
            || trim($this->sourceSchemaVersion) === '') {
            throw new InvalidArgumentException('budgeting_report_source_watermark_invalid');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'cutoff_at' => $this->cutoffAt->format(DATE_ATOM),
            'watermark' => $this->watermark,
            'source_schema_version' => $this->sourceSchemaVersion,
        ];
    }
}
