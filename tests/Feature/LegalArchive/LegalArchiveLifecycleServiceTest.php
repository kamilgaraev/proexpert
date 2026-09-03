<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\Contract;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\LegalArchiveLifecycleService;
use App\Services\LegalArchive\LegalDocumentAggregateLock;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

if (! class_exists(LegalArchiveLifecycleService::class, false)) {
    require_once dirname(__DIR__, 3).'/app/Services/LegalArchive/LegalArchiveLifecycleService.php';
}

final class LegalArchiveLifecycleServiceTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new Capsule;
        $this->database->addConnection(\Tests\Support\IsolatedPostgresTestDatabase::configuration());
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher(new Container));
        $this->database->bootEloquent();
        Model::clearBootedModels();

        $this->database->schema()->create('legal_archive_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('title');
            $table->string('status');
            $table->string('lifecycle_status');
            $table->string('approval_status');
            $table->string('signature_status');
            $table->unsignedInteger('lock_version')->default(0);
            $table->json('structured_fields')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_user_id')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        $this->database->getConnection()->statement(
            "ALTER TABLE legal_archive_documents ADD CONSTRAINT legal_docs_lifecycle_status_check CHECK (lifecycle_status IN ('draft', 'under_review', 'revision_required', 'rejected', 'approved', 'signing', 'partially_signed', 'signed', 'signature_failed', 'effective', 'suspended', 'completed', 'terminated', 'expired', 'archived'))"
        );
        $this->database->schema()->create('immutable_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->bigIncrements('sequence_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('domain');
            $table->string('event_type');
            $table->string('subject_type');
            $table->string('subject_id');
            $table->jsonb('before_state');
            $table->jsonb('after_state');
        });
    }

    protected function tearDown(): void
    {
        $this->database->schema()->dropIfExists('legal_archive_documents');
        $this->database->schema()->dropIfExists('immutable_audit_events');

        parent::tearDown();
    }

    public function test_signed_document_becomes_effective_when_activated(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();

        $activated = $service->activate($document, $actor, 0);

        self::assertSame('active', $activated->status);
        self::assertSame('effective', $activated->lifecycle_status);
        self::assertSame(1, $activated->lock_version);
        self::assertNotNull($activated->activated_at);
        self::assertSame(['activated'], $audit->events);
    }

    public function test_effective_document_cannot_be_activated_again_with_a_fresh_lock(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
        $activated = $service->activate($document, $actor, 0);
        $activatedAt = $activated->activated_at->toISOString();

        try {
            $service->activate($activated, $actor, 1);
            self::fail('Repeated activation must not overwrite the legal history.');
        } catch (DomainException $error) {
            self::assertSame('activation_state_not_allowed', $error->getMessage());
        }

        self::assertSame('effective', $document->refresh()->lifecycle_status);
        self::assertSame(1, $document->lock_version);
        self::assertSame($activatedAt, $document->activated_at->toISOString());
        self::assertSame(['activated'], $audit->events);
    }

    public function test_archived_signed_document_cannot_be_activated_without_restoring(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
        $archived = $service->archive($document, $actor, 0);
        $archivedAt = $archived->archived_at->toISOString();

        try {
            $service->activate($archived, $actor, 1);
            self::fail('Activation must not bypass the archived document state.');
        } catch (DomainException $error) {
            self::assertSame('activation_state_not_allowed', $error->getMessage());
        }

        self::assertSame('archived', $document->refresh()->status);
        self::assertSame('archived', $document->lifecycle_status);
        self::assertSame(1, $document->lock_version);
        self::assertSame($archivedAt, $document->archived_at->toISOString());
        self::assertNull($document->activated_at);
        self::assertSame(['archived'], $audit->events);
    }

    public function test_restoring_signed_document_allows_its_first_activation(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
        $archived = $service->archive($document, $actor, 0);
        $restored = $service->restore($archived, $actor, 1);

        self::assertSame('draft', $restored->status);
        self::assertSame('signed', $restored->lifecycle_status);
        self::assertSame('approved', $restored->approval_status);
        self::assertSame('signed', $restored->signature_status);
        self::assertNull($restored->archived_at);
        self::assertNull($restored->archived_by_user_id);
        self::assertSame('effective', $service->activate($restored, $actor, 2)->lifecycle_status);
    }

    public function test_restoring_effective_document_preserves_activation_and_latest_archive_cycle(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
        $service->archive($document, $actor, 0);
        $service->restore($document, $actor, 1);
        $activated = $service->activate($document, $actor, 2);
        $activatedAt = $activated->activated_at->toISOString();
        $service->archive($document, $actor, 3);
        $restored = $service->restore($document, $actor, 4);

        self::assertSame('active', $restored->status);
        self::assertSame('effective', $restored->lifecycle_status);
        self::assertSame($activatedAt, $restored->activated_at->toISOString());
        self::assertSame(5, $restored->lock_version);
        self::assertSame(['archived', 'restored', 'activated', 'archived', 'restored'], $audit->events);
    }

    public function test_repeated_archive_is_rejected_without_overwriting_date_or_history(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
        $archived = $service->archive($document, $actor, 0);
        $archivedAt = $archived->archived_at->toISOString();
        try {
            $service->archive($archived, $actor, 1);
            self::fail('Repeated archive must not overwrite its original state.');
        } catch (DomainException $error) {
            self::assertSame('document_already_archived', $error->getMessage());
        }
        self::assertSame(1, $document->refresh()->lock_version);
        self::assertSame($archivedAt, $document->archived_at->toISOString());
        self::assertSame(['archived'], $audit->events);
    }

    public function test_restore_does_not_guess_a_state_when_history_is_missing(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
        $document->forceFill(['status' => 'archived', 'lifecycle_status' => 'archived', 'archived_at' => now()])->save();
        try {
            $service->restore($document, $actor, 0);
            self::fail('Missing history must not silently reset a signed document.');
        } catch (DomainException $error) {
            self::assertSame('archive_restore_state_unavailable', $error->getMessage());
        }
        self::assertSame('archived', $document->refresh()->lifecycle_status);
        self::assertSame(0, $document->lock_version);
        self::assertSame([], $audit->events);
    }

    public function test_restore_ignores_other_organizations_and_documents(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
        $service->archive($document, $actor, 0);
        foreach ([[39, (string) $document->id], [38, '999']] as [$organizationId, $subjectId]) {
            ImmutableAuditEvent::query()->create([
                'organization_id' => $organizationId, 'domain' => 'legal_archive',
                'event_type' => 'legal_document.archived', 'subject_type' => 'legal_document', 'subject_id' => $subjectId,
                'before_state' => ['status' => 'active', 'lifecycle_status' => 'effective'],
                'after_state' => ['status' => 'archived', 'lifecycle_status' => 'archived'],
            ]);
        }
        self::assertSame('signed', $service->restore($document, $actor, 1)->lifecycle_status);
    }

    public function test_restore_recovers_original_state_after_historical_duplicate_archive(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
        $service->archive($document, $actor, 0);
        $document->refresh()->forceFill(['lock_version' => 2])->save();
        $audit->record('archived', $document, $actor, [
            'before' => ['status' => 'archived', 'lifecycle_status' => 'archived', 'lock_version' => 1],
            'after' => ['status' => 'archived', 'lifecycle_status' => 'archived', 'lock_version' => 2],
        ]);

        self::assertSame('signed', $service->restore($document, $actor, 2)->lifecycle_status);
        self::assertSame(3, $document->refresh()->lock_version);
    }

    public function test_incomplete_latest_archive_does_not_reuse_an_older_cycle(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
        $service->archive($document, $actor, 0);
        $audit->record('archived', $document, $actor, [
            'before' => [],
            'after' => ['status' => 'archived', 'lifecycle_status' => 'archived'],
        ]);

        try {
            $service->restore($document, $actor, 1);
            self::fail('An incomplete latest archive must not restore an older legal state.');
        } catch (DomainException $error) {
            self::assertSame('archive_restore_state_unavailable', $error->getMessage());
        }
        self::assertSame('archived', $document->refresh()->lifecycle_status);
        self::assertSame(1, $document->lock_version);
    }

    public function test_stale_restore_does_not_change_the_archived_document(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
        $service->archive($document, $actor, 0);
        try {
            $service->restore($document, $actor, 0);
            self::fail('A stale restore must not consume the archive state.');
        } catch (\App\Services\LegalArchive\LegalArchiveLockConflict) {
            self::assertSame('archived', $document->refresh()->lifecycle_status);
        }
        self::assertSame(1, $document->lock_version);
        self::assertSame(['archived'], $audit->events);
    }

    public function test_plain_draft_restores_as_editable_draft(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
        $document->forceFill(['lifecycle_status' => 'draft', 'approval_status' => 'not_started', 'signature_status' => 'not_signed'])->save();
        $service->archive($document, $actor, 0);
        $restored = $service->restore($document, $actor, 1);
        self::assertSame('draft', $restored->status);
        self::assertSame('draft', $restored->lifecycle_status);
        self::assertSame('not_started', $restored->approval_status);
        self::assertSame('not_signed', $restored->signature_status);
    }

    public function test_unknown_lifecycle_in_archive_history_is_rejected_before_database_write(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
        $service->archive($document, $actor, 0);
        $audit->record('archived', $document, $actor, [
            'before' => ['status' => 'active', 'lifecycle_status' => 'unknown_state'],
            'after' => ['status' => 'archived', 'lifecycle_status' => 'archived'],
        ]);
        try {
            $service->restore($document, $actor, 1);
            self::fail('Unknown lifecycle must return a domain conflict instead of a database failure.');
        } catch (DomainException $error) {
            self::assertSame('archive_restore_state_unavailable', $error->getMessage());
        }
        self::assertSame('archived', $document->refresh()->lifecycle_status);
        self::assertSame(1, $document->lock_version);
        self::assertSame(['archived', 'archived'], $audit->events);
    }

    public function test_malformed_duplicate_archive_does_not_fall_back_to_earlier_history(): void
    {
        [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
        $service->archive($document, $actor, 0);
        $audit->record('archived', $document, $actor, [
            'before' => ['status' => 'archived', 'lifecycle_status' => 'archived'],
            'after' => ['status' => 'active', 'lifecycle_status' => 'signed'],
        ]);
        try {
            $service->restore($document, $actor, 1);
            self::fail('Malformed duplicate must not conceal incomplete archive history.');
        } catch (DomainException $error) {
            self::assertSame('archive_restore_state_unavailable', $error->getMessage());
        }
        self::assertSame('archived', $document->refresh()->lifecycle_status);
        self::assertSame(1, $document->lock_version);
    }

    public function test_archive_requires_approval_or_signing_to_finish_first(): void
    {
        foreach ([['pending', 'signed'], ['approved', 'signing'], ['approved', 'partially_signed']] as [$approvalStatus, $lifecycleStatus]) {
            [$document, $actor, $audit, $service] = $this->signedDocumentFixture();
            $document->forceFill(['approval_status' => $approvalStatus, 'lifecycle_status' => $lifecycleStatus])->save();
            try {
                $service->archive($document, $actor, 0);
                self::fail('An active approval or signature must finish before archive.');
            } catch (DomainException $error) {
                self::assertSame('archive_document_in_progress', $error->getMessage());
            }
            self::assertNull($document->refresh()->archived_at);
            self::assertSame(0, $document->lock_version);
            self::assertSame([], $audit->events);
        }
    }

    private function signedDocumentFixture(): array
    {
        $document = LegalArchiveDocument::query()->create([
            'organization_id' => 38,
            'title' => 'Договор поставки',
            'status' => 'draft',
            'lifecycle_status' => 'signed',
            'approval_status' => 'approved',
            'signature_status' => 'signed',
            'lock_version' => 0,
            'structured_fields' => [],
        ]);
        $actor = new User;
        $actor->forceFill(['id' => 39]);
        $audit = new LifecycleAuditRecorder;
        $service = new LegalArchiveLifecycleService(
            new PermissiveLegalDocumentAuthorizer,
            $audit,
            $this->database->getConnection(),
            new LegalDocumentAggregateLock,
        );

        return [$document, $actor, $audit, $service];
    }
}

final class PermissiveLegalDocumentAuthorizer implements LegalDocumentAuthorizer
{
    public function authorize(User $user, LegalArchiveDocument $document, string $ability): void {}

    public function authorizePermission(User $user, LegalArchiveDocument $document, string $permission): void {}

    public function scopeAccessibleQuery(Builder $query, User $user, int $organizationId, string $ability = 'view'): Builder
    {
        return $query;
    }
}

final class LifecycleAuditRecorder implements LegalDocumentAudit
{
    public array $events = [];

    public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void
    {
        ImmutableAuditEvent::query()->create([
            'organization_id' => (int) $document->organization_id,
            'domain' => 'legal_archive',
            'event_type' => 'legal_document.'.$event,
            'subject_type' => 'legal_document',
            'subject_id' => (string) $document->id,
            'before_state' => $context['before'] ?? [],
            'after_state' => $context['after'] ?? [],
        ]);
        $this->events[] = $event;
    }

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

    public function recordContractForActorId(string $event, Contract $contract, ?int $actorId, array $context = []): void {}
}
