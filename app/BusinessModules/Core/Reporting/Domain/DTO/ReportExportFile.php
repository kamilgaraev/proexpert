<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use Closure;

final readonly class ReportExportFile
{
    public function __construct(public string $filename, public string $mimeType, public Closure $writeToOutput) {}
}
