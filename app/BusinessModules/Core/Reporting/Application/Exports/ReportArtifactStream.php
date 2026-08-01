<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

interface ReportArtifactStream
{
    public function write(string $bytes): void;

    public function cancellationRequested(): bool;
}
