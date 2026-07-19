<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRedactor;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentOutboxMessage;
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
CREATE SEQUENCE immutable_audit_sequence;
CREATE FUNCTION immutable_audit_allocate_sequence() RETURNS bigint AS $$
BEGIN RETURN nextval('immutable_audit_sequence'); END;
$$ LANGUAGE plpgsql VOLATILE;
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
CREATE UNIQUE INDEX immutable_audit_source_event_aggregate_unique
ON immutable_audit_events (organization_id, domain, subject_type, subject_id, source, source_event_id)
WHERE source_event_id IS NOT NULL AND subject_type IS NOT NULL AND subject_id IS NOT NULL;
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
SQL);
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
