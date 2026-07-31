<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Enums\WaveOneCandidateBindingStatus;

final readonly class WaveOneCandidateBinding
{
    public function __construct(
        public string $code,
        public WaveOneCandidateBindingStatus $status,
        public ?ReportDataProvider $provider,
    ) {}
}
