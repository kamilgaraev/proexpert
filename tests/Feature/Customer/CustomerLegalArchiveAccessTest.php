<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Models\User;
use App\Services\LegalArchive\Workflow\LegalWorkflowAuthorization;
use App\Services\LegalArchive\Workflow\LegalWorkflowPermissions;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

final class CustomerLegalArchiveAccessTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        Container::setInstance($container);
        $container->instance(PermissionResolver::class, new CustomerLegalArchivePermissionResolver);
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher($container));
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $this->schema();
    }

    public function test_external_customer_bulk_access_uses_only_current_organization_active_grants(): void
    {
        $actor = $this->actor(7, 20);
        $this->membership(20, 7);
        $documents = collect();
        foreach (range(1, 64) as $id) {
            $documents->push($this->document($id, 10));
        }
        $this->grant(1, 10, 20, 7, ['view', 'approve']);
        $this->grant(2, 10, 20, 7, ['view', 'approve'], revoked: true);
        $this->grant(3, 10, 20, 7, ['view', 'approve'], expired: true);
        $this->grant(4, 10, 21, 7, ['view', 'approve']);

        $connection = $this->database->getConnection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $permissions = (new LegalWorkflowAuthorization)->forMany($actor, $documents, [
            LegalWorkflowPermissions::VIEW,
            LegalWorkflowPermissions::APPROVE,
            LegalWorkflowPermissions::REJECT,
            LegalWorkflowPermissions::RETURN,
        ]);
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();

        self::assertCount(2, $queries);
        self::assertTrue($permissions[1][LegalWorkflowPermissions::VIEW]);
        self::assertTrue($permissions[1][LegalWorkflowPermissions::APPROVE]);
        self::assertTrue($permissions[1][LegalWorkflowPermissions::REJECT]);
        self::assertTrue($permissions[1][LegalWorkflowPermissions::RETURN]);
        self::assertFalse($permissions[2][LegalWorkflowPermissions::VIEW]);
        self::assertFalse($permissions[3][LegalWorkflowPermissions::VIEW]);
        self::assertFalse($permissions[4][LegalWorkflowPermissions::VIEW]);
        self::assertFalse($permissions[64][LegalWorkflowPermissions::VIEW]);
    }

    private function actor(int $id, int $organizationId): User
    {
        $actor = new User;
        $actor->forceFill(['id' => $id, 'current_organization_id' => $organizationId]);
        $actor->exists = true;

        return $actor;
    }

    private function membership(int $organizationId, int $userId): void
    {
        $this->database->table('organizations')->insert(['id' => $organizationId]);
        $this->database->table('organization_user')->insert([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'is_active' => true,
        ]);
    }

    private function document(int $id, int $organizationId): LegalArchiveDocument
    {
        $document = new LegalArchiveDocument;
        $document->forceFill([
            'id' => $id,
            'organization_id' => $organizationId,
            'title' => "Document {$id}",
        ]);
        $document->exists = true;

        return $document;
    }

    /** @param list<string> $abilities */
    private function grant(int $documentId, int $organizationId, int $subjectOrganizationId, int $subjectUserId, array $abilities, bool $revoked = false, bool $expired = false): void
    {
        LegalDocumentAccessGrant::query()->create([
            'organization_id' => $organizationId,
            'document_id' => $documentId,
            'subject_organization_id' => $subjectOrganizationId,
            'subject_user_id' => $subjectUserId,
            'subject_kind' => 'external_user',
            'abilities' => $abilities,
            'granted_by_user_id' => 1,
            'revoked_at' => $revoked ? now() : null,
            'revoked_by_user_id' => $revoked ? 1 : null,
            'revocation_reason' => $revoked ? 'withdrawn' : null,
            'expires_at' => $expired ? now()->subSecond() : null,
        ]);
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
        $schema->create('legal_document_access_grants', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('subject_organization_id');
            $table->unsignedBigInteger('subject_user_id')->nullable();
            $table->string('subject_kind')->nullable();
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

final class CustomerLegalArchivePermissionResolver extends PermissionResolver
{
    public function __construct() {}

    public function hasPermission(UserRoleAssignment $assignment, string $permission, ?array $context = null): bool
    {
        return false;
    }
}
