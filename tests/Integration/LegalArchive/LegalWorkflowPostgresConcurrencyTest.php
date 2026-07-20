<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Files\LegalDocumentFilePolicy;
use App\Services\LegalArchive\Files\LegalDocumentFileService;
use App\Services\LegalArchive\Files\LegalDocumentVersionAttempt;
use App\Services\LegalArchive\Files\LegalDocumentVersionLeaseLost;
use App\Services\LegalArchive\Files\TestingLegalDocumentScanner;
use App\Services\LegalArchive\Files\VersionInput;
use App\Services\LegalArchive\Workflow\DTO\WorkflowDecisionInput;
use App\Services\LegalArchive\Workflow\DTO\WorkflowOverride;
use App\Services\LegalArchive\Workflow\LegalDocumentWorkflowService;
use App\Services\LegalArchive\Workflow\LegalWorkflowActorResolver;
use App\Services\LegalArchive\Workflow\LegalWorkflowAssignmentValidator;
use App\Services\LegalArchive\Workflow\LegalWorkflowAuthorization;
use App\Services\LegalArchive\Workflow\LegalWorkflowRecoveryService;
use App\Services\LegalArchive\Workflow\LegalWorkflowTemplateService;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class LegalWorkflowPostgresConcurrencyTest extends TestCase
{
    private Capsule $database;

    private ConnectionInterface $first;

    private ConnectionInterface $second;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('LEGAL_DOCUMENT_PG_TEST_DSN');
        if (getenv('LEGAL_ARCHIVE_PG_WORKFLOW_CONCURRENCY') !== '1'
            || ! is_string($dsn)
            || $dsn === ''
            || getenv('LEGAL_DOCUMENT_PG_TEST_ALLOW_DDL') !== '1'
        ) {
            self::markTestSkipped('Dedicated PostgreSQL workflow contract database is not enabled.');
        }
        $config = $this->connectionConfig($dsn);
        $this->database = new Capsule;
        $this->database->addConnection($config, 'workflow_first');
        $this->database->addConnection($config, 'workflow_second');
        $this->database->setAsGlobal();
        $container = new Container;
        $container->instance('db', $this->database->getDatabaseManager());
        Facade::setFacadeApplication($container);
        $this->database->setEventDispatcher(new Dispatcher($container));
        $this->database->bootEloquent();
        $this->database->getDatabaseManager()->setDefaultConnection('workflow_first');
        Model::clearBootedModels();
        $this->first = $this->database->getConnection('workflow_first');
        $this->second = $this->database->getConnection('workflow_second');
        $database = (string) $this->first->selectOne('SELECT current_database() AS name')->name;
        if (preg_match('/(?:_test|_testing)$/D', $database) !== 1) {
            self::markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }
        $this->schema = 'legal_workflow_it_'.bin2hex(random_bytes(6));
        $this->first->statement("CREATE SCHEMA {$this->schema}");
        $this->first->statement("SET search_path TO {$this->schema}");
        $this->second->statement("SET search_path TO {$this->schema}");
        $this->installProductionSchema();
        $this->first->statement('CREATE TABLE race_barriers (race_key text NOT NULL, worker integer NOT NULL, PRIMARY KEY (race_key, worker))');
        $this->first->statement('CREATE TABLE race_results (race_key text NOT NULL, worker integer NOT NULL, result jsonb NOT NULL, PRIMARY KEY (race_key, worker))');
    }

    protected function tearDown(): void
    {
        if (isset($this->first, $this->schema) && str_starts_with($this->schema, 'legal_workflow_it_')) {
            $this->first->statement("DROP SCHEMA {$this->schema} CASCADE");
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_template_head_and_version_stream_are_serialized_on_two_connections(): void
    {
        $results = $this->runRace('template_head', [
            fn (ConnectionInterface $connection): array => [
                'version' => (int) $this->templateService($connection)
                    ->createVersion(1, 'contract', 'Маршрут 1', $this->definitions(), $this->actor())->version,
            ],
            fn (ConnectionInterface $connection): array => [
                'version' => (int) $this->templateService($connection)
                    ->createVersion(1, 'contract', 'Маршрут 2', $this->definitions(), $this->actor())->version,
            ],
        ]);
        self::assertSame([1, 2], collect($results)->pluck('version')->sort()->values()->all());
        $head = $this->first->table('legal_workflow_template_heads')->first();
        self::assertSame(2, (int) $this->first->table('legal_workflow_templates')->where('id', $head->template_id)->value('version'));
    }

    public function test_submit_replay_conflict_and_active_uniqueness_use_production_services(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor();
        $this->templateService($this->first)->createVersion(1, 'contract', 'Маршрут', $this->definitions(), $actor);
        $second = $this->workflowService($this->second);
        $documentId = (int) $document->id;
        $versionId = (int) $version->id;
        $results = $this->runRace('submit_replay', [
            fn (ConnectionInterface $connection): array => $this->submitRaceResult($connection, $documentId, $versionId, 'same-submit'),
            fn (ConnectionInterface $connection): array => $this->submitRaceResult($connection, $documentId, $versionId, 'same-submit'),
        ]);
        self::assertTrue($results[0]['ok']);
        self::assertTrue($results[1]['ok']);
        self::assertSame($results[0]['instance_id'], $results[1]['instance_id']);
        $created = (new \App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance)
            ->setConnection('workflow_first')->newQuery()->findOrFail($results[0]['instance_id']);
        try {
            $second->submit($document, (int) $version->id, $actor, new WorkflowOverride('same-submit', stepOverrides: ['optional' => ['enabled' => false]]));
            self::fail('Different command reused submit idempotency key.');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_idempotency_conflict', $exception->getMessage());
        }
        try {
            $second->submit($document->refresh(), (int) $version->id, $actor, WorkflowOverride::none('other-submit'));
            self::fail('A second active instance was created.');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_active_instance_exists', $exception->getMessage());
        }
        try {
            $this->second->statement(<<<'SQL'
INSERT INTO legal_workflow_instances
(organization_id, document_id, document_version_id, document_content_hash, template_id, template_version,
 template_definition_hash, template_snapshot, snapshot_hash, client_request_hash, request_hash, idempotency_key,
 status, lock_version, submitted_by_user_id, submitted_at, created_at, updated_at)
SELECT organization_id, document_id, document_version_id, document_content_hash, template_id, template_version,
 template_definition_hash, template_snapshot, snapshot_hash, client_request_hash, request_hash, 'raw-active-race',
 status, lock_version, submitted_by_user_id, submitted_at, now(), now()
FROM legal_workflow_instances WHERE id = ?
SQL, [$created->id]);
            self::fail('The active-instance partial unique index accepted a competing row.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('legal_workflow_instances_active_unique', $exception->getMessage());
        }
        self::assertSame(1, $this->first->table('legal_workflow_instances')->count());

        [$competingDocument, $competingVersion] = $this->dossier();
        $competing = $this->runRace('submit_conflict', [
            fn (ConnectionInterface $connection): array => $this->submitRaceResult($connection, (int) $competingDocument->id, (int) $competingVersion->id, 'submit-a'),
            fn (ConnectionInterface $connection): array => $this->submitRaceResult($connection, (int) $competingDocument->id, (int) $competingVersion->id, 'submit-b'),
        ]);
        self::assertSame(1, collect($competing)->where('ok', true)->count());
        self::assertSame(1, $this->first->table('legal_workflow_instances')->where('document_id', $competingDocument->id)->count());
    }

    public function test_parallel_decisions_are_instance_serialized_and_activate_next_sequence_once(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor();
        $this->templateService($this->first)->createVersion(1, 'contract', 'Маршрут', $this->definitions(), $actor);
        $service = $this->workflowService($this->first);
        $instance = $service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('parallel-submit'));
        $steps = $instance->steps()->where('status', 'active')->orderBy('id')->get();
        $race = $this->runRace('parallel_decisions', [
            fn (ConnectionInterface $connection): array => $this->decisionRaceResult($connection, (int) $steps[0]->id, 'approve', 'parallel-1', 1, 0),
            fn (ConnectionInterface $connection): array => $this->decisionRaceResult($connection, (int) $steps[1]->id, 'approve', 'parallel-2', 1, 0),
        ]);
        self::assertSame(1, collect($race)->where('ok', true)->count());
        self::assertSame(1, collect($race)->where('error', 'legal_workflow_stale_action')->count());
        $loser = $race[0]['ok'] ? $steps[1] : $steps[0];
        $updated = $service->decide($loser->refresh(), $actor, new WorkflowDecisionInput('approve', 'parallel-retry', 2, 0));
        self::assertSame(1, $updated->steps()->where('sequence', 20)->where('status', 'active')->count());
        self::assertSame(0, $updated->steps()->where('sequence', 10)->where('status', 'active')->count());
        $recovery = new LegalWorkflowRecoveryService(new ImmutableAuditIntegrityService, new PostgresWorkflowAudit, $this->first, $this->templateService($this->first));
        $recovery->markRequired($updated, 'post-race');
        self::assertNull($recovery->reconcile(1, (int) $updated->id)->reconciliation_required_at);
    }

    public function test_terminal_uniqueness_and_reassignment_guard_fail_closed(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor();
        $this->templateService($this->first)->createVersion(1, 'contract', 'Маршрут', $this->definitions(), $actor);
        $service = $this->workflowService($this->first);
        $instance = $service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('guard-submit'));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();
        try {
            $this->first->table('legal_workflow_steps')->where('id', $step->id)->update(['actor_reference' => 'forged']);
            self::fail('Raw reassignment bypassed the PostgreSQL guard.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('legal_workflow_step_assignment_update_forbidden', $exception->getMessage());
        }
        $reassigned = $service->decide($step, $actor, new WorkflowDecisionInput(
            'reassign', 'guard-reassign', 1, 0, reason: 'Замена', reassignActorType: 'user',
            reassignActorReference: '1', dueAt: '2099-01-01T00:00:00+00:00',
        ));
        self::assertSame(1, $reassigned->steps()->whereKey($step->id)->value('assignment_revision'));
        $terminalRace = $this->runRace('terminal_uniqueness', [
            fn (ConnectionInterface $connection): array => $this->decisionRaceResult($connection, (int) $step->id, 'approve', 'guard-approve', 2, 1),
            fn (ConnectionInterface $connection): array => $this->decisionRaceResult($connection, (int) $step->id, 'reject', 'guard-reject', 2, 1),
        ]);
        self::assertSame(1, collect($terminalRace)->where('ok', true)->count());
        self::assertSame(1, $this->first->table('legal_workflow_decisions')->where('step_id', $step->id)->whereIn('action', ['approve', 'reject'])->count());
        try {
            $this->first->statement(<<<'SQL'
INSERT INTO legal_workflow_decisions
(organization_id, instance_id, step_id, document_id, document_version_id, document_content_hash,
 actor_type, actor_user_id, action, comment, from_status, to_status, request_hash, idempotency_key, decided_at, created_at, updated_at)
SELECT organization_id, instance_id, step_id, document_id, document_version_id, document_content_hash,
 actor_type, actor_user_id, 'reject', 'race', from_status, 'rejected', request_hash, 'forged-terminal', now(), now(), now()
FROM legal_workflow_decisions WHERE step_id = ? AND action IN ('approve', 'reject') LIMIT 1
SQL, [$step->id]);
            self::fail('A second terminal decision was inserted.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('legal_workflow_decisions_terminal_unique', $exception->getMessage());
        }
    }

    public function test_submit_and_current_version_rotation_are_serialized_by_document_aggregate(): void
    {
        [$document, $file, $version] = $this->fileDossier();
        $this->templateService($this->first)->createVersion(
            1,
            'contract',
            'Aggregate race',
            $this->definitions(),
            $this->actor(),
        );
        $race = $this->runRace('document_aggregate', [
            fn (ConnectionInterface $connection): array => $this->submitRaceResult(
                $connection,
                (int) $document->id,
                (int) $version->id,
                'aggregate-submit',
            ),
            fn (ConnectionInterface $connection): array => $this->fileRaceResult($connection, (int) $file->id),
        ]);

        self::assertSame(1, collect($race)->where('ok', true)->count());
        $loser = collect($race)->firstWhere('ok', false);
        self::assertContains($loser['error'], [
            'legal_document_active_workflow_exists',
            'legal_workflow_version_changed',
            'legal_workflow_version_not_ready',
        ]);
        $document->refresh();
        $file->refresh();
        $activeVersion = $this->first->table('legal_workflow_instances')
            ->where('document_id', $document->id)
            ->where('status', 'in_progress')
            ->value('document_version_id');
        if ($activeVersion !== null) {
            self::assertSame((int) $activeVersion, (int) $document->current_primary_version_id);
            self::assertSame((int) $activeVersion, (int) $file->current_version_id);
        }
    }

    public function test_reclaimed_file_operation_fences_process_blocked_in_upload_from_persisting_or_promoting(): void
    {
        [$document, $file, $original] = $this->fileDossier();
        $this->first->statement('CREATE TABLE version_fence_owners (operation_id text PRIMARY KEY, attempt_token text NOT NULL)');
        $this->first->table('version_fence_owners')->insert([
            'operation_id' => 'source-create-race',
            'attempt_token' => 'attempt-old',
        ]);
        $race = $this->runRace('version_fence', [
            fn (ConnectionInterface $connection): array => $this->fencedFileRaceResult($connection, (int) $file->id, 0),
            fn (ConnectionInterface $connection): array => $this->fencedFileRaceResult($connection, (int) $file->id, 1),
        ]);

        self::assertFalse($race[0]['ok']);
        self::assertSame('legal_document_version_lease_lost', $race[0]['error']);
        self::assertTrue($race[1]['ok']);
        $versions = $this->first->table('legal_archive_document_versions')
            ->where('document_file_id', $file->id)
            ->orderBy('id')
            ->get();
        self::assertCount(2, $versions);
        $winner = $versions->last();
        self::assertSame('ready', $winner->processing_status);
        self::assertTrue((bool) $winner->is_current);
        self::assertFalse((bool) $versions->first()->is_current);
        self::assertNotSame((int) $original->id, (int) $winner->id);
        self::assertSame((int) $winner->id, (int) $file->fresh()->current_version_id);
        self::assertSame((int) $winner->id, (int) $document->fresh()->current_primary_version_id);
        $operation = $this->first->table('legal_archive_document_version_operations')->sole();
        self::assertSame(2, (int) $operation->attempt_count);
        self::assertSame((int) $winner->id, (int) $operation->document_version_id);
    }

    public function test_schema_migrations_fail_closed_on_descriptor_drift_and_validate_only_allowlist(): void
    {
        $indexes = require dirname(__DIR__, 3).'/database/migrations/2026_07_19_000410_create_legal_document_workflow_indexes.php';
        $this->first->statement('DROP INDEX legal_workflow_steps_actor_queue_idx');
        $this->first->statement(
            "CREATE INDEX legal_workflow_steps_actor_queue_idx
             ON legal_workflow_steps
             (organization_id, actor_type, actor_reference, due_at DESC NULLS FIRST)
             INCLUDE (step_key)
             WHERE status = 'active'",
        );
        try {
            $indexes->up();
            self::fail('A valid index with a wrong descriptor was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'legal_workflow_index_descriptor_mismatch:legal_workflow_steps_actor_queue_idx',
                $exception->getMessage(),
            );
        } finally {
            $this->first->statement('DROP INDEX legal_workflow_steps_actor_queue_idx');
            $indexes->up();
        }

        $constraints = require dirname(__DIR__, 3).'/database/migrations/2026_07_19_000420_add_legal_document_workflow_constraints.php';
        $this->first->statement('ALTER TABLE legal_workflow_templates DROP CONSTRAINT legal_workflow_templates_hash_check');
        $this->first->statement('ALTER TABLE legal_workflow_templates ADD CONSTRAINT legal_workflow_templates_hash_check CHECK (version > 0) NOT VALID');
        try {
            $constraints->up();
            self::fail('A constraint with a wrong descriptor was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'legal_workflow_constraint_descriptor_mismatch:legal_workflow_templates_hash_check',
                $exception->getMessage(),
            );
        } finally {
            $this->first->statement('ALTER TABLE legal_workflow_templates DROP CONSTRAINT legal_workflow_templates_hash_check');
            $constraints->up();
        }

        $this->first->statement('CREATE TABLE legal_workflow_unrelated_probe (value integer)');
        $this->first->statement('ALTER TABLE legal_workflow_unrelated_probe ADD CONSTRAINT legal_workflow_unrelated_probe_check CHECK (value > 0) NOT VALID');
        $validation = require dirname(__DIR__, 3).'/database/migrations/2026_07_19_000430_validate_legal_document_workflow_constraints.php';
        $validation->up();
        $probe = $this->first->selectOne(
            "SELECT convalidated FROM pg_constraint WHERE conname = 'legal_workflow_unrelated_probe_check'",
        );
        self::assertFalse((bool) $probe->convalidated);
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
            'driver' => 'pgsql', 'host' => $parts['host'] ?? '127.0.0.1', 'port' => $parts['port'] ?? '5432',
            'database' => $parts['dbname'] ?? '', 'username' => (string) getenv('LEGAL_DOCUMENT_PG_TEST_USER'),
            'password' => (string) getenv('LEGAL_DOCUMENT_PG_TEST_PASSWORD'), 'charset' => 'utf8', 'prefix' => '',
        ];
    }

    private function installProductionSchema(): void
    {
        $this->first->unprepared(<<<'SQL'
CREATE TABLE organizations (id bigserial PRIMARY KEY);
CREATE TABLE users (id bigserial PRIMARY KEY);
CREATE TABLE projects (id bigserial PRIMARY KEY, organization_id bigint NOT NULL REFERENCES organizations(id));
INSERT INTO organizations (id) VALUES (1);
INSERT INTO users (id) VALUES (1);
INSERT INTO projects (id, organization_id) VALUES (1, 1);
SQL);
        foreach ([
            '2026_06_23_000001_create_legal_archive_tables',
            '2026_07_19_000100_create_legal_document_profiles_and_extend_dossiers',
            '2026_07_19_000110_create_legal_document_profile_indexes',
            '2026_07_19_000120_add_legal_document_profile_constraints',
            '2026_07_19_000130_validate_legal_document_profile_constraints',
            '2026_07_19_000200_create_legal_document_files_and_harden_versions',
            '2026_07_19_000210_create_legal_document_file_indexes',
            '2026_07_19_000220_add_legal_document_file_constraints',
            '2026_07_19_000230_validate_legal_document_file_constraints',
            '2026_07_19_000240_create_legal_archive_file_cleanup_debts',
            '2026_07_19_000250_create_legal_document_version_operations',
            '2026_07_19_000260_add_legal_document_version_operation_constraints',
            '2026_07_19_000270_validate_legal_document_version_operation_constraints',
            '2026_07_19_000280_allow_fenced_legal_document_version_rescan',
        ] as $name) {
            $migration = require dirname(__DIR__, 3)."/database/migrations/{$name}.php";
            $migration->up();
        }
        foreach (['000400_create_legal_document_workflows', '000410_create_legal_document_workflow_indexes', '000420_add_legal_document_workflow_constraints', '000430_validate_legal_document_workflow_constraints'] as $suffix) {
            $migration = require dirname(__DIR__, 3)."/database/migrations/2026_07_19_{$suffix}.php";
            $migration->up();
        }
    }

    /** @return array{LegalArchiveDocument, LegalArchiveDocumentVersion} */
    private function dossier(): array
    {
        $document = (new LegalArchiveDocument)->setConnection('workflow_first')->newQuery()->create([
            'organization_id' => 1, 'primary_project_id' => 1, 'title' => 'Договор',
            'document_type' => 'contract', 'type_profile_code' => 'contract',
            'approval_status' => 'not_started', 'lifecycle_status' => 'draft', 'lock_version' => 0,
        ]);
        $version = (new LegalArchiveDocumentVersion)->setConnection('workflow_first')->newQuery()->create([
            'document_id' => $document->id, 'organization_id' => 1, 'version_number' => 1, 'is_current' => true,
            'status' => 'draft', 'processing_status' => 'ready', 'content_hash' => str_repeat('a', 64),
            'file_path' => 'org-1/legal/test.pdf', 'original_filename' => 'test.pdf',
        ]);
        $document->forceFill(['current_primary_version_id' => $version->id])->save();

        return [$document->refresh(), $version];
    }

    /** @return array{LegalArchiveDocument, LegalArchiveDocumentFile, LegalArchiveDocumentVersion} */
    private function fileDossier(): array
    {
        $document = (new LegalArchiveDocument)->setConnection('workflow_first')->newQuery()->create([
            'organization_id' => 1,
            'primary_project_id' => 1,
            'title' => 'Contract',
            'document_type' => 'contract',
            'type_profile_code' => 'contract',
            'approval_status' => 'not_started',
            'lifecycle_status' => 'draft',
            'lock_version' => 0,
        ]);
        $file = (new LegalArchiveDocumentFile)->setConnection('workflow_first')->newQuery()->create([
            'document_id' => $document->id,
            'organization_id' => 1,
            'role' => 'primary',
            'title' => 'Contract',
            'sort_order' => 0,
            'is_required' => true,
        ]);
        $version = (new LegalArchiveDocumentVersion)->setConnection('workflow_first')->newQuery()->create([
            'document_id' => $document->id,
            'document_file_id' => $file->id,
            'organization_id' => 1,
            'version_number' => '1',
            'is_current' => true,
            'status' => 'uploaded',
            'processing_status' => 'ready',
            'content_hash' => str_repeat('a', 64),
            'file_path' => 'org-1/legal/test.pdf',
            'original_filename' => 'test.pdf',
        ]);
        $file->forceFill(['current_version_id' => $version->id])->save();
        $document->forceFill(['current_primary_version_id' => $version->id])->save();

        return [$document->refresh(), $file->refresh(), $version];
    }

    private function actor(): PostgresWorkflowUser
    {
        $actor = new PostgresWorkflowUser;
        $actor->forceFill(['id' => 1, 'current_organization_id' => 1]);

        return $actor;
    }

    private function templateService(ConnectionInterface $connection): LegalWorkflowTemplateService
    {
        return new LegalWorkflowTemplateService(new ImmutableAuditIntegrityService, $connection);
    }

    private function workflowService(ConnectionInterface $connection): LegalDocumentWorkflowService
    {
        return new LegalDocumentWorkflowService(
            $this->templateService($connection), new LegalWorkflowAuthorization,
            new LegalWorkflowActorResolver(roleLookup: static fn (): bool => true),
            new LegalWorkflowAssignmentValidator(static fn (): bool => true), new PostgresWorkflowAudit,
            new ImmutableAuditIntegrityService, $connection,
        );
    }

    /** @return array{instance_id: int} */
    private function submitRaceResult(
        ConnectionInterface $connection,
        int $documentId,
        int $versionId,
        string $idempotencyKey,
    ): array {
        $document = (new LegalArchiveDocument)->setConnection($connection->getName())->newQuery()->findOrFail($documentId);
        $instance = $this->workflowService($connection)->submit(
            $document,
            $versionId,
            $this->actor(),
            WorkflowOverride::none($idempotencyKey),
        );

        return ['instance_id' => (int) $instance->id];
    }

    /** @return array{instance_id: int} */
    private function decisionRaceResult(
        ConnectionInterface $connection,
        int $stepId,
        string $action,
        string $idempotencyKey,
        int $instanceLockVersion,
        int $stepLockVersion,
    ): array {
        $step = (new \App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep)
            ->setConnection($connection->getName())->newQuery()->findOrFail($stepId);
        $instance = $this->workflowService($connection)->decide(
            $step,
            $this->actor(),
            new WorkflowDecisionInput(
                $action,
                $idempotencyKey,
                $instanceLockVersion,
                $stepLockVersion,
                comment: $action === 'reject' ? 'Отклонено в конкурентном решении' : null,
            ),
        );

        return ['instance_id' => (int) $instance->id];
    }

    /** @return array{version_id: int} */
    private function fileRaceResult(ConnectionInterface $connection, int $fileId): array
    {
        $file = (new LegalArchiveDocumentFile)->setConnection($connection->getName())
            ->newQuery()
            ->findOrFail($fileId);
        $storage = new class extends FileService
        {
            public function __construct() {}

            public function upload(
                UploadedFile $file,
                string $directory,
                ?string $existingPath = null,
                string $visibility = 'public',
                ?Organization $organization = null,
                bool $respectRequestedVisibility = false,
                bool $privacyMode = false,
            ): string|false {
                return 'org-1/legal/race.pdf';
            }

            public function delete(?string $path, ?Organization $organization = null): bool
            {
                return true;
            }
        };
        $service = new LegalDocumentFileService(
            $storage,
            new LegalDocumentFilePolicy([
                'max_size_bytes' => 1024 * 1024,
                'allowed_extensions' => ['pdf'],
                'allowed_mime_types' => ['pdf' => ['application/pdf']],
            ]),
            new TestingLegalDocumentScanner,
            $connection,
        );
        $version = $service->addVersion(
            $file,
            UploadedFile::fake()->createWithContent('race.pdf', "%PDF-1.7\nrace"),
            new VersionInput(uploadedByUserId: 1, makeCurrent: true),
        );

        return ['version_id' => (int) $version->id];
    }

    /** @return array{version_id: int} */
    private function fencedFileRaceResult(ConnectionInterface $connection, int $fileId, int $worker): array
    {
        if ($worker === 1) {
            $deadline = microtime(true) + 10;
            while (! $connection->table('race_barriers')->where('race_key', 'version-upload-started')->exists()) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('version_upload_barrier_timeout');
                }
                usleep(20_000);
            }
            $connection->table('version_fence_owners')->where('operation_id', 'source-create-race')->update([
                'attempt_token' => 'attempt-new',
            ]);
        }
        $file = (new LegalArchiveDocumentFile)->setConnection($connection->getName())->newQuery()->findOrFail($fileId);
        $storage = new class($connection, $worker) extends FileService
        {
            public function __construct(
                private readonly ConnectionInterface $connection,
                private readonly int $worker,
            ) {}

            public function upload(
                UploadedFile $file,
                string $directory,
                ?string $existingPath = null,
                string $visibility = 'public',
                ?Organization $organization = null,
                bool $respectRequestedVisibility = false,
                bool $privacyMode = false,
            ): string|false {
                if ($this->worker === 0) {
                    $this->connection->table('race_barriers')->insert([
                        'race_key' => 'version-upload-started',
                        'worker' => 0,
                    ]);
                    $deadline = microtime(true) + 10;
                    while ($this->connection->table('version_fence_owners')
                        ->where('operation_id', 'source-create-race')
                        ->value('attempt_token') !== 'attempt-new'
                    ) {
                        if (microtime(true) >= $deadline) {
                            throw new \RuntimeException('version_reclaim_timeout');
                        }
                        usleep(20_000);
                    }
                }

                return "org-1/legal/race-{$this->worker}.pdf";
            }

            public function delete(?string $path, ?Organization $organization = null): bool
            {
                return true;
            }
        };
        $token = $worker === 0 ? 'attempt-old' : 'attempt-new';
        $attempt = new LegalDocumentVersionAttempt(
            'source-create-race',
            $token,
            function (LegalArchiveDocument $document, string $candidate) use ($connection): void {
                $active = $connection->table('version_fence_owners')
                    ->where('operation_id', 'source-create-race')
                    ->value('attempt_token');
                if (! is_string($active) || ! hash_equals($active, $candidate)) {
                    throw new LegalDocumentVersionLeaseLost;
                }
            },
        );
        $service = new LegalDocumentFileService(
            $storage,
            new LegalDocumentFilePolicy([
                'max_size_bytes' => 1024 * 1024,
                'allowed_extensions' => ['pdf'],
                'allowed_mime_types' => ['pdf' => ['application/pdf']],
            ]),
            new TestingLegalDocumentScanner,
            $connection,
        );
        $version = $service->addVersion(
            $file,
            UploadedFile::fake()->createWithContent('race.pdf', "%PDF-1.7\nrace"),
            new VersionInput(versionNumber: '2', uploadedByUserId: 1, makeCurrent: true),
            $attempt,
        );

        return ['version_id' => (int) $version->id];
    }

    /**
     * @param  list<callable(ConnectionInterface): array<string, mixed>>  $workers
     * @return list<array<string, mixed>>
     */
    private function runRace(string $scenario, array $workers): array
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl is required for process-level PostgreSQL race tests.');
        }
        $raceKey = $scenario.'-'.bin2hex(random_bytes(5));
        $gateKey = 'legal-workflow-race:'.$raceKey;
        $this->first->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$gateKey]);
        $children = [];
        foreach ($workers as $worker => $callback) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('legal_workflow_race_fork_failed');
            }
            if ($pid === 0) {
                $manager = $this->database->getDatabaseManager();
                $manager->disconnect('workflow_first');
                $manager->disconnect('workflow_second');
                $connection = $manager->connection('workflow_first');
                $connection->statement("SET search_path TO {$this->schema}");
                $connection->table('race_barriers')->insert(['race_key' => $raceKey, 'worker' => $worker]);
                $connection->select('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', [$gateKey]);
                try {
                    $result = ['ok' => true, ...$callback($connection)];
                } catch (\Throwable $exception) {
                    $result = ['ok' => false, 'error' => $exception->getMessage(), 'code' => (string) $exception->getCode()];
                }
                $connection->table('race_results')->insert([
                    'race_key' => $raceKey,
                    'worker' => $worker,
                    'result' => json_encode($result, JSON_THROW_ON_ERROR),
                ]);
                $connection->select('SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))', [$gateKey]);
                exit(0);
            }
            $children[] = $pid;
        }
        $deadline = microtime(true) + 10;
        while ($this->first->table('race_barriers')->where('race_key', $raceKey)->count() < count($workers)) {
            if (microtime(true) >= $deadline) {
                $this->first->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$gateKey]);
                throw new \RuntimeException('legal_workflow_race_barrier_timeout');
            }
            usleep(20_000);
        }
        $this->first->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$gateKey]);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new \RuntimeException('legal_workflow_race_worker_failed');
            }
        }

        return $this->first->table('race_results')
            ->where('race_key', $raceKey)
            ->orderBy('worker')
            ->pluck('result')
            ->map(static fn (string $result): array => json_decode($result, true, 512, JSON_THROW_ON_ERROR))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function definitions(): array
    {
        return [
            ['key' => 'legal_review', 'label' => 'Юридическая проверка', 'sequence' => 10, 'parallel_group' => 'legal', 'required' => true, 'policy_key' => 'legal_review', 'actor_type' => 'role', 'actor_reference' => 'legal_reviewer', 'due_in_hours' => 24],
            ['key' => 'optional', 'label' => 'Вторая проверка', 'sequence' => 10, 'parallel_group' => 'legal', 'required' => false, 'actor_type' => 'user', 'actor_reference' => '1', 'due_in_hours' => 24],
            ['key' => 'finance', 'label' => 'Финансовая проверка', 'sequence' => 20, 'parallel_group' => 'finance', 'required' => false, 'actor_type' => 'user', 'actor_reference' => '1', 'due_in_hours' => 48],
        ];
    }
}

final class PostgresWorkflowUser extends User
{
    public function hasPermission(string $permission, ?array $context = null): bool
    {
        return true;
    }
}

final class PostgresWorkflowAudit implements LegalDocumentAudit
{
    public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void {}

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

    public function recordContractForActorId(string $event, \App\Models\Contract $contract, ?int $actorId, array $context = []): void {}
}
