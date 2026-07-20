<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\Contract;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Editor\DownloadedEditorDocument;
use App\Services\LegalArchive\Editor\EditorCallbackInput;
use App\Services\LegalArchive\Editor\EditorDocumentContext;
use App\Services\LegalArchive\Editor\EditorDocumentFetcher;
use App\Services\LegalArchive\Editor\EditorSessionPayload;
use App\Services\LegalArchive\Editor\LegalDocumentEditGuard;
use App\Services\LegalArchive\Editor\LegalDocumentEditor;
use App\Services\LegalArchive\Editor\LegalDocumentEditorSessionService;
use App\Services\LegalArchive\Files\LegalDocumentDownloadService;
use App\Services\LegalArchive\Files\LegalDocumentFilePolicy;
use App\Services\LegalArchive\Files\LegalDocumentFileService;
use App\Services\LegalArchive\Files\LegalDocumentScanner;
use App\Services\LegalArchive\LegalDocumentAggregateLock;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class LegalDocumentEditorPostgresConcurrencyTest extends TestCase
{
    private Capsule $database;

    private ConnectionInterface $first;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('LEGAL_DOCUMENT_PG_TEST_DSN');
        if (getenv('LEGAL_ARCHIVE_PG_EDITOR_CONCURRENCY') !== '1'
            || getenv('LEGAL_DOCUMENT_PG_TEST_ALLOW_DDL') !== '1'
            || ! is_string($dsn) || $dsn === ''
            || ! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            self::markTestSkipped('Dedicated PostgreSQL editor concurrency contract database is not enabled.');
        }
        $this->database = new Capsule;
        $this->database->addConnection($this->connectionConfig($dsn), 'editor_first');
        $this->database->addConnection($this->connectionConfig($dsn), 'editor_second');
        $this->database->setAsGlobal();
        $container = new Container;
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('config', new Repository([
            'legal-document-editor' => [
                'callback_base_url' => 'https://api.example.test',
                'session_ttl_minutes' => 120,
                'source_url_ttl_minutes' => 10,
            ],
        ]));
        Facade::setFacadeApplication($container);
        $this->database->getDatabaseManager()->setDefaultConnection('editor_first');
        $this->first = $this->database->getConnection('editor_first');
        $database = (string) $this->first->selectOne('SELECT current_database() name')->name;
        if (preg_match('/(?:_test|_testing)$/D', $database) !== 1) {
            self::markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }
        $this->schema = 'legal_editor_it_'.bin2hex(random_bytes(6));
        $this->first->statement("CREATE SCHEMA {$this->schema}");
        foreach (['editor_first', 'editor_second'] as $name) {
            $this->database->getConnection($name)->statement("SET search_path TO {$this->schema}");
        }
        $this->installBaseSchema();
        foreach (['000700_create_legal_document_editor_sessions', '000710_create_legal_document_editor_session_indexes',
            '000720_add_legal_document_editor_session_constraints', '000730_validate_legal_document_editor_session_constraints'] as $suffix) {
            (require dirname(__DIR__, 3)."/database/migrations/2026_07_19_{$suffix}.php")->up();
        }
        $this->seedAggregate();
    }

    protected function tearDown(): void
    {
        if (isset($this->first, $this->schema) && str_starts_with($this->schema, 'legal_editor_it_')) {
            $this->first->statement("DROP SCHEMA {$this->schema} CASCADE");
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_parallel_service_open_serializes_generation_without_aggregate_for_update(): void
    {
        $outcomes = $this->forkWorkers(function (ConnectionInterface $connection): void {
            $version = (new LegalArchiveDocumentVersion)->setConnection($connection->getName())->newQuery()->findOrFail(1);
            $actor = (new User)->setConnection($connection->getName())->newQuery()->findOrFail(1);
            $this->service($connection)->open($version, $actor);
        });

        self::assertSame([0, 0], $outcomes);
        self::assertSame(1, $this->first->table('legal_document_editor_sessions')->count());
        self::assertSame(1, (int) $this->first->table('legal_document_editor_sessions')->value('generation'));
        self::assertSame(1, $this->first->table('legal_document_editor_participants')->count());
        $source = file_get_contents(dirname(__DIR__, 3).'/app/Services/LegalArchive/Editor/LegalDocumentEditorSessionService.php');
        self::assertIsString($source);
        self::assertStringNotContainsString("lockForUpdate()->max('generation')", $source);
    }

    public function test_database_rejects_second_participant_for_the_same_session(): void
    {
        $this->openSession();
        $session = $this->first->table('legal_document_editor_sessions')->sole();
        try {
            $this->first->table('legal_document_editor_participants')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => 1, 'editor_session_id' => $session->id,
                'actor_key' => hash('sha256', 'second-actor'), 'user_id' => 1, 'provider_user_id' => 'second',
                'required_ability' => 'edit', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            self::fail('A persistent editor session must have exactly one participant identity.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('legal_editor_participants_session_unique', $exception->getMessage());
        }
        self::assertSame(1, $this->first->table('legal_document_editor_participants')->count());
    }

    public function test_callback_replay_creates_one_completed_save(): void
    {
        $payload = $this->openSession();
        $session = $this->first->table('legal_document_editor_sessions')->sole();
        $input = new EditorCallbackInput((string) $session->id, $payload->documentKey, 4, null, 'terminal-replay', 'token');

        $outcomes = $this->forkWorkers(function (ConnectionInterface $connection) use ($input): void {
            $this->service($connection)->handleCallback($input);
        });

        self::assertSame([0, 0], $outcomes);
        self::assertSame(1, $this->first->table('legal_document_editor_saves')->count());
        $save = $this->first->table('legal_document_editor_saves')->sole();
        self::assertSame('completed', $save->state);
        self::assertSame(4, (int) $save->callback_status);
        self::assertTrue((bool) $save->terminal);
        self::assertNull($save->saved_version_id);
        self::assertSame('closed', $this->first->table('legal_document_editor_sessions')->value('status'));
    }

    public function test_parallel_save_callback_has_one_lease_owner_and_one_generation(): void
    {
        $payload = $this->openSession();
        $session = $this->first->table('legal_document_editor_sessions')->sole();
        $gate = 'editor-fetch-'.bin2hex(random_bytes(6));
        $this->first->select('SELECT pg_advisory_lock(hashtextextended(?,0))', [$gate]);
        $input = new EditorCallbackInput((string) $session->id, $payload->documentKey, 6,
            'https://office.example.test/save.docx', 'same-save', 'token');

        $outcomes = $this->forkWorkers(function (ConnectionInterface $connection) use ($input, $gate): void {
            $fetcher = new class($connection, $gate) implements EditorDocumentFetcher
            {
                public function __construct(private ConnectionInterface $connection, private string $gate) {}

                public function fetch(string $url, string $expectedExtension): DownloadedEditorDocument
                {
                    $this->connection->table('editor_test_waiters')->insert(['gate_key' => $this->gate]);
                    $this->connection->select('SELECT pg_advisory_lock_shared(hashtextextended(?,0))', [$this->gate]);
                    $this->connection->select('SELECT pg_advisory_unlock_shared(hashtextextended(?,0))', [$this->gate]);
                    throw new RuntimeException('editor_test_fetch_stopped');
                }
            };
            $this->service($connection, $fetcher)->handleCallback($input);
        }, false, function () use ($gate): void {
            $deadline = microtime(true) + 5;
            while ($this->first->table('editor_test_waiters')->count() < 1 && microtime(true) < $deadline) {
                usleep(10_000);
            }
            usleep(200_000);
            $this->first->select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$gate]);
        });

        self::assertSame([20, 21], $outcomes);
        self::assertSame(1, $this->first->table('legal_document_editor_saves')->count());
        $save = $this->first->table('legal_document_editor_saves')->sole();
        self::assertSame(1, (int) $save->save_generation);
        self::assertSame(hash('sha256', 'same-save'), $save->replay_hash);
        self::assertNull($save->saved_version_id);
        self::assertSame(2, (int) $this->first->table('legal_document_editor_sessions')->value('next_save_generation'));
    }

    public function test_callback_force_save_then_final_save_keeps_an_ordered_append_only_ledger(): void
    {
        $payload = $this->openSession();
        $session = $this->first->table('legal_document_editor_sessions')->sole();
        $resolved = null;
        foreach ([[6, 'save-1', 'first'], [6, 'save-2', 'second'], [2, 'save-3', 'final']] as [$status, $replay, $body]) {
            $resolved = $this->service($this->first, $this->successfulFetcher($body))->handleCallback(new EditorCallbackInput(
                (string) $session->id, $payload->documentKey, $status,
                'https://office.example.test/document.docx', $replay, 'token',
            ));
        }

        self::assertInstanceOf(LegalArchiveDocumentVersion::class, $resolved);
        self::assertSame(4, (int) $resolved->id);
        self::assertSame([6, 6, 2], $this->first->table('legal_document_editor_saves')
            ->orderBy('save_generation')->pluck('callback_status')->map(static fn (mixed $value): int => (int) $value)->all());
        self::assertSame(3, $this->first->table('legal_document_editor_saves')->count());
    }

    public function test_failed_terminal_save_is_replaced_by_new_signed_callback_generation(): void
    {
        $payload = $this->openSession();
        $session = $this->first->table('legal_document_editor_sessions')->sole();
        $failed = new EditorCallbackInput((string) $session->id, $payload->documentKey, 2,
            'https://office.example.test/expired.docx', 'terminal-failed', 'token');
        try {
            $this->service($this->first, new class implements EditorDocumentFetcher
            {
                public function fetch(string $url, string $expectedExtension): DownloadedEditorDocument
                {
                    throw new RuntimeException('expired_editor_url');
                }
            })->handleCallback($failed);
            self::fail('The first callback must preserve a failed ledger record.');
        } catch (RuntimeException $exception) {
            self::assertSame('expired_editor_url', $exception->getMessage());
        }
        $firstSave = $this->first->table('legal_document_editor_saves')->sole();
        self::assertSame('failed', $firstSave->state);
        self::assertTrue((bool) $firstSave->terminal);

        try {
            $this->service($this->first)->handleCallback($failed);
            self::fail('An exact failed replay must not create another save generation.');
        } catch (DomainException $exception) {
            self::assertSame('legal_document_editor_callback_failed_replay', $exception->getMessage());
        }
        self::assertSame(1, $this->first->table('legal_document_editor_saves')->count());

        $saved = $this->service($this->first, $this->successfulFetcher('replacement'))->handleCallback(
            new EditorCallbackInput((string) $session->id, $payload->documentKey, 2,
                'https://office.example.test/fresh.docx', 'terminal-replacement', 'token'),
        );

        self::assertSame(2, (int) $saved->id);
        $ledger = $this->first->table('legal_document_editor_saves')->orderBy('save_generation')->get();
        self::assertCount(2, $ledger);
        self::assertSame('failed', $ledger[0]->state);
        self::assertSame('completed', $ledger[1]->state);
        self::assertSame($ledger[0]->id, $ledger[1]->supersedes_save_id);
        self::assertSame(2, (int) $this->first->table('legal_document_editor_sessions')->value('final_generation'));
        self::assertNull($this->first->table('legal_document_editor_sessions')->value('failure_code'));
    }

    public function test_callback_vs_new_version_has_one_service_winner(): void
    {
        $payload = $this->openSession();
        $session = $this->first->table('legal_document_editor_sessions')->sole();
        $gate = 'editor-version-'.bin2hex(random_bytes(6));
        $this->first->select('SELECT pg_advisory_lock(hashtextextended(?,0))', [$gate]);
        $input = new EditorCallbackInput((string) $session->id, $payload->documentKey, 6,
            'https://office.example.test/document.docx', 'callback-vs-new-version', 'token');
        $pid = pcntl_fork();
        if ($pid === 0) {
            $connection = $this->childConnection('editor_second');
            $fetcher = new class($connection, $gate) implements EditorDocumentFetcher
            {
                public function __construct(private ConnectionInterface $connection, private string $gate) {}

                public function fetch(string $url, string $expectedExtension): DownloadedEditorDocument
                {
                    $this->connection->table('editor_test_waiters')->insert(['gate_key' => $this->gate]);
                    $this->connection->select('SELECT pg_advisory_lock_shared(hashtextextended(?,0))', [$this->gate]);
                    $this->connection->select('SELECT pg_advisory_unlock_shared(hashtextextended(?,0))', [$this->gate]);

                    return LegalDocumentEditorPostgresConcurrencyTest::download('callback');
                }
            };
            try {
                $this->service($connection, $fetcher)->handleCallback($input);
                exit(0);
            } catch (\Throwable) {
                exit(21);
            }
        }
        self::assertGreaterThan(0, $pid);
        $deadline = microtime(true) + 5;
        while ($this->first->table('editor_test_waiters')->count() < 1 && microtime(true) < $deadline) {
            usleep(10_000);
        }
        try {
            $uploadDocument = null;
            $file = (new \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile)
                ->setConnection('editor_first')->newQuery()->findOrFail(1);
            $uploadDocument = self::download('external');
            $upload = new UploadedFile($uploadDocument->path, $uploadDocument->filename, $uploadDocument->mimeType, null, true);
            $this->fileService($this->first)->addVersion($file, $upload, new \App\Services\LegalArchive\Files\VersionInput(
                'External', 1, makeCurrent: true,
            ));
            self::fail('An external version must not rotate the current version while an editor session is active.');
        } catch (\Throwable $exception) {
            $messages = [];
            do {
                $messages[] = $exception->getMessage();
                $exception = $exception->getPrevious();
            } while ($exception !== null);
            self::assertContains('legal_document_active_editor_exists', $messages);
        } finally {
            $uploadDocument?->cleanup();
            $this->first->select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$gate]);
        }
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertSame(1, $this->first->table('legal_document_editor_saves')->count());
        self::assertSame(2, $this->first->table('legal_archive_documents')->value('current_primary_version_id'));
    }

    public function test_editor_open_vs_submit_approve_sign_freeze_has_exactly_one_winner(): void
    {
        $outcomes = $this->forkWorkers(
            function (ConnectionInterface $connection, int $worker): void {
                if ($worker === 0) {
                    $version = (new LegalArchiveDocumentVersion)->setConnection($connection->getName())->newQuery()->findOrFail(1);
                    $actor = (new User)->setConnection($connection->getName())->newQuery()->findOrFail(1);
                    $this->service($connection)->open($version, $actor);

                    return;
                }
                $connection->transaction(function () use ($connection): void {
                    $document = (new LegalDocumentAggregateLock)->lockDocument($connection, 1, 1);
                    (new LegalDocumentEditGuard($connection))->assertWorkflowSubmissionAllowed($document);
                    (new LegalDocumentEditGuard($connection))->assertSignatureAllowed($document);
                    $connection->table('legal_workflow_instances')->insert(['document_id' => 1, 'status' => 'in_progress']);
                });
            },
            false,
        );

        self::assertSame(1, count(array_filter($outcomes, static fn (int $code): bool => $code === 0)));
        self::assertSame(1, $this->first->table('legal_document_editor_sessions')->count()
            + $this->first->table('legal_workflow_instances')->count());
    }

    public function test_active_editor_blocks_submit_and_signature_guards_in_separate_processes(): void
    {
        $this->openSession();
        $outcomes = $this->forkWorkers(function (ConnectionInterface $connection, int $worker): void {
            $connection->transaction(function () use ($connection, $worker): void {
                $document = (new LegalDocumentAggregateLock)->lockDocument($connection, 1, 1);
                $guard = new LegalDocumentEditGuard($connection);
                if ($worker === 0) {
                    $guard->assertWorkflowSubmissionAllowed($document);
                } else {
                    $guard->assertSignatureAllowed($document);
                }
            });
        }, false);

        self::assertSame([20, 20], $outcomes);
        self::assertSame(0, $this->first->table('legal_workflow_instances')->count());
        self::assertSame(0, $this->first->table('legal_signature_requests')->count());
    }

    public function test_in_progress_approval_transition_wins_against_editor_open(): void
    {
        $this->first->table('legal_workflow_instances')->insert(['document_id' => 1, 'status' => 'in_progress']);
        $outcomes = $this->forkWorkers(function (ConnectionInterface $connection, int $worker): void {
            if ($worker === 0) {
                $version = (new LegalArchiveDocumentVersion)->setConnection($connection->getName())->newQuery()->findOrFail(1);
                $actor = (new User)->setConnection($connection->getName())->newQuery()->findOrFail(1);
                $this->service($connection)->open($version, $actor);

                return;
            }
            $connection->transaction(function () use ($connection): void {
                $document = (new LegalDocumentAggregateLock)->lockDocument($connection, 1, 1);
                if ($connection->table('legal_document_editor_sessions')->where('document_id', 1)
                    ->whereIn('status', ['active', 'processing'])->where('expires_at', '>', now())->exists()) {
                    throw new DomainException('legal_document_active_editor_exists');
                }
                $connection->table('legal_workflow_instances')->where('document_id', 1)->update(['status' => 'approved']);
                $connection->table('legal_archive_documents')->where('id', $document->id)->update([
                    'approval_status' => 'approved', 'lifecycle_status' => 'approved', 'updated_at' => now(),
                ]);
            });
        }, false);

        self::assertSame([0, 20], $outcomes);
        self::assertSame(0, $this->first->table('legal_document_editor_sessions')->count());
        self::assertSame('approved', $this->first->table('legal_archive_documents')->value('approval_status'));
        self::assertSame('approved', $this->first->table('legal_workflow_instances')->value('status'));
    }

    public function test_slow_generation_one_cannot_apply_after_fast_generation_two(): void
    {
        [$sessionId, $firstSaveId, $secondSaveId] = $this->directLedger(6);

        $outcomes = $this->forkWorkers(function (ConnectionInterface $connection, int $worker) use ($firstSaveId, $secondSaveId): void {
            if ($worker === 0) {
                usleep(300_000);
                $this->completeDirectSave($connection, $firstSaveId, 2);

                return;
            }
            $this->completeDirectSave($connection, $secondSaveId, 3);
        }, false);

        self::assertSame([0, 21], $outcomes);
        $session = $this->first->table('legal_document_editor_sessions')->where('id', $sessionId)->sole();
        self::assertSame(2, (int) $session->last_applied_generation);
        self::assertNull($session->final_generation);
        self::assertSame(3, (int) $session->saved_version_id);
        self::assertSame('failed', $this->first->table('legal_document_editor_saves')->where('id', $firstSaveId)->value('state'));
        self::assertSame('completed', $this->first->table('legal_document_editor_saves')->where('id', $secondSaveId)->value('state'));
    }

    public function test_terminal_status_two_fences_slow_force_save_and_leaves_no_processing_save(): void
    {
        $this->assertTerminalFencesSlowForceSave(2);
    }

    public function test_terminal_status_four_fences_slow_force_save_and_leaves_no_processing_save(): void
    {
        $this->assertTerminalFencesSlowForceSave(4);
    }

    private function openSession(): EditorSessionPayload
    {
        $version = (new LegalArchiveDocumentVersion)->setConnection('editor_first')->newQuery()->findOrFail(1);
        $actor = (new User)->setConnection('editor_first')->newQuery()->findOrFail(1);

        return $this->service($this->first)->open($version, $actor);
    }

    private function assertTerminalFencesSlowForceSave(int $terminalStatus): void
    {
        [$sessionId, $forceSaveId, $terminalSaveId] = $this->directLedger($terminalStatus);
        try {
            $this->insertDirectSave($this->first, $sessionId, 3, 6, false);
            self::fail('A save must not be reserved after a terminal callback was reserved.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('legal_document_editor_save_after_terminal', $exception->getMessage());
        }
        $outcomes = $this->forkWorkers(function (ConnectionInterface $connection, int $worker) use (
            $forceSaveId,
            $terminalSaveId,
            $terminalStatus,
        ): void {
            if ($worker === 0) {
                usleep(300_000);
                $this->completeDirectSave($connection, $forceSaveId, 2);

                return;
            }
            $this->completeDirectSave($connection, $terminalSaveId, $terminalStatus === 2 ? 3 : null);
        }, false);

        self::assertSame([0, 21], $outcomes);
        $session = $this->first->table('legal_document_editor_sessions')->where('id', $sessionId)->sole();
        self::assertSame(2, (int) $session->last_applied_generation);
        self::assertSame(2, (int) $session->final_generation);
        self::assertSame($terminalStatus === 2 ? 'completed' : 'closed', $session->status);
        self::assertSame(0, $this->first->table('legal_document_editor_saves')->where('editor_session_id', $sessionId)
            ->whereIn('state', ['reserved', 'processing'])->count());
        self::assertSame('failed', $this->first->table('legal_document_editor_saves')->where('id', $forceSaveId)->value('state'));

        try {
            $this->insertDirectSave($this->first, $sessionId, 3, 6, false);
            self::fail('A save must not be reserved after a terminal callback.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('legal_document_editor_save_after_terminal', $exception->getMessage());
        }
    }

    private function directLedger(int $secondStatus): array
    {
        $sessionId = (string) Str::uuid();
        $now = now();
        foreach ([2, 3] as $versionId) {
            $this->first->table('legal_archive_document_versions')->insert([
                'id' => $versionId, 'document_id' => 1, 'document_file_id' => 1, 'organization_id' => 1,
                'version_number' => (string) $versionId, 'is_current' => false, 'status' => 'uploaded',
                'processing_status' => 'ready', 'file_path' => "org-1/legal-archive/{$versionId}.docx",
                'original_filename' => "{$versionId}.docx", 'mime_type' => 'application/octet-stream',
                'size_bytes' => 10, 'content_hash' => str_repeat((string) $versionId, 64),
                'uploaded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $this->first->table('legal_document_editor_sessions')->insert([
            'id' => $sessionId, 'organization_id' => 1, 'document_id' => 1, 'source_version_id' => 1,
            'document_file_id' => 1, 'opened_by_user_id' => 1, 'provider' => 'test', 'mode' => 'edit',
            'status' => 'active', 'generation' => 1, 'next_save_generation' => 3,
            'document_key' => 'direct-'.$sessionId, 'source_content_hash' => str_repeat('a', 64),
            'expires_at' => $now->copy()->addHour(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $firstSaveId = $this->insertDirectSave($this->first, $sessionId, 1, 6, false);
        $secondSaveId = $this->insertDirectSave($this->first, $sessionId, 2, $secondStatus, in_array($secondStatus, [2, 4], true));

        return [$sessionId, $firstSaveId, $secondSaveId];
    }

    private function insertDirectSave(
        ConnectionInterface $connection,
        string $sessionId,
        int $generation,
        int $callbackStatus,
        bool $terminal,
    ): string {
        $id = (string) Str::uuid();
        $connection->table('legal_document_editor_saves')->insert([
            'id' => $id, 'organization_id' => 1, 'document_id' => 1, 'editor_session_id' => $sessionId,
            'source_version_id' => 1, 'document_file_id' => 1, 'save_generation' => $generation,
            'callback_status' => $callbackStatus, 'replay_hash' => hash('sha256', $id),
            'operation_id' => (string) Str::uuid(), 'state' => 'processing',
            'lease_owner_hash' => hash('sha256', 'lease-'.$id), 'lease_expires_at' => now()->addMinutes(5),
            'terminal' => $terminal, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function completeDirectSave(ConnectionInterface $connection, string $saveId, ?int $savedVersionId): void
    {
        $values = [
            'state' => 'completed', 'lease_owner_hash' => null, 'lease_expires_at' => null,
            'completed_at' => now(), 'updated_at' => now(),
        ];
        if ($savedVersionId !== null) {
            $values['saved_version_id'] = $savedVersionId;
            $values['content_hash'] = str_repeat((string) $savedVersionId, 64);
        }
        $connection->transaction(static function () use ($connection, $saveId, $values): void {
            $connection->table('legal_document_editor_saves')->where('id', $saveId)->update($values);
        });
    }

    private function forkWorkers(callable $work, bool $requireSuccess = true, ?callable $afterFork = null): array
    {
        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $manager = $this->database->getDatabaseManager();
                $manager->disconnect('editor_first');
                $manager->disconnect('editor_second');
                $connectionName = $worker === 0 ? 'editor_first' : 'editor_second';
                $manager->setDefaultConnection($connectionName);
                $connection = $manager->connection($connectionName);
                $connection->statement("SET search_path TO {$this->schema}");
                try {
                    $work($connection, $worker);
                    exit(0);
                } catch (DomainException) {
                    exit(20);
                } catch (\Throwable) {
                    exit(21);
                }
            }
            if ($pid < 0) {
                throw new RuntimeException('legal_document_editor_race_fork_failed');
            }
            $children[] = $pid;
        }
        $afterFork?->__invoke();
        $outcomes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            $outcomes[] = pcntl_wexitstatus($status);
        }
        sort($outcomes);
        if ($requireSuccess) {
            self::assertNotContains(21, $outcomes);
        }

        return $outcomes;
    }

    private function service(ConnectionInterface $connection, ?EditorDocumentFetcher $fetcher = null): LegalDocumentEditorSessionService
    {
        [$storage, $policy, $authorizer, $audit, $scanner] = $this->dependencies();
        $editor = new class implements LegalDocumentEditor
        {
            public function enabled(): bool
            {
                return true;
            }

            public function provider(): string
            {
                return 'test';
            }

            public function createSession(EditorDocumentContext $context, string $actorName): EditorSessionPayload
            {
                $key = $context->versionId.'.'.substr(hash('sha256', implode(':', [
                    $context->organizationId, $context->documentId, $context->versionId, $context->contentHash,
                    $context->sessionId, $context->generation,
                ])), 0, 48);

                return new EditorSessionPayload(true, 'edit', $key, (string) $context->versionId,
                    'https://office.example.test', 'token', [], $context->expiresAt, $context->sourceUrl);
            }

            public function verifyCallbackToken(string $token, EditorCallbackInput $input): void {}
        };
        $fetcher ??= $this->successfulFetcher('default');

        return new LegalDocumentEditorSessionService(
            $editor,
            $fetcher,
            new LegalDocumentFileService($storage, $policy, $scanner, $connection, $audit),
            new LegalDocumentDownloadService($storage, $authorizer, $policy, new NullLogger, $audit),
            $authorizer,
            $audit,
            $connection,
        );
    }

    private function fileService(ConnectionInterface $connection): LegalDocumentFileService
    {
        [$storage, $policy, , $audit, $scanner] = $this->dependencies();

        return new LegalDocumentFileService($storage, $policy, $scanner, $connection, $audit);
    }

    private function dependencies(): array
    {
        $storage = $this->createMock(FileService::class);
        $storage->method('temporaryUrl')->willReturn('https://storage.example.test/source.docx');
        $storage->method('upload')->willReturnCallback(static function (UploadedFile $upload): string {
            $path = $upload->getRealPath();

            return 'org-1/legal-archive/test-'.(is_string($path) ? hash_file('sha256', $path) : bin2hex(random_bytes(8))).'.docx';
        });
        $storage->method('delete')->willReturn(true);
        $policy = new LegalDocumentFilePolicy([
            'allowed_extensions' => ['docx'], 'max_size_bytes' => 1048576,
            'allowed_mime_types' => ['docx' => ['text/plain', 'application/octet-stream']],
        ]);
        $authorizer = new class implements LegalDocumentAuthorizer
        {
            public function authorize(User $user, LegalArchiveDocument $document, string $ability): void {}

            public function authorizePermission(User $user, LegalArchiveDocument $document, string $permission): void {}
        };
        $audit = new class implements LegalDocumentAudit
        {
            public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void {}

            public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

            public function recordContractForActorId(string $event, Contract $contract, ?int $actorId, array $context = []): void {}
        };
        $scanner = new class implements LegalDocumentScanner
        {
            public function assertClean(UploadedFile $upload): void {}
        };

        return [$storage, $policy, $authorizer, $audit, $scanner];
    }

    private function successfulFetcher(string $body): EditorDocumentFetcher
    {
        return new class($body) implements EditorDocumentFetcher
        {
            public function __construct(private string $body) {}

            public function fetch(string $url, string $expectedExtension): DownloadedEditorDocument
            {
                return LegalDocumentEditorPostgresConcurrencyTest::download($this->body);
            }
        };
    }

    public static function download(string $body): DownloadedEditorDocument
    {
        $path = tempnam(sys_get_temp_dir(), 'editor-pg-');
        if (! is_string($path) || file_put_contents($path, $body) === false) {
            throw new RuntimeException('editor_test_file_failed');
        }

        return new DownloadedEditorDocument($path, 'document.docx', 'text/plain', strlen($body), hash('sha256', $body));
    }

    private function childConnection(string $name): ConnectionInterface
    {
        $manager = $this->database->getDatabaseManager();
        $manager->disconnect('editor_first');
        $manager->disconnect('editor_second');
        $manager->setDefaultConnection($name);
        $connection = $manager->connection($name);
        $connection->statement("SET search_path TO {$this->schema}");

        return $connection;
    }

    private function installBaseSchema(): void
    {
        $this->first->unprepared(<<<'SQL'
CREATE TABLE organizations (id bigint PRIMARY KEY);
CREATE TABLE users (id bigint PRIMARY KEY, name text, email text, is_active boolean NOT NULL, current_organization_id bigint, deleted_at timestamptz, created_at timestamptz, updated_at timestamptz);
CREATE TABLE legal_archive_documents (
 id bigint PRIMARY KEY, organization_id bigint NOT NULL, title text NOT NULL, current_primary_version_id bigint,
 approval_status text, lifecycle_status text, signature_status text, lock_version bigint NOT NULL DEFAULT 0,
 deleted_at timestamptz, created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE(id,organization_id)
);
CREATE TABLE legal_archive_document_files (
 id bigint PRIMARY KEY, document_id bigint NOT NULL, organization_id bigint NOT NULL, current_version_id bigint,
 role text, title text, sort_order integer NOT NULL DEFAULT 0, is_required boolean NOT NULL DEFAULT true,
 created_at timestamptz, updated_at timestamptz, UNIQUE(id,document_id,organization_id)
);
CREATE TABLE legal_archive_document_versions (
 id bigserial PRIMARY KEY, document_id bigint NOT NULL, document_file_id bigint NOT NULL, organization_id bigint NOT NULL,
 version_number text NOT NULL, version_label text, is_current boolean NOT NULL, status text NOT NULL,
 processing_status text NOT NULL, file_path text NOT NULL, original_filename text NOT NULL, mime_type text,
 size_bytes bigint NOT NULL, content_hash text, metadata_hash text, uploaded_by_user_id bigint, uploaded_at timestamptz,
 metadata jsonb, created_at timestamptz, updated_at timestamptz, UNIQUE(id,document_id,organization_id),
 UNIQUE(id,document_file_id,organization_id), UNIQUE(document_id,version_number)
);
CREATE UNIQUE INDEX legal_archive_document_versions_current_unique ON legal_archive_document_versions(document_id) WHERE is_current=true;
CREATE TABLE legal_workflow_instances (id bigserial PRIMARY KEY, document_id bigint NOT NULL, status text NOT NULL);
CREATE TABLE legal_signature_requests (id bigserial PRIMARY KEY, document_id bigint NOT NULL, status text NOT NULL);
CREATE TABLE legal_archive_document_version_operations (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, document_id bigint NOT NULL, document_file_id bigint NOT NULL,
 operation_id varchar(191) NOT NULL, operation_generation integer NOT NULL DEFAULT 1, request_fingerprint varchar(64) NOT NULL,
 reserved_version_number text NOT NULL, requested_version_number varchar(64), version_label text, uploaded_by_user_id bigint,
 version_metadata jsonb, file_original_name text NOT NULL, file_size_bytes bigint NOT NULL, file_content_hash varchar(64) NOT NULL,
 file_client_mime_type text, file_detected_mime_type text, make_current boolean NOT NULL, attempt_token varchar(191) NOT NULL,
 attempt_count integer NOT NULL, status text NOT NULL, storage_path text, document_version_id bigint,
 created_at timestamptz, updated_at timestamptz,
 UNIQUE(organization_id,document_file_id,operation_id,operation_generation), UNIQUE(document_file_id,reserved_version_number)
);
CREATE TABLE editor_test_waiters (id bigserial PRIMARY KEY, gate_key text NOT NULL);
SQL);
    }

    private function seedAggregate(): void
    {
        $now = now();
        $this->first->table('organizations')->insert(['id' => 1]);
        $this->first->table('users')->insert([
            'id' => 1, 'name' => 'Editor', 'email' => 'editor@example.test', 'is_active' => true,
            'current_organization_id' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->first->table('legal_archive_documents')->insert([
            'id' => 1, 'organization_id' => 1, 'title' => 'Document', 'approval_status' => 'draft',
            'lifecycle_status' => 'draft', 'signature_status' => 'not_signed', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->first->table('legal_archive_document_files')->insert([
            'id' => 1, 'document_id' => 1, 'organization_id' => 1, 'role' => 'main', 'title' => 'Document',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->first->table('legal_archive_document_versions')->insert([
            'id' => 1, 'document_id' => 1, 'document_file_id' => 1, 'organization_id' => 1,
            'version_number' => '1', 'is_current' => true, 'status' => 'uploaded', 'processing_status' => 'ready',
            'file_path' => 'org-1/legal-archive/document.docx', 'original_filename' => 'document.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size_bytes' => 10, 'content_hash' => str_repeat('a', 64), 'uploaded_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->first->statement("SELECT setval(pg_get_serial_sequence('legal_archive_document_versions','id'),1,true)");
        $this->first->table('legal_archive_document_files')->where('id', 1)->update(['current_version_id' => 1]);
        $this->first->table('legal_archive_documents')->where('id', 1)->update(['current_primary_version_id' => 1]);
    }

    private function connectionConfig(string $dsn): array
    {
        $parts = [];
        foreach (explode(';', preg_replace('/^pgsql:/', '', $dsn) ?? '') as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
                $parts[$key] = $value;
            }
        }

        return [
            'driver' => 'pgsql', 'host' => $parts['host'] ?? '127.0.0.1', 'port' => $parts['port'] ?? '5432',
            'database' => $parts['dbname'] ?? getenv('DB_DATABASE'), 'username' => getenv('DB_USERNAME'),
            'password' => getenv('DB_PASSWORD'), 'charset' => 'utf8', 'prefix' => '', 'schema' => 'public',
        ];
    }
}
