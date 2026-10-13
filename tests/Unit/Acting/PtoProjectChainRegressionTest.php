<?php

declare(strict_types=1);

namespace Tests\Unit\Acting;

use App\BusinessModules\Features\ScheduleManagement\Http\Requests\CreateScheduleFromEstimateRequest;
use App\Models\CompletedWork;
use App\Models\PerformanceActLine;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class PtoProjectChainRegressionTest extends TestCase
{
    public function test_pto_existing_act_line_uses_the_captured_journal_unit(): void
    {
        $work = new CompletedWork(['additional_info' => ['unit_of_measurement' => 'м²']]);
        $line = new PerformanceActLine(['unit' => null, 'basis_snapshot' => ['basis_type' => 'contract_conditions']]);
        $line->setRelation('completedWork', $work);

        self::assertSame('м²', $line->unit);
        self::assertNull($line->getRawOriginal('unit'));
    }

    public function test_pto_stored_and_estimate_snapshot_units_keep_priority_over_journal_unit(): void
    {
        $work = new CompletedWork(['additional_info' => ['unit_of_measurement' => 'м²']]);
        $line = new PerformanceActLine(['unit' => 'м³']);
        $line->setRelation('completedWork', $work);
        self::assertSame('м³', $line->unit);

        $line->unit = null;
        $line->basis_snapshot = ['basis_type' => 'estimate_version', 'estimate_item' => ['measurement_unit' => ['short_name' => 'кг']]];
        self::assertSame('кг', $line->unit);
    }

    public function test_pto_unknown_unit_is_not_guessed_from_a_mutable_work_type(): void
    {
        $line = new PerformanceActLine(['unit' => null]);
        $line->setRelation('completedWork', new CompletedWork(['additional_info' => []]));
        self::assertNull($line->unit);
    }

    public function test_pto_estimate_import_accepts_a_historical_planned_start(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 13)->startOfDay());
        $rules = (new CreateScheduleFromEstimateRequest)->rules();

        self::assertFalse(Validator::make(['start_date' => '2026-10-01'], ['start_date' => $rules['start_date']])->fails());
        self::assertTrue(Validator::make(['start_date' => 'invalid'], ['start_date' => $rules['start_date']])->fails());
    }
}
