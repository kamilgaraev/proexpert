<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class LegalDocumentVersionPostgresConcurrencyTest extends TestCase
{
    private PDO $first;

    private PDO $second;

    private int $documentFileId;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('LEGAL_ARCHIVE_PG_CONCURRENCY') !== '1') {
            self::markTestSkipped('Explicit PostgreSQL concurrency opt-in is required.');
        }

        $dsn = (string) getenv('LEGAL_ARCHIVE_PG_DSN');
        $user = (string) getenv('LEGAL_ARCHIVE_PG_USER');
        $password = (string) getenv('LEGAL_ARCHIVE_PG_PASSWORD');
        $this->documentFileId = (int) getenv('LEGAL_ARCHIVE_PG_DOCUMENT_FILE_ID');
        self::assertNotSame('', $dsn);
        self::assertGreaterThan(0, $this->documentFileId);

        $this->first = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->second = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database = (string) $this->first->query('SELECT current_database()')->fetchColumn();
        self::assertMatchesRegularExpression(
            '/(?:test|staging|sandbox)/i',
            $database,
            'The opt-in contract is forbidden outside an isolated test/staging database.',
        );
    }

    protected function tearDown(): void
    {
        foreach (isset($this->first, $this->second) ? [$this->first, $this->second] : [] as $connection) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }

        parent::tearDown();
    }

    public function test_actual_migrations_constraints_indexes_and_trigger_are_active(): void
    {
        foreach (['000200', '000210', '000220', '000230', '000240'] as $phase) {
            $statement = $this->first->prepare('SELECT COUNT(*) FROM migrations WHERE migration LIKE :phase');
            $statement->execute(['phase' => "%{$phase}%"]);
            self::assertSame(1, (int) $statement->fetchColumn(), "Migration phase {$phase} is missing.");
        }

        $trigger = $this->first->query(
            "SELECT COUNT(*) FROM pg_trigger WHERE tgname = 'legal_archive_versions_immutable_guard' AND tgenabled <> 'D'"
        );
        self::assertSame(1, (int) $trigger->fetchColumn());

        foreach (['legal_archive_document_file_current_unique', 'legal_archive_document_file_versions_unique'] as $index) {
            $statement = $this->first->prepare(
                'SELECT COUNT(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid '
                .'WHERE c.relname = :name AND i.indisvalid = true AND i.indisready = true'
            );
            $statement->execute(['name' => $index]);
            self::assertSame(1, (int) $statement->fetchColumn(), "Index {$index} is not ready.");
        }

        $constraints = $this->first->query(
            'SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE convalidated = false) AS invalid '
            ."FROM pg_constraint WHERE conname IN ('legal_archive_document_files_document_fk', "
            ."'legal_archive_versions_document_file_fk', 'legal_archive_document_files_current_fk', "
            ."'legal_archive_versions_processing_status_check')"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($constraints);
        self::assertSame(4, (int) $constraints['total']);
        self::assertSame(0, (int) $constraints['invalid']);
    }

    public function test_two_connections_serialize_actual_logical_file_and_preserve_numbering_current_invariants(): void
    {
        $this->first->beginTransaction();
        $lock = $this->first->prepare(
            'SELECT id, current_version_id FROM legal_archive_document_files WHERE id = :id FOR UPDATE'
        );
        $lock->execute(['id' => $this->documentFileId]);
        $lockedFile = $lock->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($lockedFile);
        $currentVersionId = $lockedFile['current_version_id'];

        $this->second->beginTransaction();
        try {
            $blocked = $this->second->prepare(
                'SELECT id FROM legal_archive_document_files WHERE id = :id FOR UPDATE NOWAIT'
            );
            $blocked->execute(['id' => $this->documentFileId]);
            self::fail('Second connection must not acquire the actual logical-file lock.');
        } catch (PDOException $exception) {
            self::assertSame('55P03', $exception->getCode());
        } finally {
            $this->second->rollBack();
        }

        $versions = $this->first->prepare(
            'SELECT id, version_number, is_current FROM legal_archive_document_versions '
            .'WHERE document_file_id = :id ORDER BY id'
        );
        $versions->execute(['id' => $this->documentFileId]);
        $rows = $versions->fetchAll(PDO::FETCH_ASSOC);
        $numbers = array_column($rows, 'version_number');
        self::assertCount(count(array_unique($numbers)), $numbers);
        $current = array_values(array_filter($rows, static fn (array $row): bool => (bool) $row['is_current']));
        self::assertLessThanOrEqual(1, count($current));
        if ($currentVersionId === null) {
            self::assertSame([], $current);
        } else {
            self::assertCount(1, $current);
            self::assertSame((int) $currentVersionId, (int) $current[0]['id']);
        }

        $numeric = array_filter($numbers, static fn (string $number): bool => ctype_digit($number));
        $expectedNext = $numeric === [] ? 1 : max(array_map('intval', $numeric)) + 1;
        self::assertGreaterThan(0, $expectedNext);
        $this->first->rollBack();
    }

    public function test_immutable_trigger_rejects_unauthorized_actual_version_mutation(): void
    {
        $versionId = (int) getenv('LEGAL_ARCHIVE_PG_VERSION_ID');
        self::assertGreaterThan(0, $versionId);
        $this->first->beginTransaction();

        try {
            $statement = $this->first->prepare(
                'UPDATE legal_archive_document_versions SET updated_at = updated_at WHERE id = :id'
            );
            $statement->execute(['id' => $versionId]);
            self::fail('Actual immutable trigger must reject unauthorized mutation.');
        } catch (PDOException $exception) {
            self::assertSame('P0001', $exception->getCode());
        } finally {
            $this->first->rollBack();
        }
    }
}
