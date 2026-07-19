<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRedactor;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRolloutService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentOutboxMessage;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contract\ContractAuditedMutationService;
use App\Services\Contract\ContractAuditReconciliationService;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Audit\LegalDocumentAuditService;
use App\Services\LegalArchive\Audit\LegalDocumentOutbox;
use App\Services\LegalArchive\Audit\LegalDocumentOutboxPublisher;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class LegalDocumentAuditPostgresIntegrationTest extends TestCase
{
    private Capsule $database;

    private ConnectionInterface $first;

    private ConnectionInterface $second;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('LEGAL_DOCUMENT_PG_TEST_DSN');
        if (! is_string($dsn) || $dsn === '' || getenv('LEGAL_DOCUMENT_PG_TEST_ALLOW_DDL') !== '1') {
            self::markTestSkipped('Dedicated PostgreSQL integration database is not enabled.');
        }
        $config = $this->connectionConfig($dsn);
        $this->database = new Capsule;
        $this->database->addConnection($config, 'legal_pg_first');
        $this->database->addConnection($config, 'legal_pg_second');
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher(new Container));
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $this->first = $this->database->getConnection('legal_pg_first');
        $this->second = $this->database->getConnection('legal_pg_second');
        $database = (string) $this->first->selectOne('SELECT current_database() AS name')->name;
        if (preg_match('/(?:_test|_testing)$/D', $database) !== 1) {
            self::markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }
        $this->schema = 'legal_doc_it_'.bin2hex(random_bytes(6));
        $this->first->statement("CREATE SCHEMA {$this->schema}");
        $this->first->statement("SET search_path TO {$this->schema}");
        $this->second->statement("SET search_path TO {$this->schema}");
        $this->installSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->first, $this->schema) && str_starts_with($this->schema, 'legal_doc_it_')) {
            $this->first->statement("DROP SCHEMA {$this->schema} CASCADE");
        }
        parent::tearDown();
    }

    public function test_production_recorders_on_two_connections_preserve_hashes_and_chain(): void
    {
        $integrity = new ImmutableAuditIntegrityService;
        $first = new ImmutableAuditRecorder(new ImmutableAuditRedactor, $integrity, $this->first);
        $second = new ImmutableAuditRecorder(new ImmutableAuditRedactor, $integrity, $this->second);
        $base = $this->event('created', 'source:1');

        $created = $first->record($base);
        $duplicate = $second->record($base);
        $updated = $second->record($this->event('updated', 'source:2'));

        self::assertSame($created->id, $duplicate->id);
        self::assertTrue($integrity->verifyEvent($created->refresh())['payload_valid']);
        self::assertTrue($integrity->verifyEvent($updated->refresh())['record_valid']);
        $chain = (new ImmutableAuditEvent)->setConnection('legal_pg_first')->newQuery()
            ->where('chain_scope', 'organization:7:legal_document:42')
            ->orderBy('sequence_id')
            ->get();
        self::assertTrue($integrity->verifyChain($chain)['valid']);
        $previous = null;
        foreach ($chain as $event) {
            [$payloadHash, $recordHash] = $this->independentHashes($event, $previous);
            self::assertSame((string) $event->payload_hash, $payloadHash);
            self::assertSame((string) $event->record_hash, $recordHash);
            self::assertSame($previous, $event->previous_hash);
            $previous = $recordHash;
        }
        self::assertGreaterThan($created->sequence_id, $updated->sequence_id);
    }

    public function test_production_outbox_is_idempotent_across_connections_and_retries_stable_message(): void
    {
        $first = new LegalDocumentOutbox(dispatchJobs: false, connection: $this->first);
        $second = new LegalDocumentOutbox(dispatchJobs: false, connection: $this->second);
        $message = $first->enqueue('legal_document.updated', 'legal_document', '42', ['organization_id' => 7], 'source:2');
        $duplicate = $second->enqueue('legal_document.updated', 'legal_document', '42', ['organization_id' => 7], 'source:2');
        $publisher = new PostgresCrashAfterDeliveryPublisher;

        self::assertSame($message->id, $duplicate->id);
        self::assertSame('retry_scheduled', $first->publish((string) $message->id, $publisher)->status);
        $message->setConnection('legal_pg_first');
        $message->refresh()->forceFill(['available_at' => now()->subSecond()])->save();
        self::assertSame('published', $second->publish((string) $message->id, $publisher)->status);
        self::assertSame([(string) $message->id], array_keys($publisher->received));
        self::assertSame([(string) $message->id, (string) $message->id], $publisher->attempts);
    }

    public function test_concurrent_processes_use_production_recorder_and_leave_a_valid_chain(): void
    {
        $worker = dirname(__DIR__, 2).'/Support/LegalArchive/PostgresAuditWorker.php';
        $first = new Process([PHP_BINARY, $worker, $this->schema, 'parallel_a', 'parallel:1']);
        $second = new Process([PHP_BINARY, $worker, $this->schema, 'parallel_b', 'parallel:2']);

        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
        self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
        $events = (new ImmutableAuditEvent)->setConnection('legal_pg_first')->newQuery()
            ->where('chain_scope', 'organization:7:legal_document:99')
            ->orderBy('sequence_id')
            ->get();
        self::assertCount(2, $events);
        self::assertTrue((new ImmutableAuditIntegrityService)->verifyChain($events)['valid']);
    }

    public function test_phase_a_accepts_delayed_legacy_max_plus_one_and_new_writer_without_collision_or_hash_mutation(): void
    {
        $worker = dirname(__DIR__, 2).'/Support/LegalArchive/PostgresAuditWorker.php';
        $barrier = bin2hex(random_bytes(12));
        $ready = sys_get_temp_dir().DIRECTORY_SEPARATOR."most-audit-{$barrier}.ready";
        $release = sys_get_temp_dir().DIRECTORY_SEPARATOR."most-audit-{$barrier}.release";
        $legacy = new Process([PHP_BINARY, $worker, $this->schema, 'legacy', 'legacy:1', 'legacy', $barrier]);
        $modern = new Process([PHP_BINARY, $worker, $this->schema, 'modern', 'modern:1', 'batch']);
        $legacy->start();
        self::assertTrue($legacy->waitUntil(static fn (): bool => is_file($ready)), 'Legacy writer did not reach the barrier.');
        $modern->start();
        $modern->wait();
        file_put_contents($release, 'release', LOCK_EX);
        $legacy->wait();
        @unlink($ready);
        @unlink($release);

        self::assertTrue($legacy->isSuccessful(), $legacy->getErrorOutput());
        self::assertTrue($modern->isSuccessful(), $modern->getErrorOutput());
        $rows = $this->first->table('immutable_audit_events')->orderBy('sequence_id')->get();
        self::assertCount(3, $rows);
        self::assertSame(3, count(array_unique($rows->pluck('sequence_id')->all())));
        $hashes = $rows->map(fn ($row): array => [(string) $row->payload_hash, (string) $row->record_hash])->all();
        self::assertSame($hashes, $this->first->table('immutable_audit_events')->orderBy('sequence_id')->get()->map(fn ($row): array => [(string) $row->payload_hash, (string) $row->record_hash])->all());
    }

    public function test_phase_b_repairs_invalid_index_and_repairs_wrong_definition_after_completed_marker(): void
    {
        $recorder = new ImmutableAuditRecorder(new ImmutableAuditRedactor, new ImmutableAuditIntegrityService, $this->first);
        $event = $recorder->record($this->event('created', 'repair:1'));
        $this->first->statement('DROP INDEX immutable_audit_source_event_unique');
        $this->first->statement('CREATE TEMP TABLE duplicate_event AS SELECT * FROM immutable_audit_events WHERE id = ?', [$event->id]);
        $duplicateId = (string) \Illuminate\Support\Str::uuid();
        $this->first->statement('UPDATE duplicate_event SET id = ?, sequence_id = sequence_id + 1', [$duplicateId]);
        $this->first->statement('INSERT INTO immutable_audit_events SELECT * FROM duplicate_event');
        try {
            $this->first->statement('CREATE UNIQUE INDEX CONCURRENTLY immutable_audit_source_event_aggregate_unique ON immutable_audit_events (organization_id, domain, subject_type, subject_id, source, source_event_id) WHERE source_event_id IS NOT NULL AND subject_type IS NOT NULL AND subject_id IS NOT NULL');
            self::fail('Duplicate rows must leave a failed concurrent index for recovery.');
        } catch (\Illuminate\Database\QueryException) {
        }
        self::assertFalse((bool) $this->first->selectOne("SELECT indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'immutable_audit_source_event_aggregate_unique'")->indisvalid);
        $this->first->statement('DROP TRIGGER immutable_audit_events_append_only ON immutable_audit_events');
        $this->first->table('immutable_audit_events')->where('id', $duplicateId)->delete();
        $this->first->statement('CREATE TRIGGER immutable_audit_events_append_only BEFORE UPDATE OR DELETE ON immutable_audit_events FOR EACH ROW EXECUTE FUNCTION immutable_audit_prevent_mutation()');

        $rollout = new ImmutableAuditRolloutService;
        $rollout->cutover($this->first, true, 2);
        self::assertTrue((bool) $this->first->selectOne("SELECT indisvalid AND indisready AS ready FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'immutable_audit_source_event_aggregate_unique'")->ready);
        $this->first->statement('DROP INDEX CONCURRENTLY immutable_audit_source_event_aggregate_unique');
        $this->first->statement('CREATE UNIQUE INDEX immutable_audit_source_event_aggregate_unique ON immutable_audit_events (id)');
        $rollout->cutover($this->first, true, 2);
        $definition = (string) $this->first->selectOne("SELECT pg_get_indexdef(c.oid) AS definition FROM pg_class c WHERE c.relname = 'immutable_audit_source_event_aggregate_unique'")->definition;
        self::assertStringContainsString('(organization_id, domain, subject_type, subject_id, source, source_event_id)', $definition);
        self::assertSame((string) $event->payload_hash, (string) $this->first->table('immutable_audit_events')->where('id', $event->id)->value('payload_hash'));
        self::assertSame((string) $event->record_hash, (string) $this->first->table('immutable_audit_events')->where('id', $event->id)->value('record_hash'));
    }

    public function test_postgres_reconciliation_recomputes_stale_debt_and_crash_rolls_back_before_retry(): void
    {
        $contractId = (int) $this->first->table('contracts')->insertGetId([
            'organization_id' => 7, 'number' => 'PG-1', 'is_fixed_amount' => false, 'total_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->first->table('contract_performance_acts')->insert([
            'contract_id' => $contractId, 'amount' => 150, 'is_approved' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $contract = (new Contract)->setConnection('legal_pg_first')->newQuery()->findOrFail($contractId);
        $audit = new PostgresRecordingContractAudit;
        $mutations = new ContractAuditedMutationService($audit, $this->first);
        $crashing = new ContractAuditReconciliationService($this->first, $mutations, static function (): void {
            throw new RuntimeException('crash after mutation');
        });
        $crashing->recordDebt($contract, $contractId, 'performance_act', 1, str_repeat('c', 64), 100, new RuntimeException('initial audit failure'));

        self::assertSame(0, $crashing->reconcile());
        self::assertSame(0.0, (float) $this->first->table('contracts')->where('id', $contractId)->value('total_amount'));
        self::assertNull($this->first->table('contract_audit_reconciliation_debts')->value('resolved_at'));
        $this->first->table('contract_audit_reconciliation_debts')->update(['available_at' => now()->subSecond()]);
        self::assertSame(1, (new ContractAuditReconciliationService($this->first, $mutations))->reconcile());
        self::assertSame(150.0, (float) $this->first->table('contracts')->where('id', $contractId)->value('total_amount'));
        self::assertNotNull($this->first->table('contract_audit_reconciliation_debts')->value('resolved_at'));
        self::assertSame($audit->events[0], $audit->events[1]);
    }

    public function test_phase_b_dual_lookup_reuses_legacy_raw_and_namespaces_new_source_with_stable_outbox(): void
    {
        $contractId = (int) $this->first->table('contracts')->insertGetId([
            'organization_id' => 7, 'number' => 'PG-IDEMP', 'is_fixed_amount' => true, 'total_amount' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $contract = (new Contract)->setConnection('legal_pg_first')->newQuery()->findOrFail($contractId);
        $integrity = new ImmutableAuditIntegrityService;
        $service = new LegalDocumentAuditService(
            new ImmutableAuditRecorder(new ImmutableAuditRedactor, $integrity, $this->first),
            new LegalDocumentOutbox(dispatchJobs: false, connection: $this->first),
            $this->first,
        );
        $service->recordContractForActorId('legacy_retry', $contract, null, ['source_event_id' => 'external:legacy']);
        $legacyEvent = (string) $this->first->table('immutable_audit_events')->value('id');
        $legacyOutbox = (string) $this->first->table('legal_document_outbox')->value('id');
        (new ImmutableAuditRolloutService)->cutover($this->first, true, 2);
        $service->recordContractForActorId('legacy_retry', $contract, null, ['source_event_id' => 'external:legacy']);
        self::assertSame($legacyEvent, (string) $this->first->table('immutable_audit_events')->where('source_event_id', 'external:legacy')->value('id'));
        self::assertSame($legacyOutbox, (string) $this->first->table('legal_document_outbox')->where('id', $legacyOutbox)->value('id'));

        $service->recordContractForActorId('modern_retry', $contract, null, ['source_event_id' => 'external:modern']);
        $service->recordContractForActorId('modern_retry', $contract, null, ['source_event_id' => 'external:modern']);
        self::assertSame(1, $this->first->table('immutable_audit_events')->where('source_event_id', "contract:{$contractId}:external:modern")->count());
        self::assertSame(2, $this->first->table('legal_document_outbox')->count());
    }

    public function test_append_only_trigger_rejects_mutation_of_real_recorder_event(): void
    {
        $event = (new ImmutableAuditRecorder(
            new ImmutableAuditRedactor,
            new ImmutableAuditIntegrityService,
            $this->first,
        ))->record($this->event('created', 'source:1'));

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->first->table('immutable_audit_events')->where('id', $event->id)->update(['action' => 'tampered']);
    }

    public function test_real_rollout_phases_preserve_global_index_until_explicit_writer_fence(): void
    {
        $rollout = new ImmutableAuditRolloutService;
        self::assertSame('phase_a', $this->first->table('immutable_audit_rollout')->value('phase'));
        self::assertSame(1, (int) $this->first->selectOne("SELECT COUNT(*) AS value FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'immutable_audit_source_event_unique'")->value);

        try {
            $rollout->cutover($this->first, false, 2);
            self::fail('Cutover must require an explicit deployment fence.');
        } catch (RuntimeException $error) {
            self::assertSame('immutable_audit_phase_b_writer_fence_not_confirmed', $error->getMessage());
        }

        $rollout->cutover($this->first, true, 2);
        $rollout->cutover($this->first, true, 2);
        self::assertSame('phase_b', $this->first->table('immutable_audit_rollout')->value('phase'));
        self::assertSame(0, (int) $this->first->selectOne("SELECT COUNT(*) AS value FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'immutable_audit_source_event_unique'")->value);
        self::assertSame(1, (int) $this->first->selectOne("SELECT COUNT(*) AS value FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'immutable_audit_source_event_aggregate_unique'")->value);
    }

    private function event(string $action, string $sourceEventId): ImmutableAuditEventData
    {
        return new ImmutableAuditEventData(
            organizationId: 7,
            domain: 'legal_archive',
            eventType: 'legal_document.'.$action,
            action: $action,
            source: 'legal_archive',
            projectId: 3,
            sourceEventId: $sourceEventId,
            subjectType: 'legal_document',
            subjectId: 42,
            afterState: ['status' => $action],
            chainScope: 'organization:7:legal_document:42',
        );
    }

    /** @return array<string, mixed> */
    private function connectionConfig(string $dsn): array
    {
        $parts = [];
        foreach (explode(';', preg_replace('/^pgsql:/', '', $dsn) ?? '') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if (is_string($key) && is_string($value)) {
                $parts[$key] = $value;
            }
        }

        return [
            'driver' => 'pgsql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? '5432',
            'database' => $parts['dbname'] ?? '',
            'username' => (string) getenv('LEGAL_DOCUMENT_PG_TEST_USER'),
            'password' => (string) getenv('LEGAL_DOCUMENT_PG_TEST_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
        ];
    }

    private function installSchema(): void
    {
        $this->first->unprepared(<<<'SQL'
CREATE TABLE immutable_audit_events (
    id uuid PRIMARY KEY, sequence_id bigint NOT NULL UNIQUE, organization_id bigint NOT NULL,
    project_id bigint NULL, domain text NOT NULL, event_type text NOT NULL, action text NOT NULL,
    result text NOT NULL, severity text NOT NULL, occurred_at timestamptz NOT NULL, recorded_at timestamptz NOT NULL,
    actor_type text NOT NULL, actor_user_id bigint NULL, actor_snapshot jsonb NULL, impersonator_user_id bigint NULL,
    source text NOT NULL, source_route text NULL, source_model text NULL, source_table text NULL,
    source_event_id text NULL, correlation_id text NULL, idempotency_key text NULL, subject_type text NULL,
    subject_id text NULL, subject_label text NULL, related_subjects jsonb NULL, reason text NULL,
    before_state jsonb NULL, after_state jsonb NULL, diff jsonb NULL, domain_context jsonb NULL,
    sensitive_fields jsonb NULL, redaction_policy_version text NOT NULL, payload_hash char(64) NOT NULL,
    previous_hash char(64) NULL, record_hash char(64) NOT NULL, chain_scope text NOT NULL,
    chain_version smallint NOT NULL, sealed_at timestamptz NULL, seal_id uuid NULL,
    integrity_status text NOT NULL, retention_until timestamptz NOT NULL, created_at timestamptz NOT NULL
);
CREATE UNIQUE INDEX immutable_audit_source_event_unique
ON immutable_audit_events (organization_id, domain, source, source_event_id)
WHERE source_event_id IS NOT NULL;
CREATE FUNCTION immutable_audit_prevent_mutation() RETURNS trigger AS $$
BEGIN RAISE EXCEPTION 'immutable audit records are append-only'; END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER immutable_audit_events_append_only BEFORE UPDATE OR DELETE ON immutable_audit_events
FOR EACH ROW EXECUTE FUNCTION immutable_audit_prevent_mutation();
CREATE TABLE legal_document_outbox (
    id uuid PRIMARY KEY, organization_id bigint NOT NULL, aggregate_type text NOT NULL, aggregate_id text NOT NULL,
    event text NOT NULL, payload jsonb NOT NULL, payload_hash char(64) NOT NULL, idempotency_key text NOT NULL,
    attempts integer NOT NULL DEFAULT 0, available_at timestamptz NOT NULL, published_at timestamptz NULL,
    last_error text NULL, claim_token uuid NULL, claimed_at timestamptz NULL, dead_lettered_at timestamptz NULL,
    reconciliation_required_at timestamptz NULL, created_at timestamptz NULL, updated_at timestamptz NULL,
    UNIQUE (organization_id, aggregate_type, aggregate_id, idempotency_key)
);
CREATE TABLE contracts (
    id bigserial PRIMARY KEY, organization_id bigint NOT NULL, number text NOT NULL,
    is_fixed_amount boolean NOT NULL DEFAULT true, total_amount numeric(20,4) NOT NULL DEFAULT 0,
    created_at timestamptz NULL, updated_at timestamptz NULL, deleted_at timestamptz NULL
);
CREATE TABLE contract_performance_acts (
    id bigserial PRIMARY KEY, contract_id bigint NOT NULL, amount numeric(20,4) NOT NULL,
    is_approved boolean NOT NULL DEFAULT false, created_at timestamptz NULL, updated_at timestamptz NULL
);
CREATE TABLE supplementary_agreements (
    id bigserial PRIMARY KEY, contract_id bigint NOT NULL, change_amount numeric(20,4) NOT NULL,
    created_at timestamptz NULL, updated_at timestamptz NULL, deleted_at timestamptz NULL
);
CREATE TABLE contract_audit_reconciliation_debts (
    id uuid PRIMARY KEY, organization_id bigint NULL, contract_id bigint NOT NULL, source_type text NOT NULL,
    source_id text NOT NULL, change_fingerprint char(64) NOT NULL, expected_total_amount numeric(20,4) NULL,
    entity_context jsonb NOT NULL, last_error text NOT NULL, attempts integer NOT NULL DEFAULT 0,
    available_at timestamptz NOT NULL, claim_token uuid NULL, claimed_at timestamptz NULL,
    resolved_at timestamptz NULL, dead_lettered_at timestamptz NULL, created_at timestamptz NULL, updated_at timestamptz NULL,
    UNIQUE(source_type, source_id, change_fingerprint)
);
SQL);
        (new ImmutableAuditRolloutService)->installCompatibilityPhase($this->first);
    }

    /** @return array{string, string} */
    private function independentHashes(ImmutableAuditEvent $event, ?string $previousHash): array
    {
        $fields = [
            'sequence_id', 'organization_id', 'project_id', 'domain', 'event_type', 'action', 'result', 'severity',
            'occurred_at', 'recorded_at', 'actor_type', 'actor_user_id', 'actor_snapshot', 'impersonator_user_id',
            'source', 'source_route', 'source_model', 'source_table', 'source_event_id', 'correlation_id', 'idempotency_key',
            'subject_type', 'subject_id', 'subject_label', 'related_subjects', 'reason', 'before_state', 'after_state',
            'diff', 'domain_context', 'sensitive_fields', 'redaction_policy_version', 'chain_scope', 'chain_version',
            'sealed_at', 'seal_id', 'integrity_status', 'retention_until', 'created_at',
        ];
        $payload = [];
        foreach ($fields as $field) {
            $payload[$field] = $this->canonicalValue($event->getAttribute($field));
        }
        $payloadHash = hash('sha256', json_encode($this->canonicalValue($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $record = $this->canonicalValue([
            'sequence_id' => $event->sequence_id, 'chain_scope' => $event->chain_scope, 'chain_version' => $event->chain_version,
            'payload_hash' => $payloadHash, 'previous_hash' => $previousHash,
        ]);

        return [$payloadHash, hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR))];
    }

    private function canonicalValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::instance($value)->utc()->format(\DateTimeInterface::ATOM);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }

        return $value;
    }
}

final class PostgresRecordingContractAudit implements LegalDocumentAudit
{
    /** @var list<string> */
    public array $events = [];

    public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void {}

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

    public function recordContractForActorId(string $event, Contract $contract, ?int $actorId, array $context = []): void
    {
        $this->events[] = (string) ($context['source_event_id'] ?? '');
    }
}

final class PostgresCrashAfterDeliveryPublisher implements LegalDocumentOutboxPublisher
{
    /** @var list<string> */
    public array $attempts = [];

    /** @var array<string, true> */
    public array $received = [];

    public function publish(LegalDocumentOutboxMessage $message): void
    {
        $id = (string) $message->id;
        $this->attempts[] = $id;
        $this->received[$id] = true;
        if (count($this->attempts) === 1) {
            throw new RuntimeException('publisher crashed after delivery');
        }
    }
}
