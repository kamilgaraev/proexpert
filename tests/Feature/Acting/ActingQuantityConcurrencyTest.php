<?php

declare(strict_types=1);

namespace Tests\Feature\Acting;

use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Services\ActReport\ActReportWorkflowService;
use App\Services\Acting\ActingQuantityReservationService;
use App\Services\Acting\ActingActWizardService;
use App\Services\Acting\ActingAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Symfony\Component\Process\Process;

final class ActingQuantityConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_independent_requests_cannot_reserve_seven_each_from_ten(): void
    {
        [$contract, $work] = $this->fixture();
        DB::commit();
        $connection = config('database.connections.pgsql');
        $raceName = 'acting-reserve-'.bin2hex(random_bytes(5));
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
            'LOG_CHANNEL' => 'stderr', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) $connection['password'],
            'MOST_RACE_NAME' => $raceName,
        ]);
        $workers = [];
        DB::beginTransaction();
        try {
            Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            foreach ([1, 2] as $number) {
                $worker = new Process([
                    PHP_BINARY, base_path('tests/Support/Acting/create_reserved_act_worker.php'),
                    (string) $contract->id, (string) $work->id, (string) $number, (string) $work->user_id,
                ], base_path(), $environment, timeout: 45);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 20;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', $raceName)->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                foreach ($workers as $worker) {
                    if (!$worker->isRunning()) {
                        self::fail($worker->getErrorOutput().$worker->getOutput());
                    }
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['success']), json_encode($results));
            $rejected = array_values(array_filter($results, static fn (array $result): bool => !$result['success']));
            self::assertCount(1, $rejected);
            self::assertSame(422, $rejected[0]['code']);
            self::assertSame(7.0, (float) PerformanceActLine::query()->where('completed_work_id', $work->id)->sum('quantity'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::beginTransaction();
        }
    }

    public function test_second_seven_quantity_attempt_sees_three_after_first_reservation(): void
    {
        [$contract, $work] = $this->fixture();
        $first = $this->act($contract, $work, '7');

        DB::transaction(function () use ($work, $first): void {
            $locked = CompletedWork::query()->whereKey($work->id)->lockForUpdate()->get();
            $available = app(ActingQuantityReservationService::class)->availableQuantities($locked);
            self::assertSame(30000, $available[$work->id]);
            $this->expectException(BusinessLogicException::class);
            app(ActingQuantityReservationService::class)->assertAvailable([$work->id => '7'], $available);
        });
    }

    public function test_draft_metadata_retry_keeps_one_line_and_one_pivot_reservation(): void
    {
        [$contract, $work] = $this->fixture();
        $act = $this->act($contract, $work, '7');
        app(ActReportWorkflowService::class)->update($act, ['description' => 'retry']);
        app(ActReportWorkflowService::class)->update($act->fresh(), ['description' => 'retry']);
        DB::transaction(function () use ($work, $act): void {
            $locked = CompletedWork::query()->whereKey($work->id)->lockForUpdate()->get();
            self::assertSame(100000, app(ActingQuantityReservationService::class)->availableQuantities($locked, $act->id)[$work->id]);
            self::assertSame(30000, app(ActingQuantityReservationService::class)->availableQuantities($locked)[$work->id]);
        });
        self::assertSame(1, $act->lines()->count());
        self::assertSame(1, DB::table('performance_act_completed_works')->where('performance_act_id', $act->id)->count());
        self::assertSame('7.000', (string) DB::table('performance_act_completed_works')->where('performance_act_id', $act->id)->value('included_quantity'));
    }

    public function test_annulment_releases_reservation_once_and_second_retry_is_rejected(): void
    {
        [$contract, $work] = $this->fixture();
        $actor = User::query()->findOrFail($work->user_id);
        $act = $this->act($contract, $work, '7');
        $workflow = app(ActReportWorkflowService::class);
        $workflow->submit($act, $actor->id);
        $workflow->approve($act->fresh(), $actor->id);
        $workflow->annul($act, $actor->id, 'Исправление', 'acting-annul-1');
        self::assertSame(ContractPerformanceAct::STATUS_ANNULLED, $act->fresh()->status);
        $workflow->annul($act->fresh(), $actor->id, 'Исправление', 'acting-annul-1');
        DB::transaction(function () use ($work): void {
            $locked = CompletedWork::query()->whereKey($work->id)->lockForUpdate()->get();
            self::assertSame(100000, app(ActingQuantityReservationService::class)->availableQuantities($locked)[$work->id]);
        });
        $this->expectException(BusinessLogicException::class);
        $workflow->annul($act->fresh(), $actor->id, 'Исправление', 'acting-annul-2');
    }

    public function test_preview_counts_legacy_reservation_and_does_not_double_count_new_lines(): void
    {
        [$contract, $work] = $this->fixture();
        $act = $this->act($contract, $work, '7');
        $availability = app(ActingAvailabilityService::class);
        $rows = $availability->getAvailableWorks($contract->id, '2026-09-01', '2026-09-30');
        self::assertCount(1, $rows);
        self::assertSame(3.0, $rows[0]['available_quantity']);
        $act->lines()->delete();
        $rows = $availability->getAvailableWorks($contract->id, '2026-09-01', '2026-09-30');
        self::assertCount(1, $rows);
        self::assertSame(3.0, $rows[0]['available_quantity']);
        self::assertSame(7.0, $rows[0]['reserved_quantity']);
    }

    public function test_reserved_fact_stays_immutable_even_with_legacy_pending_status(): void
    {
        foreach (['canonical', 'legacy'] as $representation) {
            [$contract, $work] = $this->fixture();
            $act = $this->act($contract, $work, '8');
            $work->forceFill(['status' => CompletedWork::STATUS_PENDING])->saveQuietly();
            if ($representation === 'canonical') {
                DB::table('performance_act_completed_works')->where('performance_act_id', $act->id)->delete();
            } else {
                $act->lines()->delete();
            }
            try {
                app(\App\Services\CompletedWork\CompletedWorkMutationGuard::class)->assertMutable($work->fresh());
                self::fail('An active reservation must protect a fact regardless of legacy status.');
            } catch (BusinessLogicException $exception) {
                self::assertSame(422, $exception->getCode(), $representation);
            }
            self::assertSame('10.0000', $work->fresh()->quantity);
        }
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $contractor = Contractor::create(['organization_id' => $organization->id, 'name' => 'Резерв подрядчик', 'contractor_type' => 'manual']);
        $contract = Contract::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id, 'number' => 'ACT-RACE-'.$organization->id, 'date' => '2026-09-20', 'status' => 'active', 'total_amount' => 1000, 'currency' => 'RUB']);
        $unit = \App\Models\MeasurementUnit::query()->where('organization_id', $organization->id)->firstOrFail();
        $workType = WorkType::create(['organization_id' => $organization->id, 'name' => 'Резервируемая работа', 'measurement_unit_id' => $unit->id]);
        $work = CompletedWork::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'contract_id' => $contract->id, 'work_type_id' => $workType->id, 'user_id' => $user->id, 'quantity' => 10, 'completed_quantity' => 10, 'price' => 50, 'total_amount' => 500, 'work_origin_type' => CompletedWork::ORIGIN_MANUAL, 'planning_status' => CompletedWork::PLANNING_PLANNED, 'completion_date' => '2026-09-20', 'status' => CompletedWork::STATUS_CONFIRMED]);
        return [$contract, $work];
    }

    private function act(Contract $contract, CompletedWork $work, string $quantity): ContractPerformanceAct
    {
        return app(ActingActWizardService::class)->createFromWizard((int) $contract->organization_id, [
            'contract_id' => $contract->id, 'act_document_number' => 'ACT-'.bin2hex(random_bytes(4)),
            'act_date' => '2026-09-20', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'selected_works' => [['completed_work_id' => $work->id, 'quantity' => $quantity]],
        ], (int) $work->user_id, false);
    }
}
