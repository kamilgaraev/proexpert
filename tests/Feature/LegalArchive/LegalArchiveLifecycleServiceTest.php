<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

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
            "ALTER TABLE legal_archive_documents ADD CONSTRAINT legal_docs_lifecycle_status_check CHECK (lifecycle_status IN ('signed', 'effective', 'archived'))"
        );
    }

    protected function tearDown(): void
    {
        $this->database->schema()->dropIfExists('legal_archive_documents');

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
        $this->events[] = $event;
    }

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

    public function recordContractForActorId(string $event, Contract $contract, ?int $actorId, array $context = []): void {}
}
