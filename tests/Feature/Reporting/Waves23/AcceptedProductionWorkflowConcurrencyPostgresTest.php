<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Waves23;

use App\DTOs\Contract\ContractPerformanceActDTO;
use App\Exceptions\BusinessLogicException;
use App\Models\ContractPerformanceAct;
use App\Services\Acting\ActingPriceService;
use App\Services\ActReport\ActReportNotificationService;
use App\Services\ActReport\ActReportWorkflowService;
use App\Services\Contract\ContractPerformanceActService;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionQuantity;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;
use Throwable;

#[Group('postgresql')]
final class AcceptedProductionWorkflowConcurrencyPostgresTest extends TestCase
{
    private const TABLES = [
        'contract_performance_acts',
        'contracts',
        'contractors',
        'projects',
        'performance_act_lines',
        'performance_act_completed_works',
        'completed_works',
        'work_types',
        'measurement_units',
        'files',
        'estimates',
        'production_acceptance_owner_versions',
        'production_acceptance_owner_members',
        'production_acceptance_events',
        'production_acceptance_backfill_ledger',
    ];

    private ?string $schema = null;

    private ?string $defaultConnection = null;

    private mixed $originalSearchPath = null;

    private ?string $adminConnection = null;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'Accepted production workflow concurrency requires isolated PostgreSQL.',
        );
        $database = (string) DB::selectOne('SELECT current_database() AS name')->name;
        if (preg_match('/(?:_test|_testing)$/D', $database) !== 1) {
            self::markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }

        $this->defaultConnection = (string) config('database.default');
        $connectionConfig = (array) config('database.connections.'.$this->defaultConnection);
        $this->originalSearchPath = $connectionConfig['search_path'] ?? 'public';
        $this->schema = 'accepted_production_race_'.bin2hex(random_bytes(6));
        $this->adminConnection = 'accepted_production_race_admin_'.bin2hex(random_bytes(4));
        config(['database.connections.'.$this->adminConnection => $connectionConfig]);
        try {
            $admin = DB::connection($this->adminConnection);
            $admin->statement('CREATE SCHEMA '.$this->schema);
            $this->installSchema($admin);

            config([
                'database.connections.'.$this->defaultConnection.'.search_path' => $this->schema,
            ]);
            DB::purge($this->defaultConnection);
            self::assertSame($this->schema, (string) DB::selectOne('SELECT current_schema() AS name')->name);
        } catch (Throwable $setupFailure) {
            try {
                $this->cleanupIsolatedSchema();
            } catch (Throwable) {
            }

            throw $setupFailure;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupIsolatedSchema();
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_concurrent_approvals_create_one_exact_owner_and_event(): void
    {
        $this->requireProcessRaceSupport();
        [$actId, $organizationId, $userId] = $this->fixture();
        $this->mockWorkflowCollaborators();

        $harness = $this->harness('approval');
        $children = [];
        $connections = ['accepted_production_approval_lock', 'accepted_production_approval_observer'];
        $failure = null;
        $lock = null;
        try {
            $lock = $harness->independentConnection($connections[0]);
            $observer = $harness->independentConnection($connections[1]);
            $lock->beginTransaction();
            self::assertNotNull($lock->table('contract_performance_acts')
                ->where('id', $actId)
                ->lockForUpdate()
                ->first());

            foreach ([1, 2] as $worker) {
                $children[] = $harness->spawn($worker, static function () use ($actId, $userId): array {
                    $approved = app(ActReportWorkflowService::class)->approve(
                        ContractPerformanceAct::query()->findOrFail($actId),
                        $userId,
                    );

                    return [
                        'approval_date' => (string) $approved->approval_date,
                        'contract_loaded' => $approved->relationLoaded('contract'),
                        'files_loaded' => $approved->relationLoaded('files'),
                        'lines_loaded' => $approved->relationLoaded('lines'),
                        'status' => (string) $approved->status,
                    ];
                });
                $harness->release($worker);
            }
            foreach ([1, 2] as $worker) {
                $harness->waitForPostgresWait(
                    $observer,
                    $harness->waitForWorkerBackendPid($worker),
                );
            }
            $lock->commit();
            $harness->waitForChildren($children, 30.0);
            $children = [];

            $first = $harness->result(1);
            $second = $harness->result(2);
            self::assertSame('approved', $first['status']);
            self::assertSame('approved', $second['status']);
            self::assertSame($first['approval_date'], $second['approval_date']);
            self::assertTrue($first['contract_loaded'] && $first['files_loaded'] && $first['lines_loaded']);
            self::assertTrue($second['contract_loaded'] && $second['files_loaded'] && $second['lines_loaded']);
            $this->assertOneProjection($organizationId, $actId);
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            $this->cleanupRace($harness, $children, $connections, $lock, $failure);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function test_parallel_approval_rejects_the_legacy_act_that_exceeds_completed_quantity(): void
    {
        $this->requireProcessRaceSupport();
        [$firstActId, $organizationId, $userId] = $this->fixture();
        $this->mockWorkflowCollaborators();
        $now = now();
        DB::table('contract_performance_acts')->where('id', $firstActId)->update(['amount' => '600.00']);
        DB::table('performance_act_lines')->where('id', 37)->update([
            'quantity' => '0.6000',
            'amount' => '600.00',
        ]);
        DB::table('contract_performance_acts')->insert([
            'id' => 20,
            'contract_id' => 17,
            'project_id' => 11,
            'act_document_number' => 'LEGACY-OVERBOOK-20',
            'act_date' => $now->toDateString(),
            'amount' => '600.00',
            'currency' => 'RUB',
            'status' => ContractPerformanceAct::STATUS_PENDING_APPROVAL,
            'is_approved' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('performance_act_lines')->insert([
            'id' => 38,
            'performance_act_id' => 20,
            'completed_work_id' => 31,
            'line_type' => 'completed_work',
            'title' => 'Legacy overbooked work',
            'unit' => 'm3',
            'quantity' => '0.6000',
            'unit_price' => '1000.00',
            'amount' => '600.00',
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $harness = $this->harness('legacy-overbook-approval');
        $children = [];
        $connections = ['accepted_production_overbook_lock', 'accepted_production_overbook_observer'];
        $failure = null;
        $lock = null;
        try {
            $lock = $harness->independentConnection($connections[0]);
            $observer = $harness->independentConnection($connections[1]);
            $lock->beginTransaction();
            self::assertNotNull($lock->table('completed_works')
                ->where('id', 31)
                ->lockForUpdate()
                ->first());

            foreach ([$firstActId, 20] as $worker => $actId) {
                $workerId = $worker + 1;
                $children[] = $harness->spawn($workerId, static function () use ($actId, $userId): array {
                    try {
                        $act = app(ActReportWorkflowService::class)->approve(
                            ContractPerformanceAct::query()->findOrFail($actId),
                            $userId,
                        );

                        return ['act_id' => (int) $act->id, 'outcome' => 'approved'];
                    } catch (BusinessLogicException $exception) {
                        return [
                            'act_id' => $actId,
                            'code' => $exception->getCode(),
                            'outcome' => 'rejected',
                        ];
                    }
                });
                $harness->release($workerId);
            }
            foreach ([1, 2] as $workerId) {
                $harness->waitForPostgresWait(
                    $observer,
                    $harness->waitForWorkerBackendPid($workerId),
                );
            }
            $lock->commit();
            $harness->waitForChildren($children, 30.0);
            $children = [];

            $results = [$harness->result(1), $harness->result(2)];
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => $result['outcome'] === 'approved',
            ));
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => ($result['code'] ?? null) === 422
                    && $result['outcome'] === 'rejected',
            ));
            self::assertSame(1, DB::table('production_acceptance_events')
                ->where('organization_id', $organizationId)
                ->where('work_id', 31)
                ->where('event_type', 'accepted')
                ->count());
            self::assertSame(6_000, $this->workBasisTotal($organizationId, 'accepted_quantity_delta'));
            self::assertSame([
                ContractPerformanceAct::STATUS_APPROVED,
                ContractPerformanceAct::STATUS_PENDING_APPROVAL,
            ], DB::table('contract_performance_acts')
                ->whereIn('id', [$firstActId, 20])
                ->orderBy('status')
                ->pluck('status')
                ->sort()
                ->values()
                ->all());
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            $this->cleanupRace($harness, $children, $connections, $lock, $failure);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function test_submit_queued_after_approval_cannot_reopen_the_locked_act(): void
    {
        $this->requireProcessRaceSupport();
        [$actId, $organizationId, $userId] = $this->fixture();
        $this->mockWorkflowCollaborators();

        $harness = $this->harness('submit-approval');
        $children = [];
        $connections = ['accepted_production_submit_lock', 'accepted_production_submit_observer'];
        $failure = null;
        $lock = null;
        try {
            $lock = $harness->independentConnection($connections[0]);
            $observer = $harness->independentConnection($connections[1]);
            $lock->beginTransaction();
            self::assertNotNull($lock->table('contract_performance_acts')
                ->where('id', $actId)
                ->lockForUpdate()
                ->first());

            $children[] = $harness->spawn(1, static function () use ($actId, $userId): array {
                $approved = app(ActReportWorkflowService::class)->approve(
                    ContractPerformanceAct::query()->findOrFail($actId),
                    $userId,
                );

                return ['outcome' => 'approved', 'status' => (string) $approved->status];
            });
            $harness->release(1);
            $harness->waitForPostgresWait($observer, $harness->waitForWorkerBackendPid(1));

            $children[] = $harness->spawn(2, static function () use ($actId, $userId): array {
                try {
                    app(ActReportWorkflowService::class)->submit(
                        ContractPerformanceAct::query()->findOrFail($actId),
                        $userId,
                    );

                    return ['outcome' => 'submitted'];
                } catch (BusinessLogicException $exception) {
                    return ['code' => $exception->getCode(), 'outcome' => 'rejected'];
                }
            });
            $harness->release(2);
            $harness->waitForPostgresWait($observer, $harness->waitForWorkerBackendPid(2));

            $lock->commit();
            $harness->waitForChildren($children, 30.0);
            $children = [];

            self::assertSame(['outcome' => 'approved', 'status' => 'approved'], $harness->result(1));
            self::assertSame(['code' => 423, 'outcome' => 'rejected'], $harness->result(2));
            self::assertSame(
                ContractPerformanceAct::STATUS_APPROVED,
                DB::table('contract_performance_acts')->where('id', $actId)->value('status'),
            );
            $this->assertOneProjection($organizationId, $actId);
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            $this->cleanupRace($harness, $children, $connections, $lock, $failure);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function test_two_direct_act_requests_cannot_reserve_more_than_completed_quantity(): void
    {
        $this->requireProcessRaceSupport();
        $this->fixture();
        $this->reservationWorkFixture();

        $harness = $this->harness('direct-reservation');
        $children = [];
        $connections = ['accepted_production_reservation_lock', 'accepted_production_reservation_observer'];
        $failure = null;
        $lock = null;
        try {
            $lock = $harness->independentConnection($connections[0]);
            $observer = $harness->independentConnection($connections[1]);
            $lock->beginTransaction();
            self::assertNotNull($lock->table('completed_works')
                ->where('id', 32)
                ->lockForUpdate()
                ->first());

            foreach ([1, 2] as $worker) {
                $children[] = $harness->spawn($worker, static function () use ($worker): array {
                    try {
                        $act = app(ContractPerformanceActService::class)->createActForContract(
                            17,
                            3,
                            new ContractPerformanceActDTO(
                                project_id: 11,
                                act_document_number: 'DIRECT-RACE-'.$worker,
                                act_date: now()->toDateString(),
                                description: null,
                                is_approved: false,
                                completed_works: [[
                                    'completed_work_id' => 32,
                                    'included_quantity' => '0.600',
                                    'included_amount' => '600.00',
                                ]],
                                currency: 'RUB',
                                completedWorksProvided: true,
                            ),
                            11,
                        );

                        return ['act_id' => (int) $act->id, 'outcome' => 'reserved'];
                    } catch (BusinessLogicException $exception) {
                        return ['code' => $exception->getCode(), 'outcome' => 'rejected'];
                    }
                });
                $harness->release($worker);
            }
            foreach ([1, 2] as $worker) {
                $harness->waitForPostgresWait(
                    $observer,
                    $harness->waitForWorkerBackendPid($worker),
                );
            }
            $lock->commit();
            $harness->waitForChildren($children, 30.0);
            $children = [];

            $results = [$harness->result(1), $harness->result(2)];
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => $result['outcome'] === 'reserved',
            ));
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => $result === ['code' => 422, 'outcome' => 'rejected'],
            ));
            self::assertSame(1, DB::table('performance_act_completed_works')
                ->where('completed_work_id', 32)
                ->count());
            self::assertSame(
                '0.600',
                (string) DB::table('performance_act_completed_works')
                    ->where('completed_work_id', 32)
                    ->value('included_quantity'),
            );
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            $this->cleanupRace($harness, $children, $connections, $lock, $failure);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function test_explicit_empty_completed_works_patch_releases_all_draft_reservations(): void
    {
        $this->fixture();
        $this->reservationWorkFixture();
        $service = app(ContractPerformanceActService::class);
        $act = $service->createActForContract(
            17,
            3,
            new ContractPerformanceActDTO(
                project_id: 11,
                act_document_number: 'DIRECT-EMPTY-PATCH',
                act_date: now()->toDateString(),
                description: null,
                is_approved: false,
                completed_works: [[
                    'completed_work_id' => 32,
                    'included_quantity' => '0.500',
                    'included_amount' => '500.00',
                ]],
                currency: 'RUB',
                completedWorksProvided: true,
            ),
            11,
        );
        self::assertSame(1, DB::table('performance_act_completed_works')
            ->where('performance_act_id', $act->id)
            ->count());

        $updated = $service->updateAct(
            (int) $act->id,
            17,
            3,
            new ContractPerformanceActDTO(
                project_id: 11,
                act_document_number: null,
                act_date: '',
                description: null,
                is_approved: false,
                completed_works: [],
                completedWorksProvided: true,
                partialUpdate: true,
                providedFields: ['completed_works'],
            ),
            11,
        );

        self::assertSame(0, DB::table('performance_act_completed_works')
            ->where('performance_act_id', $act->id)
            ->count());
        self::assertSame('0.00', number_format((float) $updated->amount, 2, '.', ''));
    }

    public function test_accept_reverse_and_reaccept_conserve_one_work_basis(): void
    {
        [$actId, $organizationId, $userId] = $this->fixture();
        $recorder = app(ProductionAcceptanceEventRecorder::class);
        $act = ContractPerformanceAct::query()->findOrFail($actId);
        $firstDay = CarbonImmutable::parse('2026-08-01T10:00:00+03:00');

        $recorder->recordTransition($act, 'pending', 'approved', $firstDay, $userId);
        $recorder->recordTransition($act, 'approved', 'reopened', $firstDay->addDay(), $userId);
        $recorder->recordTransition($act, 'pending', 'approved', $firstDay->addDays(2), $userId);

        $events = DB::table('production_acceptance_events')
            ->where('organization_id', $organizationId)
            ->where('work_id', 31)
            ->orderBy('id')
            ->get();
        self::assertCount(3, $events);
        self::assertSame(10_000, $this->quantityTotal($events, 'planned_quantity'));
        self::assertSame(10_000, $this->quantityTotal($events, 'reported_quantity'));
        self::assertSame(10_000, $this->quantityTotal($events, 'accepted_quantity_delta'));
    }

    public function test_reversal_clears_the_recorded_basis_after_completed_work_changes(): void
    {
        [$actId, $organizationId, $userId] = $this->fixture();
        $recorder = app(ProductionAcceptanceEventRecorder::class);
        $firstDay = CarbonImmutable::parse('2026-08-01T10:00:00+03:00');

        $recorder->recordTransition(
            ContractPerformanceAct::query()->findOrFail($actId),
            'pending',
            'approved',
            $firstDay,
            $userId,
        );
        DB::table('completed_works')->where('id', 31)->update([
            'quantity' => '1.200',
            'completed_quantity' => '1.2000',
            'updated_at' => now(),
        ]);
        $recorder->recordTransition(
            ContractPerformanceAct::query()->findOrFail($actId),
            'approved',
            'reopened',
            $firstDay->addDay(),
            $userId,
        );

        self::assertSame(0, $this->workBasisTotal($organizationId, 'planned_quantity'));
        self::assertSame(0, $this->workBasisTotal($organizationId, 'reported_quantity'));
        self::assertSame(0, $this->workBasisTotal($organizationId, 'accepted_quantity_delta'));
    }

    public function test_approval_rejects_quantity_already_accepted_by_an_approved_act_without_events(): void
    {
        [, $organizationId, $userId] = $this->fixture();
        $now = now();
        DB::table('contract_performance_acts')->where('id', 19)->update([
            'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
            'approval_date' => $now->toDateString(),
            'updated_at' => $now,
        ]);
        DB::table('contract_performance_acts')->insert([
            'id' => 41,
            'contract_id' => 17,
            'project_id' => 11,
            'act_document_number' => 'ACCEPT-GAP-41',
            'act_date' => $now->toDateString(),
            'amount' => '100.00',
            'currency' => 'RUB',
            'status' => ContractPerformanceAct::STATUS_PENDING_APPROVAL,
            'is_approved' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('performance_act_lines')->insert([
            'id' => 43,
            'performance_act_id' => 41,
            'completed_work_id' => 31,
            'line_type' => 'completed_work',
            'title' => 'Acceptance after coverage gap',
            'unit' => 'm3',
            'quantity' => '0.1000',
            'unit_price' => '1000.00',
            'amount' => '100.00',
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            app(ProductionAcceptanceEventRecorder::class)->recordTransition(
                ContractPerformanceAct::query()->findOrFail(41),
                'pending',
                'approved',
                CarbonImmutable::parse('2026-08-02T10:00:00+03:00'),
                $userId,
            );
            self::fail('Approval beyond the completed quantity must be rejected.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }

        self::assertSame(0, DB::table('production_acceptance_events')
            ->where('organization_id', $organizationId)
            ->count());
        self::assertSame(0, DB::table('production_acceptance_owner_versions')->count());
    }

    public function test_partial_sources_and_manual_lines_preserve_valid_events_and_one_basis(): void
    {
        [$actId, $organizationId, $userId] = $this->fixture();
        $now = now();
        DB::table('performance_act_lines')->where('id', 37)->update([
            'quantity' => '0.4000',
            'amount' => '400.00',
        ]);
        DB::table('performance_act_lines')->insert([
            'id' => 38,
            'performance_act_id' => $actId,
            'line_type' => 'manual',
            'title' => 'Manual context line',
            'quantity' => '1.0000',
            'amount' => '0.00',
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('contract_performance_acts')->insert([
            'id' => 20,
            'contract_id' => 17,
            'project_id' => 11,
            'act_document_number' => 'ACCEPT-PARTIAL-20',
            'act_date' => $now->toDateString(),
            'amount' => '600.00',
            'currency' => 'RUB',
            'status' => ContractPerformanceAct::STATUS_PENDING_APPROVAL,
            'is_approved' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('performance_act_lines')->insert([
            'id' => 39,
            'performance_act_id' => 20,
            'completed_work_id' => 31,
            'line_type' => 'completed_work',
            'title' => 'Accepted production partial line',
            'unit' => 'm3',
            'quantity' => '0.6000',
            'unit_price' => '1000.00',
            'amount' => '600.00',
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $recorder = app(ProductionAcceptanceEventRecorder::class);
        $firstAct = ContractPerformanceAct::query()->findOrFail($actId);
        $secondAct = ContractPerformanceAct::query()->findOrFail(20);
        $firstDay = CarbonImmutable::parse('2026-08-01T10:00:00+03:00');
        $recorder->recordTransition($firstAct, 'pending', 'approved', $firstDay, $userId);
        self::assertSame(1, DB::table('production_acceptance_events')
            ->where('performance_act_id', $actId)
            ->count());
        self::assertSame(1, DB::table('production_acceptance_owner_members')
            ->where('performance_act_id', $actId)
            ->count());

        $recorder->recordTransition($secondAct, 'pending', 'approved', $firstDay->addDay(), $userId);
        self::assertSame(10_000, $this->workBasisTotal($organizationId, 'planned_quantity'));
        $recorder->recordTransition($firstAct, 'approved', 'reopened', $firstDay->addDays(2), $userId);
        self::assertSame(10_000, $this->workBasisTotal($organizationId, 'planned_quantity'));
        $recorder->recordTransition($secondAct, 'approved', 'reopened', $firstDay->addDays(3), $userId);
        self::assertSame(0, $this->workBasisTotal($organizationId, 'planned_quantity'));
        $recorder->recordTransition($secondAct, 'pending', 'approved', $firstDay->addDays(4), $userId);

        self::assertSame(10_000, $this->workBasisTotal($organizationId, 'planned_quantity'));
        self::assertSame(10_000, $this->workBasisTotal($organizationId, 'reported_quantity'));
        self::assertSame(6_000, $this->workBasisTotal($organizationId, 'accepted_quantity_delta'));
    }

    public function test_mixed_canonical_and_pivot_only_sources_are_both_recorded_without_duplicates(): void
    {
        [$actId, $organizationId, $userId] = $this->fixture();
        $this->reservationWorkFixture();
        $now = now();
        DB::table('performance_act_completed_works')->insert([
            [
                'performance_act_id' => $actId,
                'completed_work_id' => 31,
                'included_quantity' => '1.000',
                'included_amount' => '1000.00',
                'currency' => 'RUB',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'performance_act_id' => $actId,
                'completed_work_id' => 32,
                'included_quantity' => '0.500',
                'included_amount' => '500.00',
                'currency' => 'RUB',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        app(ProductionAcceptanceEventRecorder::class)->recordTransition(
            ContractPerformanceAct::query()->findOrFail($actId),
            'pending',
            'approved',
            CarbonImmutable::parse('2026-08-01T10:00:00+03:00'),
            $userId,
        );

        self::assertSame([
            ['performance_act_line', 37, 31],
            ['completed_work', 32, 32],
        ], DB::table('production_acceptance_events')
            ->where('organization_id', $organizationId)
            ->where('performance_act_id', $actId)
            ->orderBy('source_line_type', 'desc')
            ->get(['source_line_type', 'source_line_id', 'work_id'])
            ->map(static fn ($event): array => [
                (string) $event->source_line_type,
                (int) $event->source_line_id,
                (int) $event->work_id,
            ])
            ->all());
        self::assertSame(2, DB::table('production_acceptance_owner_members')
            ->where('organization_id', $organizationId)
            ->where('performance_act_id', $actId)
            ->count());
        self::assertSame(0, DB::table('production_acceptance_events')
            ->where('organization_id', $organizationId)
            ->where('performance_act_id', $actId)
            ->where('source_line_type', 'completed_work')
            ->where('work_id', 31)
            ->count());
    }

    private function installSchema(ConnectionInterface $admin): void
    {
        $schema = $this->schema
            ?? throw new RuntimeException('Accepted production race schema is not initialized.');
        foreach (self::TABLES as $table) {
            $admin->statement(
                'CREATE TABLE '.$schema.'.'.$table.' (LIKE public.'.$table.' INCLUDING ALL)',
            );
        }
        foreach ([
            'production_acceptance_owner_versions',
            'production_acceptance_owner_members',
            'production_acceptance_events',
            'production_acceptance_backfill_ledger',
        ] as $table) {
            $sequence = $table.'_id_seq';
            $admin->statement('CREATE SEQUENCE '.$schema.'.'.$sequence);
            $admin->statement(
                'ALTER TABLE '.$schema.'.'.$table
                .' ALTER COLUMN id SET DEFAULT nextval(\''.$schema.'.'.$sequence.'\'::regclass)',
            );
        }
    }

    private function fixture(): array
    {
        $now = now();
        DB::table('projects')->insert([
            'id' => 11,
            'organization_id' => 3,
            'name' => 'Acceptance race project',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('contractors')->insert([
            'id' => 13,
            'organization_id' => 3,
            'name' => 'Acceptance race contractor',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('contracts')->insert([
            'id' => 17,
            'organization_id' => 3,
            'project_id' => 11,
            'contractor_id' => 13,
            'number' => 'ACCEPT-RACE-17',
            'date' => $now->toDateString(),
            'total_amount' => '1000.00',
            'currency' => 'RUB',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('contract_performance_acts')->insert([
            'id' => 19,
            'contract_id' => 17,
            'project_id' => 11,
            'act_document_number' => 'ACCEPT-RACE-19',
            'act_date' => $now->toDateString(),
            'amount' => '1000.00',
            'currency' => 'RUB',
            'status' => ContractPerformanceAct::STATUS_PENDING_APPROVAL,
            'is_approved' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('measurement_units')->insert([
            'id' => 23,
            'organization_id' => 3,
            'name' => 'Race cubic meter',
            'short_name' => 'm3',
            'type' => 'work',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('work_types')->insert([
            'id' => 29,
            'organization_id' => 3,
            'name' => 'Race concrete',
            'code' => 'ACCEPT-RACE-29',
            'measurement_unit_id' => 23,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('completed_works')->insert([
            'id' => 31,
            'organization_id' => 3,
            'project_id' => 11,
            'contract_id' => 17,
            'work_type_id' => 29,
            'user_id' => 7,
            'quantity' => '1.000',
            'completed_quantity' => '1.0000',
            'price' => '1000.00',
            'total_amount' => '1000.00',
            'completion_date' => $now->toDateString(),
            'status' => 'confirmed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('performance_act_lines')->insert([
            'id' => 37,
            'performance_act_id' => 19,
            'completed_work_id' => 31,
            'line_type' => 'completed_work',
            'title' => 'Accepted production race line',
            'unit' => 'm3',
            'quantity' => '1.0000',
            'unit_price' => '1000.00',
            'amount' => '1000.00',
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [19, 3, 7];
    }

    private function reservationWorkFixture(): void
    {
        $now = now();
        DB::table('completed_works')->insert([
            'id' => 32,
            'organization_id' => 3,
            'project_id' => 11,
            'contract_id' => 17,
            'work_type_id' => 29,
            'user_id' => 7,
            'quantity' => '1.000',
            'completed_quantity' => '1.0000',
            'price' => '1000.00',
            'total_amount' => '1000.00',
            'completion_date' => $now->toDateString(),
            'status' => 'confirmed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function mockWorkflowCollaborators(): void
    {
        $this->mock(ActingPriceService::class)
            ->shouldReceive('resolveLineUnitPrice')
            ->andReturn(1000.0);
        $this->mock(ActReportNotificationService::class)->shouldIgnoreMissing();
    }

    private function workBasisTotal(int $organizationId, string $column): int
    {
        return $this->quantityTotal(
            DB::table('production_acceptance_events')
                ->where('organization_id', $organizationId)
                ->where('work_id', 31)
                ->orderBy('id')
                ->get(),
            $column,
        );
    }

    private function quantityTotal(iterable $events, string $column): int
    {
        $total = 0;
        foreach ($events as $event) {
            $total += AcceptedProductionQuantity::scaled(
                (string) $event->{$column},
                'test_quantity_invalid',
            );
        }

        return $total;
    }

    private function assertOneProjection(int $organizationId, int $actId): void
    {
        self::assertSame(1, DB::table('production_acceptance_owner_versions')
            ->where('organization_id', $organizationId)
            ->where('performance_act_id', $actId)
            ->count());
        self::assertSame(1, DB::table('production_acceptance_events')
            ->where('organization_id', $organizationId)
            ->where('performance_act_id', $actId)
            ->count());
        self::assertSame(0, DB::table('production_acceptance_backfill_ledger')
            ->where('organization_id', $organizationId)
            ->where('performance_act_id', $actId)
            ->count());
    }

    private function harness(string $name): PostgresProcessRaceHarness
    {
        return new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'accepted-production-'.$name.'-race-'.bin2hex(random_bytes(6)),
        );
    }

    private function requireProcessRaceSupport(): void
    {
        if (function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('posix_kill')) {
            return;
        }
        if (getenv('CI') === 'true') {
            self::fail('CI accepted-production race gate requires pcntl and posix.');
        }

        $this->markTestSkipped('Requires pcntl and posix for a real PostgreSQL process race.');
    }

    private function cleanupRace(
        PostgresProcessRaceHarness $harness,
        array $children,
        array $connections,
        ?ConnectionInterface $lock,
        ?Throwable &$failure,
    ): void {
        if ($lock !== null && $lock->transactionLevel() > 0) {
            $harness->cleanupStep(static fn () => $lock->rollBack(), $failure);
        }
        $harness->cleanupStep(fn () => $harness->terminateAndReap($children), $failure);
        foreach ($connections as $connection) {
            $harness->cleanupStep(static fn () => DB::purge($connection), $failure);
        }
        $harness->cleanupStep(fn () => $harness->cleanup(), $failure);
    }

    private function cleanupIsolatedSchema(): void
    {
        if ($this->defaultConnection !== null) {
            DB::purge($this->defaultConnection);
            config([
                'database.connections.'.$this->defaultConnection.'.search_path' => $this->originalSearchPath,
            ]);
        }
        if ($this->schema !== null
            && $this->adminConnection !== null
            && preg_match('/^accepted_production_race_[a-f0-9]{12}$/D', $this->schema) === 1
        ) {
            try {
                DB::connection($this->adminConnection)->statement('DROP SCHEMA '.$this->schema.' CASCADE');
            } finally {
                DB::purge($this->adminConnection);
            }
        }

        $this->schema = null;
        $this->adminConnection = null;
    }
}
