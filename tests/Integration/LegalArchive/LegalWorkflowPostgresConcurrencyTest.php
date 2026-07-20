<?php

declare(strict_types=1);

namespace Tests\Integration\LegalArchive;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\User;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Workflow\DTO\WorkflowDecisionInput;
use App\Services\LegalArchive\Workflow\DTO\WorkflowOverride;
use App\Services\LegalArchive\Workflow\LegalDocumentWorkflowService;
use App\Services\LegalArchive\Workflow\LegalWorkflowActorResolver;
use App\Services\LegalArchive\Workflow\LegalWorkflowAssignmentValidator;
use App\Services\LegalArchive\Workflow\LegalWorkflowAuthorization;
use App\Services\LegalArchive\Workflow\LegalWorkflowRecoveryService;
use App\Services\LegalArchive\Workflow\LegalWorkflowTemplateService;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
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
        $first = $this->templateService($this->first);
        $second = $this->templateService($this->second);
        $actor = $this->actor();
        $one = $first->createVersion(1, 'contract', 'Маршрут 1', $this->definitions(), $actor);
        $this->first->beginTransaction();
        try {
            $this->first->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['legal-workflow-template:1:contract']);
            $available = $this->second->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired', ['legal-workflow-template:1:contract']);
            self::assertFalse((bool) $available->acquired);
        } finally {
            $this->first->rollBack();
        }
        $two = $second->createVersion(1, 'contract', 'Маршрут 2', $this->definitions(), $actor);
        self::assertSame(1, (int) $one->version);
        self::assertSame(2, (int) $two->version);
        self::assertSame((int) $two->id, (int) $this->first->table('legal_workflow_template_heads')->value('template_id'));
    }

    public function test_submit_replay_conflict_and_active_uniqueness_use_production_services(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor();
        $this->templateService($this->first)->createVersion(1, 'contract', 'Маршрут', $this->definitions(), $actor);
        $first = $this->workflowService($this->first);
        $second = $this->workflowService($this->second);
        $created = $first->submit($document, (int) $version->id, $actor, WorkflowOverride::none('same-submit'));
        $replay = $second->submit($document, (int) $version->id, $actor, WorkflowOverride::none('same-submit'));
        self::assertSame($created->id, $replay->id);
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
    }

    public function test_parallel_decisions_are_instance_serialized_and_activate_next_sequence_once(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor();
        $this->templateService($this->first)->createVersion(1, 'contract', 'Маршрут', $this->definitions(), $actor);
        $service = $this->workflowService($this->first);
        $instance = $service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('parallel-submit'));
        $steps = $instance->steps()->where('status', 'active')->orderBy('id')->get();
        $this->first->beginTransaction();
        try {
            $this->first->table('legal_workflow_instances')->where('id', $instance->id)->lockForUpdate()->first();
            $this->second->statement("SET lock_timeout = '250ms'");
            try {
                $this->second->table('legal_workflow_instances')->where('id', $instance->id)->lockForUpdate()->first();
                self::fail('A contender acquired the instance lock.');
            } catch (\Throwable $exception) {
                self::assertContains($exception->getCode(), ['55P03', 'HY000', 7]);
            }
        } finally {
            $this->first->rollBack();
            $this->second->statement("SET lock_timeout = '0'");
        }
        $service->decide($steps[0], $actor, new WorkflowDecisionInput('approve', 'parallel-1', 1, 0));
        try {
            $service->decide($steps[1], $actor, new WorkflowDecisionInput('approve', 'parallel-stale', 1, 0));
            self::fail('A stale parallel action succeeded.');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_stale_action', $exception->getMessage());
        }
        $updated = $service->decide($steps[1]->refresh(), $actor, new WorkflowDecisionInput('approve', 'parallel-2', 2, 0));
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
        $service->decide(
            $reassigned->steps()->whereKey($step->id)->firstOrFail(),
            $actor,
            new WorkflowDecisionInput('approve', 'guard-terminal', 2, 1),
        );
        try {
            $this->first->statement(<<<'SQL'
INSERT INTO legal_workflow_decisions
(organization_id, instance_id, step_id, document_id, document_version_id, document_content_hash,
 actor_type, actor_user_id, action, reason, from_status, to_status, request_hash, idempotency_key, decided_at, created_at, updated_at)
SELECT organization_id, instance_id, step_id, document_id, document_version_id, document_content_hash,
 actor_type, actor_user_id, 'reject', 'race', from_status, 'rejected', request_hash, 'forged-terminal', now(), now(), now()
FROM legal_workflow_decisions WHERE idempotency_key = 'guard-terminal'
SQL);
            self::fail('A second terminal decision was inserted.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('legal_workflow_decisions_terminal_unique', $exception->getMessage());
        }
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
CREATE TABLE legal_archive_documents (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, primary_project_id bigint NULL, title text NOT NULL,
 type_profile_code text NULL, approval_status text NULL, current_primary_version_id bigint NULL,
 lock_version integer NOT NULL DEFAULT 0, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz,
 UNIQUE (id, organization_id)
);
CREATE TABLE legal_archive_document_versions (
 id bigserial PRIMARY KEY, document_id bigint NOT NULL, organization_id bigint NOT NULL, version_number integer NOT NULL,
 is_current boolean NOT NULL, status text NOT NULL, processing_status text NOT NULL, content_hash char(64) NOT NULL,
 created_at timestamptz, updated_at timestamptz, UNIQUE (id, document_id, organization_id, content_hash)
);
INSERT INTO organizations (id) VALUES (1);
INSERT INTO users (id) VALUES (1);
SQL);
        foreach (['000400_create_legal_document_workflows', '000410_create_legal_document_workflow_indexes', '000420_add_legal_document_workflow_constraints', '000430_validate_legal_document_workflow_constraints'] as $suffix) {
            $migration = require dirname(__DIR__, 3)."/database/migrations/2026_07_19_{$suffix}.php";
            $migration->up();
        }
    }

    /** @return array{LegalArchiveDocument, LegalArchiveDocumentVersion} */
    private function dossier(): array
    {
        $document = (new LegalArchiveDocument)->setConnection('workflow_first')->newQuery()->create([
            'organization_id' => 1, 'title' => 'Договор', 'type_profile_code' => 'contract',
            'approval_status' => 'not_started', 'lock_version' => 0,
        ]);
        $version = (new LegalArchiveDocumentVersion)->setConnection('workflow_first')->newQuery()->create([
            'document_id' => $document->id, 'organization_id' => 1, 'version_number' => 1, 'is_current' => true,
            'status' => 'draft', 'processing_status' => 'ready', 'content_hash' => str_repeat('a', 64),
        ]);
        $document->forceFill(['current_primary_version_id' => $version->id])->save();

        return [$document->refresh(), $version];
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
