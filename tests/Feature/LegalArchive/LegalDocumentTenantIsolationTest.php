<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAccessService;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Workflow\LegalWorkflowAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

final class LegalDocumentTenantIsolationTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher(new Container));
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $this->schema();
    }

    public function test_foreign_organization_is_denied_without_an_active_object_grant(): void
    {
        $document = $this->document(10, 50);
        $actor = $this->actor(7, 20);
        $service = $this->service(true, true);

        $this->expectException(AuthorizationException::class);
        $service->authorize($actor, $document, 'view');
    }

    public function test_active_grant_is_user_and_organization_scoped_and_abilities_are_exact(): void
    {
        $document = $this->document(10, 50);
        $actor = $this->actor(7, 20);
        LegalDocumentAccessGrant::query()->create([
            'organization_id' => 10,
            'document_id' => $document->id,
            'subject_organization_id' => 20,
            'subject_user_id' => 7,
            'abilities' => ['view', 'comment'],
            'granted_by_user_id' => 1,
        ]);

        $service = $this->service(false, true);
        $service->authorize($actor, $document, 'view');

        $this->expectException(AuthorizationException::class);
        $service->authorize($actor, $document, 'download');
    }

    public function test_expired_revoked_and_wrong_user_grants_fail_closed(): void
    {
        $document = $this->document(10, 50);
        $actor = $this->actor(7, 20);
        foreach ([
            ['subject_user_id' => 8],
            ['subject_user_id' => 7, 'expires_at' => now()->subMinute()],
            ['subject_user_id' => 7, 'revoked_at' => now(), 'revoked_by_user_id' => 1, 'revocation_reason' => 'Доступ прекращён'],
        ] as $overrides) {
            LegalDocumentAccessGrant::query()->create([
                'organization_id' => 10,
                'document_id' => $document->id,
                'subject_organization_id' => 20,
                'abilities' => ['view'],
                'granted_by_user_id' => 1,
                ...$overrides,
            ]);
        }

        $this->expectException(AuthorizationException::class);
        $this->service(false, true)->authorize($actor, $document, 'view');
    }

    public function test_internal_project_document_requires_project_access_in_addition_to_rbac(): void
    {
        $document = $this->document(10, 50);
        $actor = $this->actor(7, 10);

        $this->expectException(AuthorizationException::class);
        $this->service(true, false)->authorize($actor, $document, 'view');
    }

    public function test_grant_and_revoke_are_atomic_audited_and_immediately_change_authorization(): void
    {
        $this->database->table('organizations')->insert([['id' => 10], ['id' => 20]]);
        $this->database->table('organization_user')->insert([
            'organization_id' => 20,
            'user_id' => 7,
            'is_active' => true,
        ]);
        $document = $this->document(10, null);
        $manager = $this->actor(1, 10);
        $recipient = $this->actor(7, 20);
        $audit = new RecordingAccessAudit;
        $service = $this->service(true, true, $audit);

        $grant = $service->grant($document, $manager, 20, 7, ['view', 'download'], CarbonImmutable::now()->addHour());
        $service->authorize($recipient, $document, 'download');
        $revoked = $service->revoke($document, $grant, $manager, 'Полномочия представителя прекращены');

        self::assertNotNull($revoked->revoked_at);
        self::assertSame(['access_granted', 'access_revoked'], $audit->events);
        $this->expectException(AuthorizationException::class);
        $service->authorize($recipient, $document, 'view');
    }

    public function test_expired_grant_is_closed_and_replaced_without_losing_history(): void
    {
        $this->database->table('organizations')->insert([['id' => 10], ['id' => 20]]);
        $this->database->table('organization_user')->insert([
            'organization_id' => 20,
            'user_id' => 7,
            'is_active' => true,
        ]);
        $document = $this->document(10, null);
        $expired = LegalDocumentAccessGrant::query()->create([
            'organization_id' => 10,
            'document_id' => $document->id,
            'subject_organization_id' => 20,
            'subject_user_id' => 7,
            'abilities' => ['view'],
            'granted_by_user_id' => 1,
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);
        $audit = new RecordingAccessAudit;
        $service = $this->service(true, true, $audit);

        $replacement = $service->grant(
            $document,
            $this->actor(1, 10),
            20,
            7,
            ['view'],
            CarbonImmutable::now()->addHour(),
        );

        self::assertNotSame((int) $expired->id, (int) $replacement->id);
        self::assertNotNull($expired->fresh()?->revoked_at);
        self::assertSame(['access_expired', 'access_granted'], $audit->events);
    }

    public function test_external_workflow_decisions_use_approve_grant_but_internal_management_stays_closed(): void
    {
        $document = $this->document(10, null);
        $recipient = $this->actor(7, 20);
        $access = $this->createMock(LegalDocumentAuthorizer::class);
        $access->expects(self::exactly(3))->method('authorize')->with($recipient, $document, 'approve');
        $workflow = new LegalWorkflowAuthorization($access);

        self::assertTrue($workflow->can($recipient, $document, 'legal_archive.workflow.approve'));
        self::assertTrue($workflow->can($recipient, $document, 'legal_archive.workflow.reject'));
        self::assertTrue($workflow->can($recipient, $document, 'legal_archive.workflow.return'));
        self::assertFalse($workflow->can($recipient, $document, 'legal_archive.workflow.reassign'));
    }

    private function service(bool $rbacAllowed, bool $projectAllowed, ?LegalDocumentAudit $audit = null): LegalDocumentAccessService
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn($rbacAllowed);

        return new LegalDocumentAccessService(
            $authorization,
            static fn (User $user, int $organizationId): bool => (int) $user->current_organization_id === $organizationId,
            static fn (User $user, int $projectId, int $organizationId): bool => $projectAllowed,
            null,
            $audit,
            $this->database->getConnection(),
        );
    }

    private function document(int $organizationId, ?int $projectId): LegalArchiveDocument
    {
        return LegalArchiveDocument::query()->create([
            'organization_id' => $organizationId,
            'primary_project_id' => $projectId,
            'title' => 'Досье',
        ]);
    }

    private function actor(int $id, int $organizationId): User
    {
        $actor = new User;
        $actor->forceFill(['id' => $id, 'current_organization_id' => $organizationId]);
        $actor->exists = true;

        return $actor;
    }

    private function schema(): void
    {
        $schema = $this->database->schema();
        $schema->create('organizations', static function (Blueprint $table): void {
            $table->id();
            $table->softDeletes();
        });
        $schema->create('organization_user', static function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_active');
        });
        $schema->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('primary_project_id')->nullable();
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('legal_document_access_grants', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('subject_organization_id');
            $table->unsignedBigInteger('subject_user_id')->nullable();
            $table->json('abilities');
            $table->unsignedBigInteger('granted_by_user_id');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
        });
    }
}

final class RecordingAccessAudit implements LegalDocumentAudit
{
    public array $events = [];

    public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void
    {
        $this->events[] = $event;
    }

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

    public function recordContractForActorId(string $event, Contract $contract, ?int $actorId, array $context = []): void {}
}
