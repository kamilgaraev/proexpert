<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessDimensionSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTransition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\EloquentProcurementProcessEventStore;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementEventIdempotencyGuard;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Procurement\Reporting\ProcurementCyclePostgresFixture;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;
use Throwable;

#[Group('postgresql')]
final class ProcurementCycleSourcePostgresTest extends TestCase
{
    use RefreshDatabase;

    private ?array $fixtureCache = null;

    private int $sourceSequence = 1000;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('PROCUREMENT_CYCLE_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped(
                'Set PROCUREMENT_CYCLE_POSTGRES_TESTS=1 to run isolated PostgreSQL procurement-cycle tests.',
            );
        }

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires an explicitly configured isolated PostgreSQL database.');
        }

        $database = config('database.connections.pgsql.database');
        if (! is_string($database) || preg_match('/_(?:test|testing)$/D', $database) !== 1) {
            $this->markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }

        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_database_rejects_process_event_update(): void
    {
        $eventId = $this->insertRawEvent($this->rawEvent($this->fixture()['a']));
        $before = DB::table('procurement_process_events')->where('id', $eventId)->first();

        $exception = $this->captureQueryException(static function () use ($eventId): void {
            DB::table('procurement_process_events')->where('id', $eventId)->update([
                'payload_hash' => str_repeat('f', 64),
            ]);
        });

        $this->assertSqlState($exception, '55000');
        $after = DB::table('procurement_process_events')->where('id', $eventId)->first();
        self::assertSame($before?->payload_hash, $after?->payload_hash);
        self::assertSame($before?->dimension_snapshot, $after?->dimension_snapshot);
        self::assertSame(1, DB::table('procurement_process_events')->where('id', $eventId)->count());
    }

    public function test_database_rejects_process_event_delete(): void
    {
        $eventId = $this->insertRawEvent($this->rawEvent($this->fixture()['a']));
        $before = DB::table('procurement_process_events')->where('id', $eventId)->first();

        $exception = $this->captureQueryException(static function () use ($eventId): void {
            DB::table('procurement_process_events')->where('id', $eventId)->delete();
        });

        $this->assertSqlState($exception, '55000');
        $after = DB::table('procurement_process_events')->where('id', $eventId)->first();
        self::assertSame($before?->payload_hash, $after?->payload_hash);
        self::assertSame($before?->dimension_snapshot, $after?->dimension_snapshot);
        self::assertSame(1, DB::table('procurement_process_events')->where('id', $eventId)->count());
    }

    public function test_database_rejects_policy_version_update(): void
    {
        $policy = $this->fixture()['policy_a'];
        $before = DB::table('procurement_cycle_policy_versions')->where('id', $policy['id'])->first();

        $exception = $this->captureQueryException(static function () use ($policy): void {
            DB::table('procurement_cycle_policy_versions')->where('id', $policy['id'])->update([
                'canonical_hash' => str_repeat('f', 64),
            ]);
        });

        $this->assertSqlState($exception, '55000');
        $after = DB::table('procurement_cycle_policy_versions')->where('id', $policy['id'])->first();
        self::assertSame($before?->canonical_hash, $after?->canonical_hash);
        self::assertSame(1, DB::table('procurement_cycle_policy_versions')->where('id', $policy['id'])->count());
    }

    public function test_database_rejects_policy_version_delete(): void
    {
        $policy = $this->fixture()['policy_a'];
        $before = DB::table('procurement_cycle_policy_versions')->where('id', $policy['id'])->first();

        $exception = $this->captureQueryException(static function () use ($policy): void {
            DB::table('procurement_cycle_policy_versions')->where('id', $policy['id'])->delete();
        });

        $this->assertSqlState($exception, '55000');
        $after = DB::table('procurement_cycle_policy_versions')->where('id', $policy['id'])->first();
        self::assertSame($before?->canonical_hash, $after?->canonical_hash);
        self::assertSame(1, DB::table('procurement_cycle_policy_versions')->where('id', $policy['id'])->count());
    }

    public function test_database_accepts_cancelled_reason_allowed_by_pinned_policy(): void
    {
        $chain = $this->fixture()['a'];
        $this->insertRawEvent($this->rawEvent($chain));
        $cancelled = $this->rawEvent($chain, [
            'event_code' => ProcurementProcessEventCode::CANCELLED->value,
            'terminal_reason' => 'request_cancelled',
            'occurred_at' => '2026-08-01 10:05:00+00',
        ]);

        $eventId = $this->insertRawEvent($cancelled);

        self::assertSame('request_cancelled', DB::table('procurement_process_events')
            ->where('id', $eventId)
            ->value('terminal_reason'));
    }

    public function test_database_rejects_cancelled_reason_not_allowed_by_pinned_policy(): void
    {
        $chain = $this->fixture()['a'];
        $this->insertRawEvent($this->rawEvent($chain));
        $cancelled = $this->rawEvent($chain, [
            'event_code' => ProcurementProcessEventCode::CANCELLED->value,
            'terminal_reason' => 'order_cancelled',
            'occurred_at' => '2026-08-01 10:05:00+00',
        ]);

        $exception = $this->captureQueryException(static function () use ($cancelled): void {
            DB::table('procurement_process_events')->insert($cancelled);
        });

        $this->assertSqlState($exception, '23514');
        self::assertStringContainsString('terminal reason is not allowed', $exception->getMessage());
        self::assertSame(0, $this->eventCount($cancelled));
    }

    public function test_database_rejects_unpinned_cancelled_event(): void
    {
        $chain = $this->fixture()['no_project'];
        $this->insertRawEvent($this->rawEvent($chain));
        $cancelled = $this->rawEvent($chain, [
            'event_code' => ProcurementProcessEventCode::CANCELLED->value,
            'terminal_reason' => 'request_cancelled',
            'occurred_at' => '2026-08-01 10:05:00+00',
        ]);

        $exception = $this->captureQueryException(static function () use ($cancelled): void {
            DB::table('procurement_process_events')->insert($cancelled);
        });

        $this->assertSqlState($exception, '23514');
        self::assertSame(0, $this->eventCount($cancelled));
    }

    public function test_database_rejects_unpinned_request_rejected_cancellation(): void
    {
        $chain = $this->fixture()['no_project'];
        $this->insertRawEvent($this->rawEvent($chain));
        $cancelled = $this->rawEvent($chain, [
            'event_code' => ProcurementProcessEventCode::CANCELLED->value,
            'terminal_reason' => 'request_rejected',
            'occurred_at' => '2026-08-01 10:05:00+00',
        ]);

        $exception = $this->captureQueryException(static function () use ($cancelled): void {
            DB::table('procurement_process_events')->insert($cancelled);
        });

        $this->assertSqlState($exception, '23514');
        self::assertSame(0, $this->eventCount($cancelled));
    }

    public function test_database_accepts_one_coherent_full_procurement_lineage(): void
    {
        $chain = $this->fixture()['a'];
        $event = $this->rawEvent($chain);

        $eventId = $this->insertRawEvent($event);
        $persisted = DB::table('procurement_process_events')->where('id', $eventId)->first();

        self::assertSame($chain['supplier_request_id'], (int) $persisted?->supplier_request_id);
        self::assertSame($chain['supplier_proposal_version_id'], (int) $persisted?->supplier_proposal_version_id);
        self::assertSame($chain['purchase_order_item_id'], (int) $persisted?->purchase_order_item_id);
        self::assertSame($chain['purchase_receipt_line_id'], (int) $persisted?->purchase_receipt_line_id);
        self::assertSame($event['payload_hash'], $persisted?->payload_hash);
    }

    public function test_database_accepts_positive_direct_order_null_proposal_chain(): void
    {
        $chain = $this->fixture()['direct_order'];
        $requestCreated = $this->cleanRequestCreatedEvent($chain);
        $requestCreatedId = $this->insertRawEvent($requestCreated);
        $persistedRequestCreated = DB::table('procurement_process_events')
            ->where('id', $requestCreatedId)
            ->first();
        $requestCreatedDimensions = json_decode(
            (string) $persistedRequestCreated?->dimension_snapshot,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ProcurementProcessEventCode::REQUEST_CREATED->value,
            $persistedRequestCreated?->event_code,
        );
        self::assertSame($chain['project_id'], (int) $persistedRequestCreated?->project_id);
        self::assertSame($chain['policy']['id'], (int) $persistedRequestCreated?->policy_version_id);
        self::assertNull($persistedRequestCreated?->supplier_party_id);
        self::assertNull($persistedRequestCreated?->supplier_request_id);
        self::assertNull($persistedRequestCreated?->supplier_proposal_id);
        self::assertNull($persistedRequestCreated?->supplier_proposal_version_id);
        self::assertNull($persistedRequestCreated?->purchase_order_id);
        self::assertNull($persistedRequestCreated?->purchase_order_item_id);
        self::assertIsArray($requestCreatedDimensions);
        self::assertArrayNotHasKey('supplier_party_id', $requestCreatedDimensions);
        self::assertArrayNotHasKey('awarded_supplier_party_id', $requestCreatedDimensions);
        self::assertArrayNotHasKey('awarded_amount', $requestCreatedDimensions);

        $event = $this->rawEvent($chain, [
            'event_code' => ProcurementProcessEventCode::ORDER_SENT->value,
            'occurred_at' => '2026-08-01 10:05:00+00',
        ]);

        $eventId = $this->insertRawEvent($event);
        $persisted = DB::table('procurement_process_events')->where('id', $eventId)->first();

        self::assertSame(ProcurementProcessEventCode::ORDER_SENT->value, $persisted?->event_code);
        self::assertSame($persistedRequestCreated?->project_id, $persisted?->project_id);
        self::assertSame($persistedRequestCreated?->policy_version_id, $persisted?->policy_version_id);
        self::assertSame($persistedRequestCreated?->policy_hash, $persisted?->policy_hash);
        self::assertSame($persistedRequestCreated?->calendar_version, $persisted?->calendar_version);
        self::assertSame($persistedRequestCreated?->calendar_hash, $persisted?->calendar_hash);
        self::assertSame($chain['supplier_party_id'], (int) $persisted?->supplier_party_id);
        self::assertNull($persisted?->supplier_proposal_id);
        self::assertNull($persisted?->supplier_proposal_version_id);
        self::assertSame($chain['purchase_order_id'], (int) $persisted?->purchase_order_id);
        self::assertSame($chain['purchase_order_item_id'], (int) $persisted?->purchase_order_item_id);
    }

    #[DataProvider('partialSupplierProposalPairCases')]
    public function test_database_rejects_partial_supplier_proposal_version_pair(string $field): void
    {
        $event = $this->rawEvent($this->fixture()['a']);
        $event[$field] = null;

        $exception = $this->captureQueryException(static function () use ($event): void {
            DB::table('procurement_process_events')->insert($event);
        });

        $this->assertSqlState($exception, '23514');
        self::assertSame(0, $this->eventCount($event));
    }

    public static function partialSupplierProposalPairCases(): array
    {
        return [
            'proposal without immutable version' => ['supplier_proposal_version_id'],
            'version without proposal' => ['supplier_proposal_id'],
        ];
    }

    public function test_database_accepts_strict_quarantine_without_request_created(): void
    {
        $event = $this->strictQuarantineEvent($this->fixture()['a']);
        $eventId = $this->insertRawEvent($event);
        $persisted = DB::table('procurement_process_events')->where('id', $eventId)->first();

        self::assertNull($persisted?->project_id);
        self::assertNull($persisted?->policy_version_id);
        self::assertSame(
            json_decode($event['dimension_snapshot'], true, flags: JSON_THROW_ON_ERROR),
            json_decode((string) $persisted?->dimension_snapshot, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_database_rejects_current_project_reconstruction_without_request_created(): void
    {
        $chain = $this->fixture()['a'];
        $event = $this->strictQuarantineEvent($chain, $chain['project_id']);

        $exception = $this->captureQueryException(static function () use ($event): void {
            DB::table('procurement_process_events')->insert($event);
        });

        $this->assertSqlState($exception, '23514');
        self::assertStringContainsString('quarantine provenance required', $exception->getMessage());
        self::assertSame(0, $this->eventCount($event));
    }

    #[DataProvider('partialPolicyPinCases')]
    public function test_database_rejects_partial_policy_pins(string $field): void
    {
        $event = $this->rawEvent($this->fixture()['a']);
        $event[$field] = null;

        $exception = $this->captureQueryException(static function () use ($event): void {
            DB::table('procurement_process_events')->insert($event);
        });

        $this->assertSqlState($exception, '23514');
        self::assertSame(0, $this->eventCount($event));
    }

    public static function partialPolicyPinCases(): array
    {
        return [
            'missing policy hash' => ['policy_hash'],
            'missing calendar version' => ['calendar_version'],
            'missing calendar hash' => ['calendar_hash'],
        ];
    }

    #[DataProvider('malformedQuarantineGapCases')]
    public function test_database_rejects_malformed_or_incomplete_quarantine_gap_codes(string $case): void
    {
        $event = $this->strictQuarantineEvent($this->fixture()['a']);
        $snapshot = json_decode($event['dimension_snapshot'], true, flags: JSON_THROW_ON_ERROR);
        if ($case === 'missing') {
            unset($snapshot['gap_codes']);
        } elseif ($case === 'null') {
            $snapshot['gap_codes'] = null;
        } elseif ($case === 'object') {
            $snapshot['gap_codes'] = ['unexpected' => true];
        } elseif ($case === 'incomplete') {
            $snapshot['gap_codes'] = [
                'missing_request_created_event',
                'missing_project_lineage',
            ];
        } else {
            throw new RuntimeException("Unknown malformed quarantine gap case: {$case}");
        }
        $event['dimension_snapshot'] = json_encode($snapshot, JSON_THROW_ON_ERROR);

        $exception = $this->captureQueryException(static function () use ($event): void {
            DB::table('procurement_process_events')->insert($event);
        });

        $this->assertSqlState($exception, '23514');
        self::assertSame(0, $this->eventCount($event));
    }

    public static function malformedQuarantineGapCases(): array
    {
        return [
            'missing gap_codes' => ['missing'],
            'JSON null gap_codes' => ['null'],
            'object gap_codes' => ['object'],
            'incomplete gap_codes' => ['incomplete'],
        ];
    }

    #[DataProvider('invalidFullLineageCases')]
    public function test_database_rejects_crossed_tenant_project_or_optional_lineage(
        string $case,
        string $expectedMessage,
    ): void {
        [$chain, $overrides] = $this->invalidLineageMutation($case, $this->fixture());
        $event = $this->rawEvent($chain, $overrides);

        $exception = $this->captureQueryException(static function () use ($event): void {
            DB::table('procurement_process_events')->insert($event);
        });

        $this->assertSqlState($exception, '23514');
        self::assertStringContainsString($expectedMessage, $exception->getMessage());
        self::assertSame(0, $this->eventCount($event));
    }

    public static function invalidFullLineageCases(): array
    {
        return [
            'event organization differs from request' => ['organization_request', 'event lineage mismatch'],
            'different project in same organization' => ['project_same_organization', 'project lineage mismatch'],
            'project from another organization' => ['project_other_organization', 'project lineage mismatch'],
            'null event project for project request' => ['project_null', 'project lineage mismatch'],
            'project event for unassigned request' => ['request_project_null', 'project lineage mismatch'],
            'policy scope differs from event scope' => ['policy_scope', 'policy pin mismatch'],
            'policy organization differs from event scope' => ['policy_organization', 'policy pin mismatch'],
            'supplier request belongs to another request' => ['supplier_request', 'supplier request lineage mismatch'],
            'supplier request line belongs to another line' => ['supplier_request_line', 'supplier request line lineage mismatch'],
            'supplier party differs from supplier request' => ['supplier_request_party', 'supplier request lineage mismatch'],
            'supplier party differs from proposal' => ['proposal_party', 'supplier proposal lineage mismatch'],
            'supplier party differs from order' => ['order_party', 'order lineage mismatch'],
            'proposal belongs to another supplier request' => ['proposal_request', 'supplier proposal lineage mismatch'],
            'proposal version belongs to another proposal' => ['proposal_version', 'proposal version lineage mismatch'],
            'decision points to another winner' => ['decision_winner', 'proposal decision lineage mismatch'],
            'order accepts another proposal version' => ['order_acceptance', 'order lineage mismatch'],
            'order item belongs to another order and request line' => ['order_item', 'order item lineage mismatch'],
            'order item proposal line belongs to another proposal' => ['order_item_proposal_line', 'proposal line lineage mismatch'],
            'receipt belongs to another order' => ['receipt', 'receipt lineage mismatch'],
            'receipt line belongs to another receipt and item' => ['receipt_line', 'receipt line lineage mismatch'],
        ];
    }

    public function test_store_exact_replay_is_a_noop(): void
    {
        $transition = $this->transition($this->fixture()['a'], 5001, '2026-08-01T10:00:00.000000Z');

        DB::transaction(fn () => $this->store()->append($transition));
        $first = DB::table('procurement_process_events')->where($transition->idempotencyIdentity())->first();
        DB::transaction(fn () => $this->store()->append($transition));
        $second = DB::table('procurement_process_events')->where($transition->idempotencyIdentity())->first();

        self::assertSame(1, DB::table('procurement_process_events')
            ->where($transition->idempotencyIdentity())->count());
        self::assertSame($first?->id, $second?->id);
        self::assertSame($transition->payloadHash(), $second?->payload_hash);
    }

    public function test_store_rejects_same_identity_with_different_payload(): void
    {
        $chain = $this->fixture()['a'];
        $winner = $this->transition($chain, 5002, '2026-08-01T10:00:00.000000Z');
        $conflict = $this->transition($chain, 5002, '2026-08-01T10:00:01.000000Z');
        DB::transaction(fn () => $this->store()->append($winner));

        try {
            DB::transaction(fn () => $this->store()->append($conflict));
            self::fail('A different payload for the same immutable identity must be rejected.');
        } catch (LogicException $exception) {
            self::assertSame('procurement_process_event_idempotency_conflict', $exception->getMessage());
        }

        $persisted = DB::table('procurement_process_events')->where($winner->idempotencyIdentity())->first();
        self::assertSame(1, DB::table('procurement_process_events')
            ->where($winner->idempotencyIdentity())->count());
        self::assertSame($winner->payloadHash(), $persisted?->payload_hash);
    }

    public function test_database_unique_constraint_rejects_direct_duplicate_identity(): void
    {
        $event = $this->rawEvent($this->fixture()['a']);
        $this->insertRawEvent($event);
        $duplicate = [...$event, 'payload_hash' => str_repeat('e', 64)];

        $exception = $this->captureQueryException(static function () use ($duplicate): void {
            DB::table('procurement_process_events')->insert($duplicate);
        });

        $this->assertSqlState($exception, '23505');
        self::assertStringContainsString('proc_process_event_source_unique', $exception->getMessage());
        self::assertSame(1, $this->eventCount($event));
    }

    public function test_event_is_rolled_back_when_owner_transaction_fails(): void
    {
        $chain = $this->fixture()['a'];
        $transition = $this->transition($chain, 6001, '2026-08-01T10:00:00.000000Z');

        try {
            DB::transaction(function () use ($chain, $transition): void {
                DB::table('purchase_requests')->where('id', $chain['purchase_request_id'])->update([
                    'status' => 'ordered',
                ]);
                $this->store()->append($transition);

                throw new RuntimeException('procurement_owner_transaction_sentinel');
            });
            self::fail('The owner transaction sentinel must roll back the event and mutation.');
        } catch (RuntimeException $exception) {
            self::assertSame('procurement_owner_transaction_sentinel', $exception->getMessage());
        }

        self::assertSame('approved', DB::table('purchase_requests')
            ->where('id', $chain['purchase_request_id'])->value('status'));
        self::assertSame(0, DB::table('procurement_process_events')
            ->where($transition->idempotencyIdentity())->count());
    }

    public function test_owner_mutation_is_rolled_back_when_event_insert_is_rejected(): void
    {
        $fixture = $this->fixture();
        $chain = $fixture['a'];
        $transition = $this->transition(
            $chain,
            6002,
            '2026-08-01T10:00:00.000000Z',
            [
                'supplier_request_id' => $fixture['a2']['supplier_request_id'],
                'supplier_request_line_id' => null,
                'supplier_party_id' => $fixture['a2']['supplier_party_id'],
                'supplier_proposal_id' => null,
                'supplier_proposal_version_id' => null,
                'supplier_proposal_decision_id' => null,
                'purchase_order_id' => null,
                'purchase_order_item_id' => null,
                'purchase_receipt_id' => null,
                'purchase_receipt_line_id' => null,
            ],
        );

        try {
            DB::transaction(function () use ($chain, $transition): void {
                DB::table('purchase_requests')->where('id', $chain['purchase_request_id'])->update([
                    'status' => 'ordered',
                ]);
                $this->store()->append($transition);
            });
            self::fail('Cross-linked event insertion must reject the complete owner transaction.');
        } catch (QueryException $exception) {
            $this->assertSqlState($exception, '23514');
        }

        self::assertSame('approved', DB::table('purchase_requests')
            ->where('id', $chain['purchase_request_id'])->value('status'));
        self::assertSame(0, DB::table('procurement_process_events')
            ->where($transition->idempotencyIdentity())->count());
    }

    public function test_concurrent_exact_replay_serializes_and_persists_once(): void
    {
        $this->runProcessRace(false);
    }

    public function test_concurrent_conflicting_payload_has_one_winner_and_one_conflict(): void
    {
        $this->runProcessRace(true);
    }

    private function runProcessRace(bool $conflictingPayload): void
    {
        if (! function_exists('pcntl_fork')
            || ! function_exists('pcntl_waitpid')
            || ! function_exists('posix_kill')) {
            if (getenv('CI') === 'true') {
                self::fail('CI PostgreSQL process-race gate requires pcntl and posix extensions.');
            }

            $this->markTestSkipped('Requires pcntl and posix extensions for a real PostgreSQL process race.');
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proc-cycle-race-'.bin2hex(random_bytes(8));
        $harness = new PostgresProcessRaceHarness($directory);
        $connections = [
            'proc_cycle_fixture',
            'proc_cycle_winner',
            'proc_cycle_contender',
            'proc_cycle_observer',
        ];
        $originalDefault = (string) config('database.default');
        $children = [];
        $fixture = null;
        $winnerConnection = null;
        $observerConnection = null;
        $fixtureBuilder = null;

        try {
            $fixtureConnection = $harness->independentConnection('proc_cycle_fixture');
            $winnerConnection = $harness->independentConnection('proc_cycle_winner');
            $harness->independentConnection('proc_cycle_contender');
            $observerConnection = $harness->independentConnection('proc_cycle_observer');
            if (! $fixtureConnection instanceof Connection
                || ! $winnerConnection instanceof Connection
                || ! $observerConnection instanceof Connection) {
                throw new RuntimeException('PostgreSQL race connections must be Laravel connections.');
            }

            $fixtureBuilder = new ProcurementCyclePostgresFixture($fixtureConnection);
            $fixture = $fixtureBuilder->create('race-'.bin2hex(random_bytes(5)));
            $winnerTransition = $this->transition(
                $fixture['a'],
                7001,
                '2026-08-01T10:00:00.000000Z',
            );
            $contenderTransition = $conflictingPayload
                ? $this->transition($fixture['a'], 7001, '2026-08-01T10:00:01.000000Z')
                : $winnerTransition;

            $winnerConnection->beginTransaction();
            DB::setDefaultConnection('proc_cycle_winner');
            $this->store()->append($winnerTransition);
            DB::setDefaultConnection('proc_cycle_contender');

            $children[] = $harness->spawn(1, static function () use (
                $contenderTransition,
                $conflictingPayload,
            ): array {
                try {
                    DB::transaction(static function () use ($contenderTransition): void {
                        (new EloquentProcurementProcessEventStore(new ProcurementEventIdempotencyGuard))
                            ->append($contenderTransition);
                    });

                    return ['outcome' => 'replay'];
                } catch (LogicException $exception) {
                    if (! $conflictingPayload) {
                        throw $exception;
                    }

                    return [
                        'outcome' => 'conflict',
                        'message' => $exception->getMessage(),
                    ];
                }
            });
            DB::setDefaultConnection($originalDefault);

            $harness->release(1);
            $backendPid = $harness->waitForWorkerBackendPid(1);
            $harness->waitForPostgresWait($observerConnection, $backendPid);
            $winnerConnection->commit();
            $harness->waitForChildren($children);
            $result = $harness->result(1);

            self::assertSame($conflictingPayload ? 'conflict' : 'replay', $result['outcome']);
            if ($conflictingPayload) {
                self::assertSame(
                    'procurement_process_event_idempotency_conflict',
                    $result['message'] ?? null,
                );
            }
            self::assertSame(1, $observerConnection->table('procurement_process_events')
                ->where($winnerTransition->idempotencyIdentity())->count());
            self::assertSame($winnerTransition->payloadHash(), $observerConnection
                ->table('procurement_process_events')
                ->where($winnerTransition->idempotencyIdentity())
                ->value('payload_hash'));
        } finally {
            DB::setDefaultConnection($originalDefault);
            $failure = null;
            if ($winnerConnection instanceof Connection && $winnerConnection->transactionLevel() > 0) {
                $harness->cleanupStep(static fn () => $winnerConnection->rollBack(), $failure);
            }
            $harness->cleanupStep(static fn () => $harness->terminateAndReap($children), $failure);
            if ($fixture !== null && $fixtureBuilder instanceof ProcurementCyclePostgresFixture) {
                $harness->cleanupStep(static fn () => $fixtureBuilder->cleanup($fixture), $failure);
            }
            foreach ($connections as $connection) {
                $harness->cleanupStep(static fn () => DB::purge($connection), $failure);
            }
            $harness->cleanupStep(static fn () => $harness->cleanup(), $failure);

            if ($failure instanceof Throwable) {
                throw $failure;
            }
        }
    }

    private function fixture(): array
    {
        if ($this->fixtureCache !== null) {
            return $this->fixtureCache;
        }

        $connection = DB::connection();
        if (! $connection instanceof Connection) {
            throw new RuntimeException('Procurement cycle contract tests require a Laravel database connection.');
        }

        $this->fixtureCache = (new ProcurementCyclePostgresFixture($connection))
            ->create('test-'.bin2hex(random_bytes(5)));

        return $this->fixtureCache;
    }

    private function rawEvent(array $chain, array $overrides = []): array
    {
        $policy = $chain['policy'] ?? null;
        $attributes = [
            'organization_id' => $chain['organization_id'],
            'project_id' => $chain['project_id'],
            'purchase_request_id' => $chain['purchase_request_id'],
            'purchase_request_line_id' => $chain['purchase_request_line_id'],
            'supplier_request_id' => $chain['supplier_request_id'] ?? null,
            'supplier_request_line_id' => $chain['supplier_request_line_id'] ?? null,
            'supplier_party_id' => $chain['supplier_party_id'] ?? null,
            'supplier_proposal_id' => $chain['supplier_proposal_id'] ?? null,
            'supplier_proposal_version_id' => $chain['supplier_proposal_version_id'] ?? null,
            'supplier_proposal_decision_id' => $chain['supplier_proposal_decision_id'] ?? null,
            'purchase_order_id' => $chain['purchase_order_id'] ?? null,
            'purchase_order_item_id' => $chain['purchase_order_item_id'] ?? null,
            'purchase_receipt_id' => $chain['purchase_receipt_id'] ?? null,
            'purchase_receipt_line_id' => $chain['purchase_receipt_line_id'] ?? null,
            'policy_version_id' => $policy['id'] ?? null,
            'policy_hash' => $policy['hash'] ?? null,
            'calendar_version' => $policy['calendar_version'] ?? null,
            'calendar_hash' => $policy['calendar_hash'] ?? null,
            'event_code' => ProcurementProcessEventCode::REQUEST_CREATED->value,
            'terminal_reason' => null,
            'event_version' => ProcurementProcessTransition::EVENT_VERSION,
            'actor_id' => null,
            'occurred_at' => '2026-08-01 10:00:00+00',
            'source_kind' => 'procurement_cycle_pg_contract',
            'source_id' => ++$this->sourceSequence,
            'source_event_id' => null,
            'payload_hash' => hash('sha256', 'procurement-cycle-'.$this->sourceSequence),
            'created_at' => '2026-08-01 10:00:00+00',
        ];
        $attributes = array_replace($attributes, $overrides);
        $attributes['dimension_snapshot'] = json_encode(
            $this->dimensionSnapshot($attributes)->values,
            JSON_THROW_ON_ERROR,
        );

        return $attributes;
    }

    private function cleanRequestCreatedEvent(array $chain): array
    {
        $event = $this->rawEvent($chain, [
            'supplier_party_id' => null,
            'supplier_request_id' => null,
            'supplier_request_line_id' => null,
            'supplier_proposal_id' => null,
            'supplier_proposal_version_id' => null,
            'supplier_proposal_decision_id' => null,
            'purchase_order_id' => null,
            'purchase_order_item_id' => null,
            'purchase_receipt_id' => null,
            'purchase_receipt_line_id' => null,
        ]);
        $event['dimension_snapshot'] = json_encode(
            ProcurementProcessDimensionSnapshot::fromArray([
                'schema_version' => ProcurementProcessDimensionSnapshot::SCHEMA_VERSION,
                'organization_id' => $chain['organization_id'],
                'project_id' => $chain['project_id'],
                'purchase_request_id' => $chain['purchase_request_id'],
                'purchase_request_line_id' => $chain['purchase_request_line_id'],
                'policy_version_id' => $chain['policy']['id'],
                'policy_hash' => $chain['policy']['hash'],
                'calendar_version' => $chain['policy']['calendar_version'],
                'calendar_hash' => $chain['policy']['calendar_hash'],
                'quality_status' => 'FULL',
                'gap_codes' => [],
            ])->values,
            JSON_THROW_ON_ERROR,
        );

        return $event;
    }

    private function strictQuarantineEvent(array $chain, ?int $projectId = null): array
    {
        $event = $this->rawEvent($chain, [
            'project_id' => $projectId,
            'supplier_request_id' => null,
            'supplier_request_line_id' => null,
            'supplier_party_id' => null,
            'supplier_proposal_id' => null,
            'supplier_proposal_version_id' => null,
            'supplier_proposal_decision_id' => null,
            'purchase_order_id' => null,
            'purchase_order_item_id' => null,
            'purchase_receipt_id' => null,
            'purchase_receipt_line_id' => null,
            ...$this->policyFields(null),
            'event_code' => ProcurementProcessEventCode::REQUEST_APPROVED->value,
            'occurred_at' => '2026-08-01 10:05:00+00',
        ]);
        $event['dimension_snapshot'] = json_encode([
            'schema_version' => ProcurementProcessDimensionSnapshot::SCHEMA_VERSION,
            'organization_id' => $chain['organization_id'],
            'project_id' => $projectId,
            'purchase_request_id' => $chain['purchase_request_id'],
            'purchase_request_line_id' => $chain['purchase_request_line_id'],
            'quality_status' => 'PARTIAL',
            'gap_codes' => [
                'missing_project_lineage',
                'missing_policy_version',
                'missing_request_created_event',
            ],
        ], JSON_THROW_ON_ERROR);

        return $event;
    }

    private function dimensionSnapshot(array $attributes): ProcurementProcessDimensionSnapshot
    {
        $projectId = $attributes['project_id'];
        $policyId = $attributes['policy_version_id'];
        $gaps = [];
        if ($projectId === null) {
            $gaps[] = 'missing_project_lineage';
        }
        if ($policyId === null) {
            $gaps[] = 'missing_policy_version';
        }

        return ProcurementProcessDimensionSnapshot::fromArray([
            'schema_version' => ProcurementProcessDimensionSnapshot::SCHEMA_VERSION,
            'organization_id' => $attributes['organization_id'],
            'project_id' => $projectId,
            'purchase_request_id' => $attributes['purchase_request_id'],
            'purchase_request_line_id' => $attributes['purchase_request_line_id'],
            'supplier_party_id' => $attributes['supplier_party_id'],
            'awarded_supplier_party_id' => $attributes['supplier_party_id'],
            'awarded_amount' => '10.00',
            'currency' => 'RUB',
            'policy_version_id' => $policyId,
            'policy_hash' => $attributes['policy_hash'],
            'calendar_version' => $attributes['calendar_version'],
            'calendar_hash' => $attributes['calendar_hash'],
            'quality_status' => $gaps === [] ? 'FULL' : 'PARTIAL',
            'gap_codes' => $gaps,
        ]);
    }

    private function transition(
        array $chain,
        int $sourceId,
        string $occurredAt,
        array $overrides = [],
    ): ProcurementProcessTransition {
        $policy = $chain['policy'];
        $attributes = array_replace([
            'organization_id' => $chain['organization_id'],
            'project_id' => $chain['project_id'],
            'purchase_request_id' => $chain['purchase_request_id'],
            'purchase_request_line_id' => $chain['purchase_request_line_id'],
            'supplier_request_id' => $chain['supplier_request_id'],
            'supplier_request_line_id' => $chain['supplier_request_line_id'],
            'supplier_party_id' => $chain['supplier_party_id'],
            'supplier_proposal_id' => $chain['supplier_proposal_id'],
            'supplier_proposal_version_id' => $chain['supplier_proposal_version_id'],
            'supplier_proposal_decision_id' => $chain['supplier_proposal_decision_id'],
            'purchase_order_id' => $chain['purchase_order_id'],
            'purchase_order_item_id' => $chain['purchase_order_item_id'],
            'purchase_receipt_id' => $chain['purchase_receipt_id'],
            'purchase_receipt_line_id' => $chain['purchase_receipt_line_id'],
            'policy_version_id' => $policy['id'],
            'policy_hash' => $policy['hash'],
            'calendar_version' => $policy['calendar_version'],
            'calendar_hash' => $policy['calendar_hash'],
        ], $overrides);

        return new ProcurementProcessTransition(
            eventCode: ProcurementProcessEventCode::REQUEST_CREATED,
            organizationId: $attributes['organization_id'],
            projectId: $attributes['project_id'],
            purchaseRequestId: $attributes['purchase_request_id'],
            purchaseRequestLineId: $attributes['purchase_request_line_id'],
            occurredAt: new DateTimeImmutable($occurredAt),
            sourceKind: 'procurement_cycle_pg_contract',
            sourceId: $sourceId,
            dimensionSnapshot: $this->dimensionSnapshot($attributes),
            supplierRequestId: $attributes['supplier_request_id'],
            supplierRequestLineId: $attributes['supplier_request_line_id'],
            supplierPartyId: $attributes['supplier_party_id'],
            supplierProposalId: $attributes['supplier_proposal_id'],
            supplierProposalVersionId: $attributes['supplier_proposal_version_id'],
            supplierProposalDecisionId: $attributes['supplier_proposal_decision_id'],
            purchaseOrderId: $attributes['purchase_order_id'],
            purchaseOrderItemId: $attributes['purchase_order_item_id'],
            purchaseReceiptId: $attributes['purchase_receipt_id'],
            purchaseReceiptLineId: $attributes['purchase_receipt_line_id'],
            policyVersionId: $attributes['policy_version_id'],
            policyHash: $attributes['policy_hash'],
            calendarVersion: $attributes['calendar_version'],
            calendarHash: $attributes['calendar_hash'],
        );
    }

    private function invalidLineageMutation(string $case, array $fixture): array
    {
        $a = $fixture['a'];
        $a2 = $fixture['a2'];
        $b = $fixture['b'];
        $clearAfterSupplierRequest = [
            'supplier_request_line_id' => null,
            'supplier_proposal_id' => null,
            'supplier_proposal_version_id' => null,
            'supplier_proposal_decision_id' => null,
            'purchase_order_id' => null,
            'purchase_order_item_id' => null,
            'purchase_receipt_id' => null,
            'purchase_receipt_line_id' => null,
        ];
        $clearAfterProposal = [
            'supplier_proposal_version_id' => null,
            'supplier_proposal_decision_id' => null,
            'purchase_order_id' => null,
            'purchase_order_item_id' => null,
            'purchase_receipt_id' => null,
            'purchase_receipt_line_id' => null,
        ];

        return match ($case) {
            'organization_request' => [$a, [
                'organization_id' => $b['organization_id'],
                'project_id' => $b['project_id'],
                ...$this->policyFields($b['policy']),
            ]],
            'project_same_organization' => [$a, [
                'project_id' => $a2['project_id'],
                ...$this->policyFields($a2['policy']),
            ]],
            'project_other_organization' => [$a, [
                'project_id' => $b['project_id'],
                ...$this->policyFields($b['policy']),
            ]],
            'project_null' => [$a, [
                'project_id' => null,
                ...$this->policyFields(null),
            ]],
            'request_project_null' => [$fixture['no_project'], [
                'project_id' => $a['project_id'],
                ...$this->policyFields($a['policy']),
            ]],
            'policy_scope' => [$a, $this->policyFields($a2['policy'])],
            'policy_organization' => [$a, $this->policyFields($b['policy'])],
            'supplier_request' => [$a, [
                ...$clearAfterSupplierRequest,
                'supplier_request_id' => $a2['supplier_request_id'],
                'supplier_party_id' => $a2['supplier_party_id'],
            ]],
            'supplier_request_line' => [$a, [
                ...$clearAfterProposal,
                'supplier_request_line_id' => $a2['supplier_request_line_id'],
            ]],
            'supplier_request_party' => [$a, [
                ...$clearAfterSupplierRequest,
                'supplier_party_id' => $a2['supplier_party_id'],
            ]],
            'proposal_party' => [$a, [
                ...$clearAfterProposal,
                'supplier_proposal_id' => $fixture['crossed_proposal_party_id'],
            ]],
            'order_party' => [$a, [
                'purchase_order_id' => $fixture['crossed_order_party_id'],
                'purchase_order_item_id' => null,
                'purchase_receipt_id' => null,
                'purchase_receipt_line_id' => null,
            ]],
            'proposal_request' => [$a, [
                ...$clearAfterProposal,
                'supplier_proposal_id' => $a2['supplier_proposal_id'],
            ]],
            'proposal_version' => [$a, [
                'supplier_proposal_version_id' => $a2['supplier_proposal_version_id'],
                'supplier_proposal_decision_id' => null,
                'purchase_order_id' => null,
                'purchase_order_item_id' => null,
                'purchase_receipt_id' => null,
                'purchase_receipt_line_id' => null,
            ]],
            'decision_winner' => [$a, [
                'supplier_proposal_decision_id' => $a2['supplier_proposal_decision_id'],
                'purchase_order_id' => null,
                'purchase_order_item_id' => null,
                'purchase_receipt_id' => null,
                'purchase_receipt_line_id' => null,
            ]],
            'order_acceptance' => [$a, [
                'supplier_proposal_id' => null,
                'supplier_proposal_version_id' => null,
                'supplier_proposal_decision_id' => null,
                'purchase_order_item_id' => null,
                'purchase_receipt_id' => null,
                'purchase_receipt_line_id' => null,
            ]],
            'order_item' => [$a, [
                'purchase_order_item_id' => $a2['purchase_order_item_id'],
                'purchase_receipt_id' => null,
                'purchase_receipt_line_id' => null,
            ]],
            'order_item_proposal_line' => [$a, [
                'purchase_order_item_id' => $fixture['crossed_order_item_id'],
                'purchase_receipt_id' => null,
                'purchase_receipt_line_id' => null,
            ]],
            'receipt' => [$a, [
                'purchase_receipt_id' => $a2['purchase_receipt_id'],
                'purchase_receipt_line_id' => null,
            ]],
            'receipt_line' => [$a, [
                'purchase_receipt_line_id' => $a2['purchase_receipt_line_id'],
            ]],
            default => throw new RuntimeException("Unknown invalid lineage case: {$case}"),
        };
    }

    private function policyFields(?array $policy): array
    {
        return [
            'policy_version_id' => $policy['id'] ?? null,
            'policy_hash' => $policy['hash'] ?? null,
            'calendar_version' => $policy['calendar_version'] ?? null,
            'calendar_hash' => $policy['calendar_hash'] ?? null,
        ];
    }

    private function insertRawEvent(array $attributes): int
    {
        return (int) DB::table('procurement_process_events')->insertGetId($attributes);
    }

    private function eventCount(array $event): int
    {
        return DB::table('procurement_process_events')->where([
            'organization_id' => $event['organization_id'],
            'purchase_request_line_id' => $event['purchase_request_line_id'],
            'event_code' => $event['event_code'],
            'source_kind' => $event['source_kind'],
            'source_id' => $event['source_id'],
            'event_version' => $event['event_version'],
        ])->count();
    }

    private function store(): EloquentProcurementProcessEventStore
    {
        return new EloquentProcurementProcessEventStore(new ProcurementEventIdempotencyGuard);
    }

    private function captureQueryException(callable $operation): QueryException
    {
        try {
            DB::transaction($operation);
        } catch (QueryException $exception) {
            return $exception;
        }

        self::fail('The PostgreSQL contract was expected to reject the operation.');
    }

    private function assertSqlState(QueryException $exception, string $expected): void
    {
        self::assertSame($expected, $exception->errorInfo[0] ?? null, $exception->getMessage());
    }
}
