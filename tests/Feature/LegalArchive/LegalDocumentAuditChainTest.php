<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRedactor;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentOutboxMessage;
use App\Models\User;
use App\Services\LegalArchive\Audit\LegalDocumentAuditService;
use App\Services\LegalArchive\Audit\LegalDocumentOutbox;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegalDocumentAuditChainTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new Capsule;
        $this->database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher(new Container));
        $this->database->bootEloquent();
        Model::clearBootedModels();

        $this->database->schema()->create('immutable_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('sequence_id')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('domain');
            $table->string('event_type');
            $table->string('action');
            $table->string('result');
            $table->string('severity');
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at');
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('actor_snapshot')->nullable();
            $table->unsignedBigInteger('impersonator_user_id')->nullable();
            $table->string('source');
            $table->string('source_route')->nullable();
            $table->string('source_model')->nullable();
            $table->string('source_table')->nullable();
            $table->string('source_event_id')->nullable();
            $table->string('correlation_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->json('related_subjects')->nullable();
            $table->text('reason')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('diff')->nullable();
            $table->json('domain_context')->nullable();
            $table->json('sensitive_fields')->nullable();
            $table->string('redaction_policy_version');
            $table->string('payload_hash', 64);
            $table->string('previous_hash', 64)->nullable();
            $table->string('record_hash', 64);
            $table->string('chain_scope');
            $table->unsignedSmallInteger('chain_version');
            $table->timestamp('sealed_at')->nullable();
            $table->uuid('seal_id')->nullable();
            $table->string('integrity_status');
            $table->timestamp('retention_until');
            $table->timestamp('created_at');
            $table->unique([
                'organization_id',
                'domain',
                'subject_type',
                'subject_id',
                'source',
                'source_event_id',
            ]);
        });
        $this->database->schema()->create('legal_document_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('event');
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->string('idempotency_key');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();
            $table->timestamp('reconciliation_required_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'aggregate_type', 'aggregate_id', 'idempotency_key']);
        });
    }

    public function test_document_events_form_tenant_and_aggregate_safe_hash_chains(): void
    {
        $service = $this->service();
        $actor = $this->actor(51, 7);
        $document = $this->document(101, 7);

        $service->record('create', $document, $actor, ['status' => 'draft']);
        $service->record('update', $document, $actor, ['status' => 'review']);
        $service->record('create', $this->document(101, 8), $this->actor(52, 8));

        $firstChain = ImmutableAuditEvent::query()
            ->where('organization_id', 7)
            ->where('subject_id', '101')
            ->orderBy('sequence_id')
            ->get();
        $foreign = ImmutableAuditEvent::query()->where('organization_id', 8)->sole();

        self::assertCount(2, $firstChain);
        self::assertNull($firstChain[0]->previous_hash);
        self::assertSame($firstChain[0]->record_hash, $firstChain[1]->previous_hash);
        self::assertNull($foreign->previous_hash);
        self::assertSame('organization:7:legal_document:101', $firstChain[0]->chain_scope);
        self::assertTrue((new ImmutableAuditIntegrityService)->verifyChain($firstChain)['valid']);
    }

    public function test_payload_is_canonical_redacted_and_does_not_store_file_content_or_secrets(): void
    {
        $service = $this->service();
        $service->record('sign', $this->document(5, 2), $this->actor(9, 2), [
            'version_id' => 44,
            'content' => base64_encode('confidential pdf'),
            'content_hash' => str_repeat('a', 64),
            'size_bytes' => 4096,
            'access_token' => 'secret-token',
            'nested' => ['private_key' => 'private-key'],
            'safe' => ['b' => 2, 'a' => 1],
        ]);

        $event = ImmutableAuditEvent::query()->sole();

        self::assertSame(ImmutableAuditRedactor::REDACTED, $event->domain_context['content']);
        self::assertSame(str_repeat('a', 64), $event->domain_context['content_hash']);
        self::assertSame(4096, $event->domain_context['size_bytes']);
        self::assertSame(ImmutableAuditRedactor::REDACTED, $event->domain_context['access_token']);
        self::assertSame(ImmutableAuditRedactor::REDACTED, $event->domain_context['nested']['private_key']);
        self::assertSame(['a' => 1, 'b' => 2], $event->domain_context['safe']);
        self::assertStringNotContainsString(
            'confidential pdf',
            json_encode($event->domain_context, JSON_THROW_ON_ERROR),
        );
    }

    public function test_audit_record_rolls_back_with_domain_transaction(): void
    {
        try {
            $this->database->getConnection()->transaction(function (): void {
                $this->service()->record('archive', $this->document(5, 2), $this->actor(9, 2));

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(0, ImmutableAuditEvent::query()->count());
    }

    public function test_audit_and_outbox_are_committed_or_rolled_back_together(): void
    {
        $service = $this->service();

        $this->database->getConnection()->transaction(function () use ($service): void {
            $service->record('create', $this->document(5, 2), $this->actor(9, 2));
        });

        self::assertSame(1, ImmutableAuditEvent::query()->count());
        self::assertSame(1, LegalDocumentOutboxMessage::query()->count());

        try {
            $this->database->getConnection()->transaction(function () use ($service): void {
                $service->record('update', $this->document(5, 2), $this->actor(9, 2));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(1, ImmutableAuditEvent::query()->count());
        self::assertSame(1, LegalDocumentOutboxMessage::query()->count());
    }

    public function test_audit_model_rejects_updates_and_deletes(): void
    {
        $this->service()->record('create', $this->document(5, 2), $this->actor(9, 2));
        $event = ImmutableAuditEvent::query()->sole();

        try {
            $event->forceFill(['action' => 'tampered'])->save();
            self::fail('Audit event update must be rejected.');
        } catch (RuntimeException) {
        }

        $this->expectException(RuntimeException::class);
        $event->delete();
    }

    public function test_postgres_migration_keeps_append_only_guard_and_adds_concurrency_safe_sequence(): void
    {
        $baseMigration = file_get_contents(
            __DIR__.'/../../../database/migrations/2026_06_22_000001_create_immutable_audit_tables.php',
        );
        $extension = file_get_contents(
            __DIR__.'/../../../database/migrations/2026_07_19_000310_extend_immutable_audit_for_legal_documents.php',
        );

        self::assertIsString($baseMigration);
        self::assertIsString($extension);
        self::assertStringContainsString('immutable_audit_events_append_only', $baseMigration);
        self::assertStringContainsString("'contracts', 'legal_archive'", $extension);
        self::assertStringContainsString('immutable_audit_sequence', $extension);
        $validation = file_get_contents(
            __DIR__.'/../../../database/migrations/2026_07_19_000311_validate_immutable_audit_legal_domains.php',
        );
        self::assertIsString($validation);
        self::assertStringContainsString('NOT VALID', $extension);
        self::assertStringNotContainsString('VALIDATE CONSTRAINT', $extension);
        self::assertStringContainsString('VALIDATE CONSTRAINT', $validation);
        $rollout = file_get_contents(__DIR__.'/../../../app/BusinessModules/Core/ImmutableAudit/Services/ImmutableAuditRolloutService.php');
        self::assertIsString($rollout);
        self::assertStringContainsString('immutable_audit_allocate_sequence', $rollout);
        self::assertStringContainsString('CREATE TRIGGER immutable_audit_sequence_sync', $rollout);
        self::assertStringContainsString('AFTER INSERT', $rollout);
        self::assertStringContainsString('MAX(sequence_id), 0) + 2', $rollout);
        self::assertStringContainsString('LOCK TABLE immutable_audit_events IN SHARE ROW EXCLUSIVE MODE', $rollout);
        self::assertStringNotContainsString('NEW.sequence_id :=', $rollout);
        self::assertStringNotContainsString('SET DEFAULT nextval', $extension);
    }

    public function test_source_idempotency_is_aggregate_scoped_and_conflicts_are_rejected(): void
    {
        $service = $this->service();
        $actor = $this->actor(9, 2);
        $context = [
            'source_event_id' => 'external:17',
            'after' => ['status' => 'draft'],
        ];

        $service->record('create', $this->document(5, 2), $actor, $context);
        $service->record('create', $this->document(6, 2), $actor, $context);
        $service->record('create', $this->document(5, 2), $actor, $context);

        self::assertSame(2, ImmutableAuditEvent::query()->count());
        self::assertSame(2, LegalDocumentOutboxMessage::query()->count());

        $this->expectException(DomainException::class);
        $service->record('update', $this->document(5, 2), $actor, [
            'source_event_id' => 'external:17',
            'after' => ['status' => 'active'],
        ]);
    }

    public function test_duplicate_comparison_rejects_changed_project_and_evidence_fields(): void
    {
        $recorder = new ImmutableAuditRecorder(
            new ImmutableAuditRedactor,
            new ImmutableAuditIntegrityService,
            $this->database->getConnection(),
        );
        $base = new \App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData(
            organizationId: 2,
            domain: 'legal_archive',
            eventType: 'legal_document.issued',
            action: 'issued',
            source: 'legal_archive',
            projectId: 10,
            sourceEventId: 'issue:5',
            subjectType: 'legal_document',
            subjectId: 5,
            beforeState: ['status' => 'ready'],
            relatedSubjects: [['type' => 'version', 'id' => 7]],
        );
        $recorder->record($base);

        $this->expectException(DomainException::class);
        $recorder->record(new \App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData(
            organizationId: 2,
            domain: 'legal_archive',
            eventType: 'legal_document.issued',
            action: 'issued',
            source: 'legal_archive',
            projectId: 11,
            sourceEventId: 'issue:5',
            subjectType: 'legal_document',
            subjectId: 5,
            beforeState: ['status' => 'changed'],
            relatedSubjects: [['type' => 'version', 'id' => 8]],
        ));
    }

    public function test_idempotency_index_rollout_preserves_legacy_contour_and_is_forward_only(): void
    {
        $migration = file_get_contents(
            __DIR__.'/../../../database/migrations/2026_07_19_000320_scope_immutable_audit_idempotency.php',
        );
        self::assertIsString($migration);
        self::assertStringNotContainsString('DROP INDEX', $migration);
        $rollout = file_get_contents(__DIR__.'/../../../app/BusinessModules/Core/ImmutableAudit/Services/ImmutableAuditRolloutService.php');
        $command = file_get_contents(__DIR__.'/../../../app/Console/Commands/ImmutableAuditPhaseBCutoverCommand.php');
        self::assertIsString($rollout);
        self::assertIsString($command);
        self::assertStringContainsString('immutable_audit_source_event_aggregate_unique', $rollout);
        self::assertStringContainsString('immutable_audit_source_event_legacy_unique', $rollout);
        self::assertStringContainsString('phase_b_writer_fence_not_confirmed', $rollout);
        self::assertStringContainsString('--confirm-writer-version=', $command);
    }

    public function test_opt_in_postgres_test_exercises_production_recorder_integrity_and_outbox(): void
    {
        $test = file_get_contents(
            __DIR__.'/../../Integration/LegalArchive/LegalDocumentAuditPostgresIntegrationTest.php',
        );
        self::assertIsString($test);
        self::assertStringContainsString('new ImmutableAuditRecorder', $test);
        self::assertStringContainsString('verifyEvent(', $test);
        self::assertStringContainsString('verifyChain(', $test);
        self::assertStringContainsString('new LegalDocumentOutbox', $test);
        self::assertStringContainsString("getConnection('legal_pg_first')", $test);
        self::assertStringContainsString("getConnection('legal_pg_second')", $test);
        self::assertStringContainsString('new Process([PHP_BINARY', $test);
        self::assertStringContainsString('new ImmutableAuditRolloutService', $test);
        self::assertStringContainsString('installCompatibilityPhase', $test);
        self::assertStringContainsString('cutover($this->first, false, 2)', $test);
        self::assertStringContainsString('cutover($this->first, true, 2)', $test);
        self::assertStringContainsString('immutable_audit_source_event_unique', $test);
        self::assertStringContainsString("'legacy:1', 'legacy'", $test);
        self::assertStringContainsString('count(array_unique', $test);
        self::assertStringNotContainsString('SKIP LOCKED', $test);
        self::assertStringNotContainsString('pg_try_advisory', $test);
    }

    public function test_service_uses_only_injected_connection_for_audit_and_outbox(): void
    {
        $this->database->addConnection(
            ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'isolated',
        );
        $connection = $this->database->getConnection('isolated');
        foreach (['immutable_audit_events', 'legal_document_outbox'] as $table) {
            $connection->getSchemaBuilder()->create($table, function (Blueprint $schema) use ($table): void {
                if ($table === 'immutable_audit_events') {
                    $schema->uuid('id')->primary();
                    $schema->unsignedBigInteger('sequence_id')->unique();
                    $schema->unsignedBigInteger('organization_id');
                    $schema->unsignedBigInteger('project_id')->nullable();
                    foreach (['domain', 'event_type', 'action', 'result', 'severity', 'actor_type', 'source', 'redaction_policy_version', 'payload_hash', 'record_hash', 'chain_scope', 'integrity_status'] as $column) {
                        $schema->string($column);
                    }
                    foreach (['source_route', 'source_model', 'source_table', 'source_event_id', 'correlation_id', 'idempotency_key', 'subject_type', 'subject_id', 'subject_label', 'reason', 'previous_hash', 'seal_id'] as $column) {
                        $schema->string($column)->nullable();
                    }
                    foreach (['actor_snapshot', 'related_subjects', 'before_state', 'after_state', 'diff', 'domain_context', 'sensitive_fields'] as $column) {
                        $schema->json($column)->nullable();
                    }
                    $schema->unsignedBigInteger('actor_user_id')->nullable();
                    $schema->unsignedBigInteger('impersonator_user_id')->nullable();
                    $schema->unsignedSmallInteger('chain_version');
                    foreach (['occurred_at', 'recorded_at', 'sealed_at', 'retention_until', 'created_at'] as $column) {
                        $schema->timestamp($column)->nullable();
                    }
                } else {
                    $schema->uuid('id')->primary();
                    $schema->unsignedBigInteger('organization_id');
                    foreach (['aggregate_type', 'aggregate_id', 'event', 'payload_hash', 'idempotency_key'] as $column) {
                        $schema->string($column);
                    }
                    $schema->json('payload');
                    $schema->unsignedInteger('attempts')->default(0);
                    foreach (['available_at', 'published_at', 'claimed_at', 'dead_lettered_at', 'reconciliation_required_at', 'created_at', 'updated_at'] as $column) {
                        $schema->timestamp($column)->nullable();
                    }
                    $schema->text('last_error')->nullable();
                    $schema->uuid('claim_token')->nullable();
                }
            });
        }

        $service = $this->service(connection: $connection);
        $service->record('create', $this->document(55, 2), $this->actor(9, 2));

        self::assertSame(0, ImmutableAuditEvent::query()->count());
        self::assertSame(1, $connection->table('immutable_audit_events')->count());
        self::assertSame(1, $connection->table('legal_document_outbox')->count());
    }

    public function test_registry_source_idempotency_includes_document_identity(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../../app/Services/LegalArchive/LegalArchiveRegistryService.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('"{$action}:{$documentId}:{$key}"', $source);
    }

    private function service(?\Illuminate\Database\ConnectionInterface $connection = null): LegalDocumentAuditService
    {
        $connection ??= $this->database->getConnection();

        return new LegalDocumentAuditService(new ImmutableAuditRecorder(
            new ImmutableAuditRedactor,
            new ImmutableAuditIntegrityService,
            $connection,
        ), new LegalDocumentOutbox(dispatchJobs: false, connection: $connection), $connection);
    }

    private function document(int $id, int $organizationId): LegalArchiveDocument
    {
        $document = new LegalArchiveDocument;
        $document->forceFill([
            'id' => $id,
            'organization_id' => $organizationId,
            'primary_project_id' => null,
            'title' => 'Договор поставки',
        ]);

        return $document;
    }

    private function actor(int $id, int $organizationId): User
    {
        $actor = new User;
        $actor->forceFill([
            'id' => $id,
            'current_organization_id' => $organizationId,
            'name' => 'Юрист',
        ]);

        return $actor;
    }
}
