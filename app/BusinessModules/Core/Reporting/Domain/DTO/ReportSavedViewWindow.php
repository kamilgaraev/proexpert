<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportSavedViewWindow
{
    public function __construct(public ?string $cursor, public int $limit, public ?string $reportCode)
    {
        if ($limit < 1 || $limit > 100 || ($reportCode !== null && preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $reportCode) !== 1)) {
            throw new InvalidArgumentException('report_saved_view_window_invalid');
        }
    }
}
