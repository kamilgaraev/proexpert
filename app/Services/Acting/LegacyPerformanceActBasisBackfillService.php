<?php

declare(strict_types=1);

namespace App\Services\Acting;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class LegacyPerformanceActBasisBackfillService
{
    public function backfill(): int
    {
        if (DB::getDriverName() === 'pgsql') {
            $affected = DB::affectingStatement(<<<'SQL'
                UPDATE performance_act_lines
                SET basis_snapshot = jsonb_build_object(
                    'basis_type', 'legacy_act_line',
                    'completed_work_id', completed_work_id,
                    'base_unit_price', CASE
                        WHEN COALESCE(unit_price, 0) > 0 THEN unit_price
                        WHEN quantity > 0 THEN ROUND(amount / quantity, 2)
                        ELSE 0
                    END,
                    'unit_price_with_vat', CASE
                        WHEN COALESCE(unit_price, 0) > 0 THEN unit_price
                        WHEN quantity > 0 THEN ROUND(amount / quantity, 2)
                        ELSE 0
                    END,
                    'vat_rate', 0,
                    'legacy_amount', amount
                )
                WHERE basis_snapshot IS NULL
                SQL);
        } else {
            $affected = 0;
            DB::table('performance_act_lines')
                ->whereNull('basis_snapshot')
                ->orderBy('id')
                ->chunkById(500, static function ($lines) use (&$affected): void {
                    foreach ($lines as $line) {
                        $unitPrice = BigDecimal::of((string) ($line->unit_price ?? 0));
                        if ($unitPrice->isLessThanOrEqualTo(0) && BigDecimal::of((string) $line->quantity)->isGreaterThan(0)) {
                            $unitPrice = BigDecimal::of((string) $line->amount)
                                ->dividedBy(BigDecimal::of((string) $line->quantity), 2, RoundingMode::HalfUp);
                        }
                        DB::table('performance_act_lines')->where('id', $line->id)->update([
                            'basis_snapshot' => json_encode([
                                'basis_type' => 'legacy_act_line',
                                'completed_work_id' => $line->completed_work_id,
                                'base_unit_price' => (string) $unitPrice,
                                'unit_price_with_vat' => (string) $unitPrice,
                                'vat_rate' => '0.00',
                                'legacy_amount' => (string) $line->amount,
                            ], JSON_THROW_ON_ERROR),
                        ]);
                        $affected++;
                    }
                });
        }

        DB::table('contract_performance_acts')->update([
            'vat_rate' => 0,
            'vat_amount' => 0,
            'amount_without_vat' => DB::raw('amount'),
        ]);

        return $affected;
    }
}
