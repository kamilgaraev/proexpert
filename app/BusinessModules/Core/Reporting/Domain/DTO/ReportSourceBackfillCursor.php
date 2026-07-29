<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportSourceBackfillCursor
{
    public function __construct(public array $position)
    {
        if ($position !== [] && array_is_list($position)) {
            throw new InvalidArgumentException('report_source_backfill_cursor_invalid');
        }
        CanonicalJson::encode($position);
    }

    public static function start(): self
    {
        return new self([]);
    }
}
