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
        $this->assertConcurrentReservations(false);
    }

    public function test_two_requests_share_technical_limit_even_when_physical_fact_is_larger(): void
    {
        $this->assertConcurrentReservations(true);
    }

    private function assertConcurrentReservations(bool $technicalAcceptanceOnly): void
    {
        [$contract, $work] = $this->fixture();
        if ($technicalAcceptanceOnly) {
            $work->update(['quantity' => 20, 'completed_quantity' => 20, 'total_amount' => 1000]);
            $scope = \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope::query()->create([
                'organization_id' => $contract->organization_id, 'project_id' => $contract->project_id,
                'created_by_user_id' => $work->user_id, 'title' => 'Частичная приёмка', 'status' => 'accepted',
            ]);
            $scope->workQuantities()->create([
                'organization_id' => $contract->organization_id, 'project_id' => $contract->project_id,
                'completed_work_id' => $work->id, 'unit_id' => $work->workType->measurement_unit_id,
                'presented_quantity' => '20', 'accepted_quantity' => '10', 'defect_quantity' => '10', 'defect_reason' => 'Требуется устранение замечания',
            ]);
            \App\Models\ActingPolicy::query()->create([
                'organization_id' => $contract->organization_id, 'contract_id' => $contract->id,
                'mode' => \App\Models\ActingPolicy::MODE_OPERATIONAL,
                'settings' => ['technical_acceptance' => ['mode' => 'accepted_only']],
            ]);
            $available = app(ActingAvailabilityService::class)->getAvailableWorks($contract->id, '2026-09-01', '2026-09-30');
            self::assertSame(10.0, $available[0]['available_quantity']);
        }
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
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            try {
                $this->deleteCommittedRaceFixture($contract, $work);
            } finally {
                DB::beginTransaction();
            }
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

    #[\PHPUnit\Framework\Attributes\DataProvider('activePaymentStatuses')]
    public function test_completed_payment_ledger_blocks_annulment_even_when_invoice_snapshot_is_stale(string $ledgerStatus): void
    {
        [$contract, $work] = $this->fixture();
        $act = $this->act($contract, $work, '7');
        $workflow = app(ActReportWorkflowService::class);
        $workflow->submit($act, $work->user_id);
        $act = $workflow->approve($act->fresh(), $work->user_id);
        $invoice = $this->invoice($contract, $act);
        $this->payment($invoice, $work->user_id, '100', status: $ledgerStatus);

        try {
            $workflow->annul($act, $work->user_id, 'Исправление', 'paid-ledger-annul');
            self::fail('A completed ledger payment must block annulment despite a stale invoice snapshot.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        self::assertSame(ContractPerformanceAct::STATUS_APPROVED, $act->fresh()->status);
        self::assertSame(0, \App\Models\PerformanceActReversal::query()->where('performance_act_id', $act->id)->count());
        self::assertSame('10.0000', $work->fresh()->quantity);
        self::assertSame(3.0, app(ActingAvailabilityService::class)->getAvailableWorks($contract->id, '2026-09-01', '2026-09-30')[0]['available_quantity']);
    }

    public function test_signed_act_and_fully_refunded_payment_keep_history_when_annulled(): void
    {
        [$contract, $work] = $this->fixture();
        $act = $this->act($contract, $work, '7');
        $workflow = app(ActReportWorkflowService::class);
        $workflow->submit($act, $work->user_id);
        $act = $workflow->approve($act->fresh(), $work->user_id);
        $file = \App\Models\File::query()->create([
            'organization_id' => $contract->organization_id, 'fileable_type' => ContractPerformanceAct::class,
            'fileable_id' => $act->id, 'user_id' => $work->user_id, 'name' => 'signed.pdf',
            'original_name' => 'signed.pdf', 'path' => 'tests/signed.pdf', 'mime_type' => 'application/pdf',
            'size' => 100, 'disk' => 's3', 'type' => 'document', 'category' => 'signed_act',
        ]);
        $act = $workflow->markSigned($act, $file->id, $work->user_id);
        $lines = $act->lines()->get()->toArray();
        $signedAt = $act->signed_at->toISOString();
        $rows = app(ActingAvailabilityService::class)->getAvailableWorks($contract->id, '2026-09-01', '2026-09-30');
        self::assertSame(7.0, $rows[0]['approved_acted_quantity']);
        self::assertSame(0.0, $rows[0]['reserved_quantity']);
        self::assertSame(3.0, $rows[0]['available_quantity']);

        $invoice = $this->invoice($contract, $act);
        $paid = $this->payment($invoice, $work->user_id, '100');
        $this->payment($invoice, $work->user_id, '-100', $paid->id);
        $annulled = $workflow->annul($act, $work->user_id, 'После полного возврата', 'refunded-annul');
        self::assertSame(ContractPerformanceAct::STATUS_ANNULLED, $annulled->status);
        self::assertSame($file->id, $annulled->signed_file_id);
        self::assertSame($signedAt, $annulled->signed_at->toISOString());
        self::assertSame($lines, $annulled->lines()->get()->toArray());
        self::assertSame(2, $invoice->transactions()->count());
        self::assertSame('10.0000', $work->fresh()->quantity);
        self::assertSame(10.0, app(ActingAvailabilityService::class)->getAvailableWorks($contract->id, '2026-09-01', '2026-09-30')[0]['available_quantity']);
    }

    private function invoice(Contract $contract, ContractPerformanceAct $act): \App\BusinessModules\Core\Payments\Models\PaymentDocument
    {
        return \App\BusinessModules\Core\Payments\Models\PaymentDocument::query()->create([
            'organization_id' => $contract->organization_id, 'project_id' => $contract->project_id,
            'document_type' => 'invoice', 'document_number' => 'T06-'.$act->id, 'document_date' => '2026-09-20',
            'direction' => 'outgoing', 'invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $act->id,
            'amount' => 350, 'paid_amount' => 0, 'remaining_amount' => 350, 'currency' => 'RUB', 'status' => 'approved',
        ]);
    }

    public static function activePaymentStatuses(): array
    {
        return [['completed'], ['pending'], ['processing']];
    }

    private function payment(\App\BusinessModules\Core\Payments\Models\PaymentDocument $invoice, int $actorId, string $amount, ?int $reverses = null, string $status = 'completed'): \App\BusinessModules\Core\Payments\Models\PaymentTransaction
    {
        return \App\BusinessModules\Core\Payments\Models\PaymentTransaction::query()->create([
            'payment_document_id' => $invoice->id, 'organization_id' => $invoice->organization_id,
            'project_id' => $invoice->project_id, 'amount' => $amount, 'currency' => 'RUB',
            'payment_method' => 'bank_transfer', 'transaction_date' => '2026-09-20', 'status' => $status,
            'created_by_user_id' => $actorId, 'reverses_transaction_id' => $reverses,
        ]);
    }

    public function test_preview_api_returns_the_same_remaining_quantity_as_the_reservation_guard(): void
    {
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        foreach ([\App\Domain\Authorization\Services\ModulePermissionChecker::class, \App\Domain\Authorization\Services\PermissionResolver::class, \App\Domain\Authorization\Services\AuthorizationService::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $context = \Tests\Support\AdminApiTestContext::create();
        [$contract, $work] = $this->fixture($context);
        $this->act($contract, $work, '7');
        $response = $this->postJson('/api/v1/admin/act-reports/preview', [
            'contract_id' => $contract->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        ], $context->authHeaders())->assertOk();
        $row = $response->json('data.available_works.0');
        self::assertSame($work->id, $row['id']);
        self::assertEquals(7, $row['reserved_quantity']);
        self::assertEquals(0, $row['approved_acted_quantity']);
        self::assertEquals(3, $row['available_quantity']);
        DB::transaction(function () use ($work, $row): void {
            $locked = CompletedWork::query()->whereKey($work->id)->lockForUpdate()->get();
            self::assertSame((int) round($row['available_quantity'] * 10000), app(ActingQuantityReservationService::class)->availableQuantities($locked)[$work->id]);
        });
    }

    public function test_submit_and_approve_recheck_technical_acceptance_after_policy_change(): void
    {
        foreach (['submit', 'approve'] as $action) {
            [$contract, $work] = $this->fixture();
            $act = $this->act($contract, $work, '7');
            $workflow = app(ActReportWorkflowService::class);
            if ($action === 'approve') {
                $workflow->submit($act, $work->user_id);
            }
            \App\Models\ActingPolicy::query()->create([
                'organization_id' => $contract->organization_id, 'contract_id' => $contract->id,
                'mode' => \App\Models\ActingPolicy::MODE_OPERATIONAL,
                'settings' => ['technical_acceptance' => ['mode' => 'accepted_only']],
            ]);
            try {
                $workflow->{$action}($act->fresh(), $work->user_id);
                self::fail('Act must not pass without technically accepted work');
            } catch (BusinessLogicException $exception) {
                self::assertSame(422, $exception->getCode());
            }
            self::assertFalse((bool) $act->fresh()->is_approved);
            self::assertSame($action === 'submit' ? 'draft' : 'pending_approval', $act->fresh()->status);
        }
    }

    private function fixture(?\Tests\Support\AdminApiTestContext $context = null): array
    {
        $organization = $context?->organization ?? Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = $context?->user ?? User::factory()->create(['current_organization_id' => $organization->id]);
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

    private function deleteCommittedRaceFixture(Contract $contract, CompletedWork $work): void
    {
        $workId = (int) $work->id;
        $contractId = (int) $contract->id;
        $projectId = (int) $contract->project_id;
        $contractorId = (int) $contract->contractor_id;
        $workTypeId = (int) $work->work_type_id;
        $userId = (int) $work->user_id;
        $organizationId = (int) $contract->organization_id;
        $actIds = DB::table('contract_performance_acts')->where('contract_id', $contractId)->pluck('id');
        if ($actIds->isNotEmpty()) {
            DB::table('performance_act_lines')->whereIn('performance_act_id', $actIds)->delete();
            DB::table('performance_act_completed_works')->whereIn('performance_act_id', $actIds)->delete();
            DB::table('performance_act_reversals')->whereIn('performance_act_id', $actIds)->delete();
            DB::table('contract_performance_acts')->whereIn('id', $actIds)->delete();
        }
        $scopeIds = DB::table('acceptance_scopes')->where('project_id', $projectId)->pluck('id');
        if ($scopeIds->isNotEmpty()) {
            DB::table('acceptance_scope_work_quantity_operations')->whereIn('acceptance_scope_id', $scopeIds)->delete();
            DB::table('acceptance_scope_work_quantities')->whereIn('acceptance_scope_id', $scopeIds)->delete();
            DB::table('acceptance_scopes')->whereIn('id', $scopeIds)->delete();
        }
        DB::table('acting_policies')->where('contract_id', $contractId)->delete();
        $viewIds = DB::table('contract_organization_views')->where('contract_id', $contractId)->pluck('id');
        if ($viewIds->isNotEmpty()) {
            DB::table('contract_organization_view_events')->whereIn('view_id', $viewIds)->delete();
            DB::table('contract_organization_views')->whereIn('id', $viewIds)->delete();
        }
        DB::table('activity_events')->where('organization_id', $organizationId)->delete();
        DB::table('contract_state_events')->where('contract_id', $contractId)->delete();
        DB::table('contract_project')->where('contract_id', $contractId)->delete();
        DB::table('completed_works')->where('id', $workId)->delete();
        DB::table('contracts')->where('id', $contractId)->delete();
        DB::table('contractors')->where('id', $contractorId)->delete();
        DB::table('work_types')->where('id', $workTypeId)->delete();
        DB::table('project_user')->where('project_id', $projectId)->delete();
        DB::table('project_organization')->where('project_id', $projectId)->delete();
        DB::table('projects')->where('id', $projectId)->delete();
        DB::table('measurement_units')->where('organization_id', $organizationId)->delete();
        DB::table('organization_user')->where('organization_id', $organizationId)->delete();
        DB::table('users')->where('id', $userId)->delete();
        DB::table('organizations')->where('id', $organizationId)->delete();
    }
}
