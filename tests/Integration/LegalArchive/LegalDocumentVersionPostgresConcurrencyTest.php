<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class LegalDocumentVersionPostgresConcurrencyTest extends TestCase
{
    public function test_two_connections_serialize_logical_file_version_switch(): void
    {
        if (getenv('LEGAL_ARCHIVE_PG_CONCURRENCY') !== '1') {
            self::markTestSkipped('Explicit PostgreSQL concurrency opt-in is required.');
        }

        $dsn = (string) getenv('LEGAL_ARCHIVE_PG_DSN');
        $user = (string) getenv('LEGAL_ARCHIVE_PG_USER');
        $password = (string) getenv('LEGAL_ARCHIVE_PG_PASSWORD');
        self::assertNotSame('', $dsn);

        $first = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $second = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $table = 'legal_file_concurrency_'.bin2hex(random_bytes(6));

        try {
            $first->exec("CREATE TABLE {$table} (id bigint PRIMARY KEY, current_version_id bigint NULL)");
            $first->exec("INSERT INTO {$table} (id) VALUES (1)");
            $first->beginTransaction();
            $first->query("SELECT id FROM {$table} WHERE id = 1 FOR UPDATE");
            $second->beginTransaction();

            try {
                $second->query("SELECT id FROM {$table} WHERE id = 1 FOR UPDATE NOWAIT");
                self::fail('Second connection must not acquire the logical-file lock.');
            } catch (PDOException $exception) {
                self::assertSame('55P03', $exception->getCode());
            } finally {
                $second->rollBack();
            }

            $first->commit();
        } finally {
            if ($first->inTransaction()) {
                $first->rollBack();
            }
            if ($second->inTransaction()) {
                $second->rollBack();
            }
            $first->exec("DROP TABLE IF EXISTS {$table}");
        }
    }
}
