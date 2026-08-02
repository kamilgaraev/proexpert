<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO;

final readonly class ProjectControlMetricRow
{
    public function __construct(
        public int $bacMinor,
        public int $pvMinor,
        public int $evMinor,
        public int $acMinor,
        public ?int $approvedEtcMinor,
        public int $svMinor,
        public int $cvMinor,
        public ?string $spi,
        public ?string $cpi,
        public ?int $eacMinor,
        public ?int $vacMinor,
        public ?string $tcpi,
        public string $currency,
    ) {
    }

    public function toArray(bool $canViewSensitive = true): array
    {
        $row = [
            'bac_minor' => $this->bacMinor,
            'pv_minor' => $this->pvMinor,
            'ev_minor' => $this->evMinor,
            'sv_minor' => $this->svMinor,
            'spi' => $this->spi,
            'currency' => $this->currency,
        ];

        if ($canViewSensitive) {
            $row += [
                'ac_minor' => $this->acMinor,
                'approved_etc_minor' => $this->approvedEtcMinor,
                'cv_minor' => $this->cvMinor,
                'cpi' => $this->cpi,
                'eac_minor' => $this->eacMinor,
                'vac_minor' => $this->vacMinor,
                'tcpi' => $this->tcpi,
            ];
        }

        return $row;
    }
}
