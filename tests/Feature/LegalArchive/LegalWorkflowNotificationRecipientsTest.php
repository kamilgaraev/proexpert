<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Enums\UserProjectAccessMode;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Workflow\LegalWorkflowActorResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedPostgresTestDatabase;

final class LegalWorkflowNotificationRecipientsTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Capsule;
        $this->database->addConnection(IsolatedPostgresTestDatabase::configuration());
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $schema = $this->database->schema();
        foreach (['user_role_assignments', 'authorization_contexts', 'project_user', 'organization_user', 'users'] as $table) {
            $schema->dropIfExists($table);
        }
        $schema->create('users', static function (Blueprint $table): void {
            $table->bigInteger('id')->primary();
            $table->bigInteger('current_organization_id');
            $table->softDeletes();
        });
        $schema->create('organization_user', static function (Blueprint $table): void {
            $table->bigInteger('user_id');
            $table->bigInteger('organization_id');
            $table->boolean('is_active');
            $table->string('project_access_mode');
        });
        $schema->create('project_user', static function (Blueprint $table): void {
            $table->bigInteger('user_id');
            $table->bigInteger('project_id');
            $table->boolean('is_active');
        });
        $schema->create('authorization_contexts', static function (Blueprint $table): void {
            $table->bigInteger('id')->primary();
            $table->string('type');
            $table->bigInteger('resource_id')->nullable();
            $table->bigInteger('parent_context_id')->nullable();
        });
        $schema->create('user_role_assignments', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('user_id');
            $table->string('role_slug');
            $table->bigInteger('context_id');
            $table->boolean('is_active');
            $table->timestamp('expires_at')->nullable();
        });
        $this->database->table('authorization_contexts')->insert([
            ['id' => 1, 'type' => 'system', 'resource_id' => null, 'parent_context_id' => null],
            ['id' => 10, 'type' => 'organization', 'resource_id' => 7, 'parent_context_id' => 1],
            ['id' => 11, 'type' => 'project', 'resource_id' => 52, 'parent_context_id' => 10],
            ['id' => 12, 'type' => 'project', 'resource_id' => 53, 'parent_context_id' => 10],
            ['id' => 20, 'type' => 'organization', 'resource_id' => 8, 'parent_context_id' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        Model::clearBootedModels();
        parent::tearDown();
    }

    public function test_role_recipients_require_active_membership_exact_context_project_access_and_document_permissions(): void
    {
        foreach (range(1, 13) as $id) {
            $this->database->table('users')->insert([
                'id' => $id,
                'current_organization_id' => $id === 2 ? 8 : 7,
                'deleted_at' => $id === 10 ? now() : null,
            ]);
            $this->database->table('organization_user')->insert([
                'user_id' => $id,
                'organization_id' => 7,
                'is_active' => $id !== 8,
                'project_access_mode' => in_array($id, [9, 12], true) ? 'assigned' : UserProjectAccessMode::ALL_PROJECTS->value,
            ]);
            $this->database->table('user_role_assignments')->insert([
                'user_id' => $id,
                'role_slug' => 'document_reviewer',
                'context_id' => match ($id) {
                    3 => 11, 4 => 20, 5 => 12, default => 10
                },
                'is_active' => $id !== 6,
                'expires_at' => $id === 7 ? now()->subDay() : null,
            ]);
        }
        $this->database->table('user_role_assignments')->insert([
            'user_id' => 1, 'role_slug' => 'document_reviewer', 'context_id' => 11, 'is_active' => true,
        ]);
        $this->database->table('project_user')->insert(['user_id' => 12, 'project_id' => 52, 'is_active' => true]);

        $recipients = $this->resolver()->recipientsFor($this->step(), $this->document())->all();

        self::assertSame([1, 2, 3, 12], array_map(static fn (User $user): int => (int) $user->id, $recipients));
        self::assertSame(7, (int) $recipients[1]->current_organization_id);
        self::assertSame(8, (int) $this->database->table('users')->where('id', 2)->value('current_organization_id'));
    }

    public function test_inactive_or_foreign_step_has_no_recipients(): void
    {
        $step = $this->step();
        $step->status = 'pending';
        self::assertSame([], $this->resolver()->recipientsFor($step, $this->document())->all());
        $step->status = 'active';
        $step->organization_id = 8;
        self::assertSame([], $this->resolver()->recipientsFor($step, $this->document())->all());
    }

    public function test_explicit_user_assignment_also_requires_membership_and_document_access(): void
    {
        $this->database->table('users')->insert(['id' => 11, 'current_organization_id' => 7]);
        $step = $this->step();
        $step->actor_type = 'user';
        $step->actor_reference = '11';
        self::assertSame([], $this->resolver()->recipientsFor($step, $this->document())->all());
        $this->database->table('organization_user')->insert([
            'user_id' => 11, 'organization_id' => 7, 'is_active' => true, 'project_access_mode' => UserProjectAccessMode::ALL_PROJECTS->value,
        ]);
        self::assertSame([], $this->resolver()->recipientsFor($step, $this->document())->all());
    }

    private function resolver(): LegalWorkflowActorResolver
    {
        $access = $this->createStub(LegalDocumentAuthorizer::class);
        $access->method('authorize')->willReturnCallback(static function (User $user, LegalArchiveDocument $document, string $ability): void {
            if (((int) $user->id === 11 && $ability === 'approve')
                || ((int) $user->id === 13 && $ability === 'view')
                || (int) $user->current_organization_id !== (int) $document->organization_id) {
                throw new AuthorizationException;
            }
        });

        return new LegalWorkflowActorResolver(documentAuthorizer: $access);
    }

    private function document(): LegalArchiveDocument
    {
        return (new LegalArchiveDocument)->forceFill(['id' => 42, 'organization_id' => 7, 'primary_project_id' => 52]);
    }

    private function step(): LegalWorkflowStep
    {
        return (new LegalWorkflowStep)->forceFill([
            'id' => 19, 'organization_id' => 7, 'status' => 'active', 'actor_type' => 'role', 'actor_reference' => 'document_reviewer',
        ]);
    }
}
