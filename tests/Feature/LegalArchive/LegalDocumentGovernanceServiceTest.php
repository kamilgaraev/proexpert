<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\LegalArchiveLockConflict;
use App\Services\LegalArchive\LegalDocumentGovernanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegalDocumentGovernanceServiceTest extends TestCase
{
    private Capsule $database;
    private Container $container;
    private GovernanceDocumentAuthorizer $authorizer;
    private GovernanceAuditRecorder $audit;
    private LegalArchiveDocument $document;
    private User $actor;

    protected function setUp(): void
    {
        $this->database = new Capsule;
        $this->database->addConnection(\Tests\Support\IsolatedPostgresTestDatabase::configuration());
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $this->container = new Container;
        $this->container->instance('db', $this->database->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->container);
        $this->database->schema()->create('legal_archive_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('primary_project_id')->nullable();
            $table->string('confidentiality_level');
            $table->string('retention_policy', 128)->nullable();
            $table->text('retention_basis')->nullable();
            $table->timestamp('retention_started_at')->nullable();
            $table->timestamp('retention_until')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $this->document = LegalArchiveDocument::query()->create([
            'organization_id' => 38,
            'primary_project_id' => 52,
            'confidentiality_level' => 'restricted',
        ]);
        $this->actor = new User;
        $this->actor->forceFill(['id' => 39, 'current_organization_id' => 38]);
        $this->authorizer = new GovernanceDocumentAuthorizer;
        $this->audit = new GovernanceAuditRecorder;
        $this->container->instance(LegalDocumentAuthorizer::class, $this->authorizer);
        $this->container->instance(LegalDocumentAudit::class, $this->audit);
        $organizationPermission = $this->createMock(AuthorizationService::class);
        $organizationPermission->method('can')->willReturn(true);
        $this->container->instance(AuthorizationService::class, $organizationPermission);
    }

    protected function tearDown(): void
    {
        $this->database->schema()->dropIfExists('legal_archive_documents');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    #[DataProvider('operations')]
    public function test_document_access_denial_prevents_mutation_despite_organization_permission(string $operation, string $permission): void
    {
        $this->authorizer->denyAt = 1;
        try {
            $this->mutate($operation);
            self::fail('A denied document must not be changed.');
        } catch (AuthorizationException) {
            self::assertSame([$permission], $this->authorizer->permissions);
            $this->assertUnchanged();
        }
    }

    #[DataProvider('operations')]
    public function test_access_is_rechecked_on_locked_document(string $operation, string $permission): void
    {
        $this->authorizer->denyAt = 2;
        try {
            $this->mutate($operation);
            self::fail('Access lost before the locked write must prevent mutation.');
        } catch (AuthorizationException) {
            self::assertSame([$permission, $permission], $this->authorizer->permissions);
            $this->assertUnchanged();
        }
    }

    #[DataProvider('operations')]
    public function test_authorized_change_is_saved_and_audited(string $operation, string $permission): void
    {
        $updated = $this->mutate($operation);
        self::assertSame([$permission, $permission], $this->authorizer->permissions);
        self::assertSame(1, $updated->lock_version);
        self::assertSame(39, $updated->updated_by_user_id);
        self::assertCount(1, $this->audit->events);
        if ($operation === 'retention') {
            self::assertSame('Срок по перечню организации', $updated->retention_policy);
            self::assertSame('Решение ответственного', $updated->retention_basis);
            self::assertSame('2026-09-03', $updated->retention_started_at->toDateString());
            self::assertSame('2031-09-03', $updated->retention_until->toDateString());
            self::assertFalse($updated->legal_hold);
            self::assertSame('retention_updated', $this->audit->events[0]['event']);
            self::assertNull($this->audit->events[0]['context']['before']['retention_policy']);
            self::assertSame('Срок по перечню организации', $this->audit->events[0]['context']['after']['retention_policy']);
        } else {
            self::assertTrue($updated->legal_hold);
            self::assertNull($updated->retention_policy);
            self::assertSame('legal_hold_enabled', $this->audit->events[0]['event']);
        }
    }

    #[DataProvider('operations')]
    public function test_stale_revision_does_not_overwrite_document(string $operation): void
    {
        $this->document->forceFill(['lock_version' => 2])->save();
        try {
            $this->mutate($operation);
            self::fail('A stale revision must be rejected.');
        } catch (LegalArchiveLockConflict $error) {
            self::assertSame(2, $error->currentLockVersion);
            $this->assertUnchanged(2);
        }
    }

    #[DataProvider('operations')]
    public function test_audit_failure_rolls_back_change(string $operation): void
    {
        $this->audit->fail = true;
        try {
            $this->mutate($operation);
            self::fail('Audit failure must roll back the change.');
        } catch (RuntimeException $error) {
            self::assertSame('test_audit_failure', $error->getMessage());
            $this->assertUnchanged();
        }
    }

    public static function operations(): array
    {
        return [
            'retention' => ['retention', 'legal_archive.retention.manage'],
            'legal hold' => ['hold', 'legal_archive.legal_hold.manage'],
        ];
    }

    private function mutate(string $operation): LegalArchiveDocument
    {
        $service = $this->container->make(LegalDocumentGovernanceService::class);

        return $operation === 'retention'
            ? $service->updateRetention($this->document, $this->actor, [
                'retention_policy' => 'Срок по перечню организации',
                'retention_basis' => 'Решение ответственного',
                'retention_started_at' => '2026-09-03',
                'retention_until' => '2031-09-03',
            ], 0)
            : $service->setLegalHold($this->document, $this->actor, true, 0);
    }

    private function assertUnchanged(int $lockVersion = 0): void
    {
        $this->document->refresh();
        self::assertSame($lockVersion, $this->document->lock_version);
        self::assertNull($this->document->retention_policy);
        self::assertFalse($this->document->legal_hold);
        self::assertSame([], $this->audit->events);
    }
}

final class GovernanceDocumentAuthorizer implements LegalDocumentAuthorizer
{
    public int $denyAt = 0;
    public array $permissions = [];

    public function authorizePermission(User $user, LegalArchiveDocument $document, string $permission): void
    {
        TestCase::assertSame(39, $user->id);
        TestCase::assertSame(38, $document->organization_id);
        TestCase::assertSame(52, $document->primary_project_id);
        TestCase::assertSame('restricted', $document->confidentiality_level);
        $this->permissions[] = $permission;
        if (count($this->permissions) === $this->denyAt) {
            throw new AuthorizationException;
        }
    }

    public function authorize(User $user, LegalArchiveDocument $document, string $ability): void
    {
        throw new RuntimeException('unexpected_generic_ability_check');
    }

    public function scopeAccessibleQuery(Builder $query, User $user, int $organizationId, string $ability = 'view'): Builder
    {
        return $query;
    }
}

final class GovernanceAuditRecorder implements LegalDocumentAudit
{
    public bool $fail = false;
    public array $events = [];

    public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void
    {
        if ($this->fail) {
            throw new RuntimeException('test_audit_failure');
        }
        $this->events[] = compact('event', 'context');
    }

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

    public function recordContractForActorId(string $event, Contract $contract, ?int $actorId, array $context = []): void {}
}
