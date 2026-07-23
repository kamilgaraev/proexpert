<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Http\Resources\Api\V1\Customer\CustomerLegalArchiveDocumentResource;
use App\Models\User;
use App\Services\LegalArchive\Workflow\LegalWorkflowAuthorization;
use App\Services\LegalArchive\Workflow\LegalWorkflowPermissions;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class CustomerLegalArchiveActionsTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        Container::setInstance($container);
        $container->instance(PermissionResolver::class, new CustomerLegalArchiveActionsPermissionResolver);
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher($container));
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $this->schema();
    }

    public function test_external_customer_can_see_only_granted_workflow_actions(): void
    {
        $actor = new User;
        $actor->forceFill(['id' => 7, 'current_organization_id' => 20]);
        $actor->exists = true;
        $this->database->table('organizations')->insert(['id' => 20]);
        $this->database->table('organization_user')->insert([
            'organization_id' => 20,
            'user_id' => 7,
            'is_active' => true,
        ]);
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 1, 'organization_id' => 10, 'title' => 'Contract']);
        $document->exists = true;
        LegalDocumentAccessGrant::query()->create([
            'organization_id' => 10,
            'document_id' => 1,
            'subject_organization_id' => 20,
            'subject_user_id' => 7,
            'subject_kind' => 'external_user',
            'abilities' => ['view', 'approve'],
            'granted_by_user_id' => 1,
        ]);

        $permissions = (new LegalWorkflowAuthorization)->forMany($actor, collect([$document]), [
            LegalWorkflowPermissions::VIEW,
            LegalWorkflowPermissions::SUBMIT,
            LegalWorkflowPermissions::APPROVE,
            LegalWorkflowPermissions::REJECT,
            LegalWorkflowPermissions::RETURN,
            LegalWorkflowPermissions::REASSIGN,
            LegalWorkflowPermissions::CANCEL,
        ]);

        self::assertTrue($permissions[1][LegalWorkflowPermissions::VIEW]);
        self::assertTrue($permissions[1][LegalWorkflowPermissions::APPROVE]);
        self::assertTrue($permissions[1][LegalWorkflowPermissions::REJECT]);
        self::assertTrue($permissions[1][LegalWorkflowPermissions::RETURN]);
        self::assertFalse($permissions[1][LegalWorkflowPermissions::SUBMIT]);
        self::assertFalse($permissions[1][LegalWorkflowPermissions::REASSIGN]);
        self::assertFalse($permissions[1][LegalWorkflowPermissions::CANCEL]);
    }

    public function test_customer_resource_does_not_expose_internal_document_fields(): void
    {
        $document = new LegalArchiveDocument;
        $document->forceFill([
            'id' => 1,
            'organization_id' => 10,
            'title' => 'Contract',
            'document_number' => 'C-1',
            'confidentiality_level' => 'secret',
            'metadata' => ['internal' => true],
            'source_create_attempt_token' => 'secret-token',
        ]);
        $document->setAttribute('customer_workflow_summary', ['status' => 'in_progress']);

        $payload = (new CustomerLegalArchiveDocumentResource($document))->toArray(new Request);

        self::assertSame(1, $payload['id']);
        self::assertSame('Contract', $payload['title']);
        self::assertSame(['status' => 'in_progress'], $payload['workflow_summary']);
        self::assertArrayNotHasKey('organization_id', $payload);
        self::assertArrayNotHasKey('confidentiality_level', $payload);
        self::assertArrayNotHasKey('metadata', $payload);
        self::assertArrayNotHasKey('source_create_attempt_token', $payload);
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
            $table->timestamps();
        });
    }
}

final class CustomerLegalArchiveActionsPermissionResolver extends PermissionResolver
{
    public function __construct() {}

    public function hasPermission(UserRoleAssignment $assignment, string $permission, ?array $context = null): bool
    {
        return false;
    }
}
