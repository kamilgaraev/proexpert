<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services;

use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlAmounts;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlMetricRow;
use InvalidArgumentException;

final readonly class ProjectControlFormula
{
    public function calculate(ProjectControlAmounts $amounts): ProjectControlMetricRow
    {
        $eac = $amounts->approvedEtcMinor === null
            ? null
            : $amounts->acMinor + $amounts->approvedEtcMinor;

        return new ProjectControlMetricRow(
            bacMinor: $amounts->bacMinor,
            pvMinor: $amounts->pvMinor,
            evMinor: $amounts->evMinor,
            acMinor: $amounts->acMinor,
            approvedEtcMinor: $amounts->approvedEtcMinor,
            svMinor: $amounts->evMinor - $amounts->pvMinor,
            cvMinor: $amounts->evMinor - $amounts->acMinor,
            spi: $this->ratio($amounts->evMinor, $amounts->pvMinor),
            cpi: $this->ratio($amounts->evMinor, $amounts->acMinor),
            eacMinor: $eac,
            vacMinor: $eac === null ? null : $amounts->bacMinor - $eac,
            tcpi: $eac === null
                ? null
                : $this->ratio($amounts->bacMinor - $amounts->evMinor, $amounts->bacMinor - $amounts->acMinor),
            currency: $amounts->currency,
        );
    }

    public function total(iterable $rows): ProjectControlMetricRow
    {
        $currency = null;
        $bac = 0;
        $pv = 0;
        $ev = 0;
        $ac = 0;
        $etc = 0;
        $hasUnknownEtc = false;
        $seen = false;

        foreach ($rows as $row) {
            if (!$row instanceof ProjectControlMetricRow) {
                throw new InvalidArgumentException('project_control_total_row_invalid');
            }

            $seen = true;
            $currency ??= $row->currency;
            if ($currency !== $row->currency) {
                throw new InvalidArgumentException('project_control_total_currency_mismatch');
            }

            $bac += $row->bacMinor;
            $pv += $row->pvMinor;
            $ev += $row->evMinor;
            $ac += $row->acMinor;
            if ($row->approvedEtcMinor === null) {
                $hasUnknownEtc = true;
            } else {
                $etc += $row->approvedEtcMinor;
            }
        }

        if (!$seen || $currency === null) {
            throw new InvalidArgumentException('project_control_total_empty');
        }

        return $this->calculate(new ProjectControlAmounts(
            $bac,
            $pv,
            $ev,
            $ac,
            $hasUnknownEtc ? null : $etc,
            $currency,
        ));
    }

    private function ratio(int $numerator, int $denominator): ?string
    {
        if ($denominator === 0) {
            return null;
        }

        $negative = ($numerator < 0) !== ($denominator < 0);
        $absoluteDenominator = abs($denominator);
        $scaled = intdiv(
            (abs($numerator) * 100_000_000) + intdiv($absoluteDenominator, 2),
            $absoluteDenominator,
        );
        $ratio = intdiv($scaled, 100_000_000)
            .'.'
            .str_pad((string) ($scaled % 100_000_000), 8, '0', STR_PAD_LEFT);

        return $negative ? '-'.$ratio : $ratio;
    }
}
