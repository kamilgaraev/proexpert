<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement\Reporting\Award;

use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardCandidateEvidence;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardEvidenceEvent;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardManifest;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardPolicyDefinition;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardSelectionFact;
use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardCompleteness;
use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardEventType;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\EloquentProcurementAwardEvidenceStore;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\EloquentProcurementAwardSelectionSource;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardEvidenceRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardManifestBuilder;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardOwnerEventRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\LaravelProcurementTransactionBoundary;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Procurement\Reporting\Award\ProcurementAwardPostgresFixture;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;

#[Group('postgresql')]
final class ProcurementAwardSourcePostgresTest extends TestCase
{
    use RefreshDatabase;

    private ?array $fixtureCache = null;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('PROCUREMENT_AWARD_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped(
                'Set PROCUREMENT_AWARD_POSTGRES_TESTS=1 to run isolated PostgreSQL procurement-award tests.',
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

    public function test_database_accepts_complete_pinned_direct_selection_and_commit(): void
    {
        $fixture = $this->fixture();
        $recorder = $this->recorder();

        [$selection, $commit] = DB::transaction(function () use ($fixture, $recorder): array {
            $selection = $recorder->captureSelection($this->selection($fixture));
            $commit = $recorder->commit(
                $fixture['decision_id'],
                $fixture['purchase_order_id'],
                $fixture['first']['proposal_id'],
                $fixture['first']['proposal_version_id'],
                new DateTimeImmutable('2026-08-01T10:01:00.000000+00:00'),
                $fixture['user_id'],
            );

            return [$selection, $commit];
        });

        self::assertSame($selection->eventId, $commit->predecessorEventId);
        self::assertSame(2, DB::table('procurement_award_evidence_events')
            ->where('decision_id', $fixture['decision_id'])
            ->count());
        self::assertSame(2, DB::table('procurement_award_evidence_candidates')
            ->where('event_id', $selection->eventId)
            ->count());
    }

    public function test_real_source_query_and_owner_capture_keep_the_full_candidate_universe(): void
    {
        $fixture = $this->fixture();

        $selection = DB::transaction(function () use ($fixture): ProcurementAwardEvidenceEvent {
            $source = new EloquentProcurementAwardSelectionSource;
            $rows = $source->candidateRows(
                $fixture['organization_id'],
                $fixture['supplier_request']['supplier_request_id'],
                new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            );
            self::assertSame([
                $fixture['first']['proposal_id'],
                $fixture['second']['proposal_id'],
            ], array_column($rows, 'proposal_id'));

            $owner = new ProcurementAwardOwnerEventRecorder(
                new ProcurementAwardManifestBuilder,
                $this->recorder(),
                $source,
            );
            $request = SupplierRequest::query()->findOrFail($fixture['supplier_request']['supplier_request_id']);
            $decision = SupplierProposalDecision::query()->findOrFail($fixture['decision_id']);
            $prepared = $owner->prepareForSupplierRequest(
                $request,
                $fixture['first']['proposal_id'],
                new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            );
            $owner->selected(
                $prepared,
                $decision,
                new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
                $fixture['user_id'],
                null,
            );

            return $this->store()->eventsForDecision($fixture['decision_id'])[0];
        });

        self::assertSame(2, $selection->manifest->candidateCount);
        self::assertSame(ProcurementAwardCompleteness::NOT_COMPARABLE, $selection->manifest->completeness);
    }

    public function test_database_rejects_outcome_without_immediate_predecessor(): void
    {
        $fixture = $this->fixture();
        $selection = $this->appendSelection($fixture);
        $invalid = $selection->outcome(
            eventType: ProcurementAwardEventType::AWARD_COMMITTED,
            eventSequence: 2,
            occurredAt: new DateTimeImmutable('2026-08-01T10:01:00+00:00'),
            actorId: $fixture['user_id'],
            predecessorEventId: null,
            purchaseOrderId: $fixture['purchase_order_id'],
        );

        $exception = $this->captureQueryException(fn (): ProcurementAwardEvidenceEvent => $this->store()->append($invalid));

        $this->assertSqlState($exception, '23514');
        self::assertStringContainsString('predecessor required', $exception->getMessage());
    }

    public function test_two_concurrent_owner_captures_serialize_to_one_exact_fact(): void
    {
        if (! function_exists('pcntl_fork')
            || ! function_exists('pcntl_waitpid')
            || ! function_exists('posix_kill')) {
            if (getenv('CI') === 'true') {
                self::fail('CI procurement-award race gate requires pcntl and posix.');
            }

            $this->markTestSkipped('Requires pcntl and posix for a real PostgreSQL process race.');
        }

        $fixture = $this->fixture();
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proc-award-race-'.bin2hex(random_bytes(8));
        $harness = new PostgresProcessRaceHarness($directory);
        $connections = ['proc_award_lock', 'proc_award_observer'];
        $children = [];
        $failure = null;
        $lock = null;

        try {
            $lock = $harness->independentConnection('proc_award_lock');
            $observer = $harness->independentConnection('proc_award_observer');
            $lock->beginTransaction();
            $lock->table('supplier_proposals')
                ->where('id', $fixture['first']['proposal_id'])
                ->lockForUpdate()
                ->first();

            foreach ([1, 2] as $worker) {
                $children[] = $harness->spawn($worker, static function () use ($fixture): array {
                    return DB::transaction(static function () use ($fixture): array {
                        $source = new EloquentProcurementAwardSelectionSource;
                        $owner = new ProcurementAwardOwnerEventRecorder(
                            new ProcurementAwardManifestBuilder,
                            new ProcurementAwardEvidenceRecorder(
                                new EloquentProcurementAwardEvidenceStore,
                                new LaravelProcurementTransactionBoundary,
                            ),
                            $source,
                        );
                        $request = SupplierRequest::query()
                            ->findOrFail($fixture['supplier_request']['supplier_request_id']);
                        $decision = SupplierProposalDecision::query()->findOrFail($fixture['decision_id']);
                        $occurredAt = new DateTimeImmutable('2026-08-01T10:00:00+00:00');
                        $prepared = $owner->prepareForSupplierRequest(
                            $request,
                            $fixture['first']['proposal_id'],
                            $occurredAt,
                        );
                        $owner->selected(
                            $prepared,
                            $decision,
                            $occurredAt,
                            $fixture['user_id'],
                            null,
                        );
                        $event = (new EloquentProcurementAwardEvidenceStore)
                            ->eventsForDecision($fixture['decision_id'])[0];

                        return ['event_id' => $event->eventId];
                    });
                });
            }

            foreach ([1, 2] as $worker) {
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

            self::assertSame($harness->result(1)['event_id'], $harness->result(2)['event_id']);
            self::assertSame(1, DB::table('procurement_award_evidence_events')
                ->where('decision_id', $fixture['decision_id'])
                ->where('event_type', 'comparison_captured')
                ->count());
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            if ($lock !== null && $lock->transactionLevel() > 0) {
                $harness->cleanupStep(static fn () => $lock->rollBack(), $failure);
            }
            $harness->cleanupStep(fn () => $harness->terminateAndReap($children), $failure);
            foreach ($connections as $connection) {
                $harness->cleanupStep(static fn () => DB::purge($connection), $failure);
            }
            $harness->cleanupStep(fn () => $harness->cleanup(), $failure);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function test_database_rejects_append_only_mutations(): void
    {
        $fixture = $this->fixture();
        $selection = $this->appendSelection($fixture);
        $policy = ProcurementAwardPolicyDefinition::v1();
        $cases = [
            'policy update' => static fn (): int => DB::table('procurement_award_policy_versions')
                ->where('policy_id', $policy->policyId)->update(['policy_hash' => str_repeat('f', 64)]),
            'policy delete' => static fn (): int => DB::table('procurement_award_policy_versions')
                ->where('policy_id', $policy->policyId)->delete(),
            'event update' => static fn (): int => DB::table('procurement_award_evidence_events')
                ->where('id', $selection->eventId)->update(['source_hash' => str_repeat('f', 64)]),
            'event delete' => static fn (): int => DB::table('procurement_award_evidence_events')
                ->where('id', $selection->eventId)->delete(),
            'candidate update' => static fn (): int => DB::table('procurement_award_evidence_candidates')
                ->where('event_id', $selection->eventId)->where('ordinal', 1)->update(['total_amount' => '999']),
            'candidate delete' => static fn (): int => DB::table('procurement_award_evidence_candidates')
                ->where('event_id', $selection->eventId)->where('ordinal', 1)->delete(),
            'proposal version update' => static fn (): int => DB::table('supplier_proposal_versions')
                ->where('id', $fixture['first']['proposal_version_id'])->update(['content_hash' => str_repeat('f', 64)]),
            'request version delete' => static fn (): int => DB::table('supplier_request_versions')
                ->where('id', $fixture['supplier_request']['supplier_request_version_id'])->delete(),
        ];

        foreach ($cases as $label => $mutation) {
            $exception = $this->captureQueryException($mutation);
            $this->assertSqlState($exception, '55000', $label);
        }
    }

    public function test_database_rejects_foreign_candidate_pair_at_deferred_commit(): void
    {
        $fixture = $this->fixture();
        $fact = $this->selection($fixture, [$fixture['first'], $fixture['foreign']]);
        $event = ProcurementAwardEvidenceEvent::fromSelection($fact, 1, 1);

        $exception = $this->captureQueryException(function () use ($event): void {
            $this->store()->append($event);
        });

        $this->assertSqlState($exception, '23514');
        self::assertStringContainsString('candidate event scope mismatch', $exception->getMessage());
    }

    public function test_database_rejects_unpinned_policy_and_source_hash_tampering(): void
    {
        $fixture = $this->fixture();
        $event = ProcurementAwardEvidenceEvent::fromSelection($this->selection($fixture), 1, 1);

        $unpinned = $this->eventAttributes($event);
        $unpinned['policy_hash'] = str_repeat('f', 64);
        $exception = $this->captureQueryException(function () use ($unpinned): void {
            DB::table('procurement_award_evidence_events')->insert($unpinned);
        });
        $this->assertSqlState($exception, '23503');

        $tampered = $event->withSourceHash(str_repeat('f', 64));
        $exception = $this->captureQueryException(function () use ($tampered): void {
            $this->store()->append($tampered);
        });
        $this->assertSqlState($exception, '23514');
        self::assertStringContainsString('source hash mismatch', $exception->getMessage());
    }

    public function test_store_keeps_exact_replay_and_fails_closed_on_conflicting_replay(): void
    {
        $fixture = $this->fixture();
        $event = $this->appendSelection($fixture);
        $replay = DB::transaction(fn (): ProcurementAwardEvidenceEvent => $this->store()->append(ProcurementAwardEvidenceEvent::fromSelection($this->selection($fixture), 1, 1)));

        self::assertSame($event->eventId, $replay->eventId);

        try {
            DB::transaction(fn (): ProcurementAwardEvidenceEvent => $this->store()->append($event->withSourceHash(str_repeat('f', 64))));
        } catch (LogicException $exception) {
            self::assertSame('procurement_award_evidence_idempotency_conflict', $exception->getMessage());

            return;
        }

        self::fail('The contract store accepted a conflicting replay.');
    }

    public function test_database_rejects_parent_without_full_manifest_at_deferred_commit(): void
    {
        $event = ProcurementAwardEvidenceEvent::fromSelection($this->selection($this->fixture()), 1, 1);

        $exception = $this->captureQueryException(function () use ($event): void {
            DB::table('procurement_award_evidence_events')->insert($this->eventAttributes($event));
        });

        $this->assertSqlState($exception, '23514');
        self::assertStringContainsString('manifest completeness mismatch', $exception->getMessage());
    }

    private function fixture(): array
    {
        if ($this->fixtureCache !== null) {
            return $this->fixtureCache;
        }

        $connection = DB::connection();
        if (! $connection instanceof Connection) {
            throw new RuntimeException('Procurement award contract tests require a Laravel database connection.');
        }

        $this->fixtureCache = (new ProcurementAwardPostgresFixture($connection))
            ->create('test-'.bin2hex(random_bytes(5)));

        return $this->fixtureCache;
    }

    private function recorder(): ProcurementAwardEvidenceRecorder
    {
        return new ProcurementAwardEvidenceRecorder(
            $this->store(),
            new LaravelProcurementTransactionBoundary,
        );
    }

    private function store(): EloquentProcurementAwardEvidenceStore
    {
        return new EloquentProcurementAwardEvidenceStore;
    }

    private function appendSelection(array $fixture): ProcurementAwardEvidenceEvent
    {
        return DB::transaction(fn (): ProcurementAwardEvidenceEvent => $this->recorder()->captureSelection($this->selection($fixture)));
    }

    private function selection(array $fixture, ?array $candidateRows = null): ProcurementAwardSelectionFact
    {
        $candidateRows ??= [$fixture['first'], $fixture['second']];
        $candidates = array_map(
            fn (array $row): ProcurementAwardCandidateEvidence => $this->candidate($fixture, $row),
            $candidateRows,
        );
        $selected = $fixture['first'];
        $cheapest = $candidateRows === [$fixture['first'], $fixture['second']] ? $fixture['first'] : null;
        $manifest = new ProcurementAwardManifest(
            candidates: $candidates,
            completeness: $cheapest === null
                ? ProcurementAwardCompleteness::NOT_COMPARABLE
                : ProcurementAwardCompleteness::COMPLETE,
            selectedProposalId: $selected['proposal_id'],
            selectedProposalVersionId: $selected['proposal_version_id'],
            cheapestProposalId: $cheapest['proposal_id'] ?? null,
            cheapestProposalVersionId: $cheapest['proposal_version_id'] ?? null,
            selectedRank: $cheapest === null ? null : 1,
            cheapestRank: $cheapest === null ? null : 1,
            quarantineCodes: $cheapest === null ? ['foreign_supplier_request'] : [],
        );

        return ProcurementAwardSelectionFact::create(
            organizationId: $fixture['organization_id'],
            projectId: $fixture['project_id'],
            purchaseRequestId: $fixture['purchase_request_id'],
            supplierRequestId: $fixture['supplier_request']['supplier_request_id'],
            supplierRequestVersionId: $fixture['supplier_request']['supplier_request_version_id'],
            supplierRequestVersionHash: $fixture['supplier_request']['supplier_request_version_hash'],
            decisionId: $fixture['decision_id'],
            selectedStatus: 'selected',
            occurredAt: new DateTimeImmutable('2026-08-01T10:00:00.000000+00:00'),
            actorId: $fixture['user_id'],
            manifest: $manifest,
            policy: ProcurementAwardPolicyDefinition::v1(),
            reason: null,
        );
    }

    private function candidate(array $fixture, array $row): ProcurementAwardCandidateEvidence
    {
        return new ProcurementAwardCandidateEvidence(
            organizationId: $fixture['organization_id'],
            projectId: $fixture['project_id'],
            purchaseRequestId: $fixture['purchase_request_id'],
            supplierRequestId: $row['supplier_request_id'],
            supplierRequestVersionId: $row['supplier_request_version_id'],
            supplierRequestVersionHash: $row['supplier_request_version_hash'],
            proposalId: $row['proposal_id'],
            proposalVersionId: $row['proposal_version_id'],
            supplierPartyId: $row['supplier_party_id'],
            proposalStatus: 'accepted',
            proposalValidUntil: null,
            versionContentHash: $row['version_content_hash'],
            subtotalAmount: $row['total'],
            deliveryAmount: '0',
            vatAmount: '0',
            totalAmount: $row['total'],
            comparisonTotal: $row['total'],
            currency: 'RUB',
            vatMode: 'included',
            vatRate: '20',
            deliveryDueDate: '2026-08-10',
            leadTimeDays: 5,
            requestLineCoverage: [[
                'supplier_request_line_id' => $row['supplier_request_line_id'],
                'required_quantity' => '1',
                'required_unit' => 'pcs',
                'covered_quantity' => '1',
                'covered_unit' => 'pcs',
                'covered' => true,
            ]],
            comparable: true,
            exclusionCodes: [],
        );
    }

    private function eventAttributes(ProcurementAwardEvidenceEvent $event): array
    {
        return [
            'id' => $event->eventId,
            'organization_id' => $event->organizationId,
            'project_id' => $event->projectId,
            'purchase_request_id' => $event->purchaseRequestId,
            'supplier_request_id' => $event->supplierRequestId,
            'supplier_request_version_id' => $event->supplierRequestVersionId,
            'supplier_request_version_hash' => $event->supplierRequestVersionHash,
            'decision_id' => $event->decisionId,
            'decision_revision' => $event->decisionRevision,
            'event_sequence' => $event->eventSequence,
            'event_type' => $event->eventType->value,
            'occurred_at' => $event->occurredAtUtc(),
            'actor_id' => $event->actorId,
            'selected_status' => $event->selectedStatus,
            'policy_id' => $event->policy->policyId,
            'policy_version' => $event->policy->version,
            'policy_hash' => $event->policy->canonicalHash(),
            'selection_fingerprint' => $event->selectionFingerprint,
            'source_hash' => $event->sourceHash,
            'manifest_hash' => $event->manifest->contentHash(),
            'candidate_count' => $event->manifest->candidateCount,
            'comparable_count' => $event->manifest->comparableCount,
            'completeness' => $event->manifest->completeness->value,
            'quarantine_codes' => json_encode($event->manifest->quarantineCodes, JSON_THROW_ON_ERROR),
            'selected_proposal_id' => $event->manifest->selectedProposalId,
            'selected_proposal_version_id' => $event->manifest->selectedProposalVersionId,
            'cheapest_proposal_id' => $event->manifest->cheapestProposalId,
            'cheapest_proposal_version_id' => $event->manifest->cheapestProposalVersionId,
            'selected_rank' => $event->manifest->selectedRank,
            'cheapest_rank' => $event->manifest->cheapestRank,
            'reason_present' => $event->reasonPresent,
            'reason_normalized_length' => $event->reasonNormalizedLength,
            'reason_digest' => $event->reasonDigest,
            'predecessor_event_id' => $event->predecessorEventId,
            'purchase_order_id' => $event->purchaseOrderId,
            'created_at' => '2026-08-01 10:00:00+00',
        ];
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

    private function assertSqlState(QueryException $exception, string $expected, string $label = ''): void
    {
        self::assertSame($expected, $exception->errorInfo[0] ?? null, $label.' '.$exception->getMessage());
    }
}
