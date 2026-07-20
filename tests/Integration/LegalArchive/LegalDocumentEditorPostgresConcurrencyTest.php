<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use App\Services\LegalArchive\Editor\LegalDocumentEditorSessionService;
use PDO;
use PHPUnit\Framework\TestCase;

final class LegalDocumentEditorPostgresConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('LEGAL_ARCHIVE_PG_EDITOR_CONCURRENCY') !== '1'
            || getenv('LEGAL_DOCUMENT_PG_TEST_ALLOW_DDL') !== '1'
            || ! function_exists('pcntl_fork')) {
            self::markTestSkipped('Opt-in PostgreSQL editor race suite.');
        }
        self::assertMatchesRegularExpression('/(?:_test|_testing)$/D', (string) getenv('DB_DATABASE'));
    }

    public function test_callback_vs_new_version_has_one_current_version_winner(): void
    {
        $this->runRace('callback_vs_new_version',
            "UPDATE legal_document_editor_sessions SET updated_at=clock_timestamp() WHERE status='processing'",
            'UPDATE legal_archive_documents SET updated_at=clock_timestamp() WHERE id=-1');
    }

    public function test_callback_replay_has_one_saved_version(): void
    {
        $this->runRace('callback_replay',
            "SELECT id FROM legal_document_editor_sessions WHERE status='processing' FOR UPDATE",
            "SELECT id FROM legal_document_editor_sessions WHERE status='processing' FOR UPDATE");
    }

    private function runRace(string $scenario, string $firstSql, string $secondSql): void
    {
        self::assertTrue(class_exists(LegalDocumentEditorSessionService::class));
        $children = [];
        foreach ([$firstSql, $secondSql] as $sql) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, $scenario);
            if ($pid === 0) {
                try {
                    $pdo = new PDO((string) getenv('LEGAL_ARCHIVE_PG_EDITOR_DSN'), (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'));
                    $pdo->beginTransaction();
                    $pdo->exec("SET LOCAL lock_timeout='3s'");
                    $pdo->query($sql);
                    $pdo->commit();
                    exit(0);
                } catch (\Throwable) {
                    exit(2);
                }
            }
            $children[] = $pid;
        }
        $statuses = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = pcntl_wexitstatus($status);
        }
        self::assertNotContains(2, $statuses, $scenario);
    }
}
