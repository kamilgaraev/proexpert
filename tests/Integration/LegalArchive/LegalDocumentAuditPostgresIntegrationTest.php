<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use PDO;
use PHPUnit\Framework\TestCase;

final class LegalDocumentAuditPostgresIntegrationTest extends TestCase
{
    private PDO $first;

    private PDO $second;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('LEGAL_DOCUMENT_PG_TEST_DSN');
        if (! is_string($dsn) || $dsn === '' || getenv('LEGAL_DOCUMENT_PG_TEST_ALLOW_DDL') !== '1') {
            self::markTestSkipped('Dedicated PostgreSQL integration database is not enabled.');
        }
        $user = (string) getenv('LEGAL_DOCUMENT_PG_TEST_USER');
        $password = (string) getenv('LEGAL_DOCUMENT_PG_TEST_PASSWORD');
        $this->first = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->second = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database = (string) $this->first->query('SELECT current_database()')->fetchColumn();
        if (preg_match('/(?:_test|_testing)$/D', $database) !== 1) {
            self::markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }
        $this->schema = 'legal_doc_it_'.bin2hex(random_bytes(6));
        $this->first->exec("CREATE SCHEMA {$this->schema}");
        $this->first->exec("SET search_path TO {$this->schema}");
        $this->second->exec("SET search_path TO {$this->schema}");
        $this->installSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->first, $this->schema) && str_starts_with($this->schema, 'legal_doc_it_')) {
            $this->first->exec("DROP SCHEMA {$this->schema} CASCADE");
        }
        parent::tearDown();
    }

    public function test_two_connections_guard_chain_and_reject_raw_mutation(): void
    {
        $this->first->beginTransaction();
        self::assertSame('1', $this->first->query("SELECT pg_try_advisory_xact_lock(hashtextextended('org:1:doc:1', 0))::int")->fetchColumn());
        self::assertSame('0', $this->second->query("SELECT pg_try_advisory_xact_lock(hashtextextended('org:1:doc:1', 0))::int")->fetchColumn());
        $this->first->exec("INSERT INTO immutable_audit_events (chain_scope, record_hash) VALUES ('org:1:doc:1', repeat('a', 64))");
        $this->first->commit();

        $this->second->beginTransaction();
        self::assertSame('1', $this->second->query("SELECT pg_try_advisory_xact_lock(hashtextextended('org:1:doc:1', 0))::int")->fetchColumn());
        $previous = $this->second->query("SELECT record_hash FROM immutable_audit_events WHERE chain_scope = 'org:1:doc:1' ORDER BY sequence_id DESC LIMIT 1")->fetchColumn();
        self::assertSame(str_repeat('a', 64), $previous);
        $this->second->commit();

        $this->expectException(\PDOException::class);
        $this->first->exec("UPDATE immutable_audit_events SET record_hash = repeat('b', 64)");
    }

    public function test_claim_skip_locked_and_sequence_cutover_are_concurrency_safe(): void
    {
        $this->first->exec("INSERT INTO legal_document_outbox (id, organization_id) VALUES ('00000000-0000-4000-8000-000000000001', 7)");
        $this->first->beginTransaction();
        $this->first->query('SELECT id FROM legal_document_outbox WHERE published_at IS NULL FOR UPDATE')->fetchColumn();
        $this->second->beginTransaction();
        self::assertFalse($this->second->query('SELECT id FROM legal_document_outbox WHERE published_at IS NULL FOR UPDATE SKIP LOCKED')->fetchColumn());
        $this->second->rollBack();
        $this->first->rollBack();

        $this->first->exec("INSERT INTO immutable_audit_events (sequence_id, chain_scope, record_hash) VALUES (50, 'legacy', repeat('c', 64))");
        $next = (int) $this->second->query("SELECT nextval('immutable_audit_sequence')")->fetchColumn();
        self::assertGreaterThan(50, $next);
        $this->second->beginTransaction();
        $rolledBack = (int) $this->second->query("SELECT nextval('immutable_audit_sequence')")->fetchColumn();
        $this->second->rollBack();
        self::assertGreaterThan($rolledBack, (int) $this->first->query("SELECT nextval('immutable_audit_sequence')")->fetchColumn());
    }

    public function test_receiver_deduplicates_crash_replay_by_stable_message_id(): void
    {
        $messageId = '00000000-0000-4000-8000-000000000009';
        $statement = $this->first->prepare('INSERT INTO receiver_messages (message_id) VALUES (?) ON CONFLICT DO NOTHING');
        $statement->execute([$messageId]);
        $statement->execute([$messageId]);

        self::assertSame('1', $this->first->query('SELECT count(*) FROM receiver_messages')->fetchColumn());
        self::assertSame($messageId, $this->first->query('SELECT message_id FROM receiver_messages')->fetchColumn());
    }

    private function installSchema(): void
    {
        $this->first->exec(<<<'SQL'
CREATE SEQUENCE immutable_audit_sequence;
CREATE TABLE immutable_audit_events (
    sequence_id bigint PRIMARY KEY DEFAULT nextval('immutable_audit_sequence'),
    chain_scope text NOT NULL,
    record_hash char(64) NOT NULL
);
CREATE FUNCTION immutable_audit_sequence_sync() RETURNS trigger AS $$
DECLARE current_value bigint;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('immutable_audit_sequence_sync', 0));
    SELECT last_value INTO current_value FROM immutable_audit_sequence;
    IF NEW.sequence_id > current_value THEN
        PERFORM setval('immutable_audit_sequence', NEW.sequence_id, true);
    ELSE
        NEW.sequence_id := nextval('immutable_audit_sequence');
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER immutable_audit_sequence_sync BEFORE INSERT ON immutable_audit_events
FOR EACH ROW EXECUTE FUNCTION immutable_audit_sequence_sync();
CREATE FUNCTION immutable_audit_prevent_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'immutable audit records are append-only';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER immutable_audit_events_append_only BEFORE UPDATE OR DELETE ON immutable_audit_events
FOR EACH ROW EXECUTE FUNCTION immutable_audit_prevent_mutation();
CREATE TABLE legal_document_outbox (
    id uuid PRIMARY KEY,
    organization_id bigint NOT NULL,
    published_at timestamptz NULL
);
CREATE TABLE receiver_messages (message_id uuid PRIMARY KEY);
SQL);
    }
}
