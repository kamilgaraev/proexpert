<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class LegalWorkflowPostgresConcurrencyTest extends TestCase
{
    public function test_instance_lock_serializes_parallel_decisions_and_production_guards_are_valid(): void
    {
        if (getenv('LEGAL_ARCHIVE_PG_WORKFLOW_CONCURRENCY') !== '1') {
            self::markTestSkipped('Set LEGAL_ARCHIVE_PG_WORKFLOW_CONCURRENCY=1 for the isolated PostgreSQL gate.');
        }
        $dsn = (string) getenv('LEGAL_ARCHIVE_PG_DSN');
        $user = (string) getenv('LEGAL_ARCHIVE_PG_USER');
        $password = (string) getenv('LEGAL_ARCHIVE_PG_PASSWORD');
        $instanceId = filter_var(getenv('LEGAL_ARCHIVE_PG_WORKFLOW_INSTANCE_ID'), FILTER_VALIDATE_INT);
        if ($dsn === '' || $instanceId === false) {
            self::markTestSkipped('An isolated existing workflow fixture and PostgreSQL DSN are required.');
        }
        $first = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $second = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $first->beginTransaction();
        $second->beginTransaction();
        try {
            $lock = $first->prepare('SELECT id FROM legal_workflow_instances WHERE id = ? FOR UPDATE NOWAIT');
            $lock->execute([$instanceId]);
            $second->exec("SET LOCAL lock_timeout = '250ms'");
            $contender = $second->prepare('SELECT id FROM legal_workflow_instances WHERE id = ? FOR UPDATE NOWAIT');
            try {
                $contender->execute([$instanceId]);
                self::fail('A parallel decision acquired the same instance lock.');
            } catch (PDOException $exception) {
                self::assertContains($exception->getCode(), ['55P03', 'HY000']);
            }

            $guardQuery = $first->query(<<<'SQL'
SELECT c.relname, i.indisvalid, i.indisready
FROM pg_class c
JOIN pg_index i ON i.indexrelid = c.oid
WHERE c.relname IN ('legal_workflow_instances_active_unique', 'legal_workflow_decisions_terminal_unique')
ORDER BY c.relname
SQL);
            $guards = $guardQuery->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(2, $guards);
            self::assertSame([true], array_values(array_unique(array_map(static fn (array $row): bool => (bool) $row['indisvalid'] && (bool) $row['indisready'], $guards))));
        } finally {
            if ($second->inTransaction()) {
                $second->rollBack();
            }
            if ($first->inTransaction()) {
                $first->rollBack();
            }
        }
    }
}
