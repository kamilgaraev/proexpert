<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RolePayloadFormatter;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\User;
use App\Services\PermissionTranslationService;
use App\Services\User\OrganizationTeamDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\IsolatedPostgresTestDatabase;

final class OrganizationTeamDirectoryTest extends TestCase
{
    private Capsule $database;
    private mixed $previousFacadeApplication;
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).'/lang'), 'ru'));
        $container->instance('log', new NullLogger);
        $this->database = new Capsule($container);
        $this->database->addConnection(IsolatedPostgresTestDatabase::configuration());
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher($container));
        $this->database->bootEloquent();
        $container->instance('db', $this->database->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Model::clearBootedModels();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_members_and_roles_are_scoped_to_the_requested_organization(): void
    {
        $this->member(1, 'Анна', 'anna@example.test', 7);
        $this->member(2, 'Борис', 'boris@example.test', 7);
        $this->member(3, 'Вера', 'vera@example.test', 9);
        $this->database->table('organization_user')->insert($this->membership(1, 9));
        $this->assignment(1, 'warehouse', 7);
        $this->assignment(1, 'secret', 9);
        $this->assignment(2, 'expired', 7, true, '2000-01-01 00:00:00');
        $this->assignment(2, 'disabled', 7, false);
        $this->database->table('organization_custom_roles')->insert([
            ['id' => 1, 'organization_id' => 7, 'slug' => 'warehouse', 'name' => 'Снабженец'],
            ['id' => 2, 'organization_id' => 9, 'slug' => 'warehouse', 'name' => 'Чужое название'],
            ['id' => 3, 'organization_id' => 9, 'slug' => 'secret', 'name' => 'Чужая роль'],
        ]);

        $result = $this->directory()->paginate(new User, 7);

        self::assertSame(2, $result->total());
        self::assertSame([1, 2], array_column($result->items(), 'id'));
        self::assertSame([['id' => 1, 'slug' => 'warehouse', 'name' => 'Снабженец', 'type' => 'custom']], $result->items()[0]['roles']);
        self::assertSame([], $result->items()[1]['roles']);
        self::assertSame(['id', 'name', 'email', 'email_verified_at', 'is_active', 'roles', 'created_at'], array_keys($result->items()[0]));
    }

    public function test_search_is_case_insensitive_and_wildcards_are_literal(): void
    {
        $this->member(1, 'Анна Сметчик', 'anna@example.test', 7);
        $this->member(2, 'Проект 100%_готов', 'exact@example.test', 7);
        $this->member(3, 'Проект 1000 готов', 'other@example.test', 7);
        $directory = $this->directory();

        self::assertSame([1], array_column($directory->paginate(new User, 7, 'сМеТчИк')->items(), 'id'));
        self::assertSame([1], array_column($directory->paginate(new User, 7, 'ANNA@')->items(), 'id'));
        self::assertSame([2], array_column($directory->paginate(new User, 7, '%_')->items(), 'id'));
        self::assertSame(0, $directory->paginate(new User, 7, 'не существует')->total());
    }

    public function test_pagination_is_stable_and_membership_activity_is_respected(): void
    {
        for ($id = 1; $id <= 8; $id++) {
            $this->member($id, 'Сотрудник', 'member'.$id.'@example.test', 7);
        }
        $this->database->table('organization_user')->where('user_id', 3)->update(['is_active' => false]);
        $this->database->table('users')->where('id', 4)->update(['is_active' => false]);
        $this->database->table('users')->where('id', 8)->update(['deleted_at' => '2020-01-01 00:00:00']);

        $result = $this->directory()->paginate(new User, 7, '', 2, 2);

        self::assertSame(7, $result->total());
        self::assertSame(4, $result->lastPage());
        self::assertSame([3, 4], array_column($result->items(), 'id'));
        self::assertSame([false, false], array_column($result->items(), 'is_active'));
    }

    public function test_query_count_does_not_grow_with_the_number_of_employees(): void
    {
        for ($id = 1; $id <= 30; $id++) {
            $this->member($id, sprintf('Сотрудник %02d', $id), 'member'.$id.'@example.test', 7);
            $this->assignment($id, 'shared', 7);
        }
        $this->database->table('organization_custom_roles')->insert(['id' => 1, 'organization_id' => 7, 'slug' => 'shared', 'name' => 'Инженер']);
        $connection = $this->database->getConnection();
        $connection->enableQueryLog();
        $directory = $this->directory();
        $directory->paginate(new User, 7, '', 1, 1);
        $singleCount = count($connection->getQueryLog());
        $connection->flushQueryLog();

        $result = $directory->paginate(new User, 7, '', 1, 30);

        self::assertCount(30, $result->items());
        self::assertSame($singleCount, count($connection->getQueryLog()));
        self::assertLessThanOrEqual(5, $singleCount);
    }

    public function test_missing_custom_role_is_not_replaced_with_a_system_role_of_the_same_name(): void
    {
        $this->member(1, 'Анна', 'anna@example.test', 7);
        $this->assignment(1, 'owner', 7);
        $scanner = $this->createMock(RoleScanner::class);
        $scanner->expects(self::never())->method('getRole');
        $directory = $this->directory(true, $scanner);

        $result = $directory->paginate(new User, 7);

        self::assertSame('Роль недоступна', $result->items()[0]['roles'][0]['name']);
        self::assertNull($result->items()[0]['roles'][0]['id']);
    }

    public function test_unauthorized_request_does_not_query_members(): void
    {
        $connection = $this->database->getConnection();
        $connection->enableQueryLog();
        try {
            $this->directory(false)->paginate(new User, 7);
            self::fail('Запрос без права управления сотрудниками должен быть отклонён.');
        } catch (AuthorizationException) {
            self::assertSame([], $connection->getQueryLog());
        }
    }

    public function test_system_roles_from_different_interfaces_share_the_same_team(): void
    {
        $this->member(1, 'Анна', 'anna@example.test', 7);
        $this->member(2, 'Борис', 'boris@example.test', 7);
        $this->database->table('user_role_assignments')->insert([
            ['user_id' => 1, 'role_slug' => 'accountant', 'role_type' => 'system', 'context_id' => 7, 'is_active' => true],
            ['user_id' => 2, 'role_slug' => 'foreman', 'role_type' => 'system', 'context_id' => 7, 'is_active' => true],
        ]);
        $scanner = $this->createStub(RoleScanner::class);
        $scanner->method('getRole')->willReturnMap([
            ['accountant', ['name' => 'Бухгалтер', 'interface_access' => ['lk']]],
            ['foreman', ['name' => 'Прораб', 'interface_access' => ['mobile']]],
        ]);

        $result = $this->directory(true, $scanner)->paginate(new User, 7);

        self::assertSame(2, $result->total());
        self::assertSame('Бухгалтер', $result->items()[0]['roles'][0]['name']);
        self::assertSame('Прораб', $result->items()[1]['roles'][0]['name']);
        self::assertSame('system', $result->items()[1]['roles'][0]['type']);
    }

    public function test_authorization_receives_the_requested_organization_and_missing_context_is_rejected(): void
    {
        $actor = new User;
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects(self::once())->method('can')
            ->with($actor, 'users.manage', ['organization_id' => 7])->willReturn(true);
        $directory = new OrganizationTeamDirectory($authorization, $this->createStub(RoleScanner::class), new RolePayloadFormatter($this->createStub(PermissionTranslationService::class)));
        self::assertSame(0, $directory->paginate($actor, 7)->total());

        $this->expectException(AuthorizationException::class);
        $directory->paginate($actor, 0);
    }

    private function directory(bool $allowed = true, ?RoleScanner $scanner = null): OrganizationTeamDirectory
    {
        $authorization = $this->createStub(AuthorizationService::class);
        $authorization->method('can')->willReturn($allowed);

        return new OrganizationTeamDirectory($authorization, $scanner ?? $this->createStub(RoleScanner::class), new RolePayloadFormatter($this->createStub(PermissionTranslationService::class)));
    }

    private function member(int $id, string $name, string $email, int $organizationId): void
    {
        $this->database->table('users')->insert(['id' => $id, 'name' => $name, 'email' => $email, 'is_active' => true, 'created_at' => '2025-01-01 00:00:00']);
        $this->database->table('organization_user')->insert($this->membership($id, $organizationId));
    }

    private function membership(int $userId, int $organizationId): array
    {
        return ['user_id' => $userId, 'organization_id' => $organizationId, 'is_active' => true, 'is_owner' => false, 'settings' => '{}', 'project_access_mode' => 'all'];
    }

    private function assignment(int $userId, string $slug, int $contextId, bool $active = true, ?string $expiresAt = null): void
    {
        $this->database->table('user_role_assignments')->insert(['user_id' => $userId, 'role_slug' => $slug, 'role_type' => 'custom', 'context_id' => $contextId, 'is_active' => $active, 'expires_at' => $expiresAt]);
    }

    private function createSchema(): void
    {
        $schema = $this->database->schema();
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->boolean('is_active');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->softDeletes();
        });
        $schema->create('organization_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');
            $table->boolean('is_owner');
            $table->boolean('is_active');
            $table->jsonb('settings');
            $table->string('project_access_mode');
            $table->timestamps();
        });
        $schema->create('authorization_contexts', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->unsignedBigInteger('resource_id')->nullable();
        });
        $schema->create('user_role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('role_slug');
            $table->string('role_type');
            $table->unsignedBigInteger('context_id');
            $table->boolean('is_active');
            $table->timestamp('expires_at')->nullable();
        });
        $schema->create('organization_custom_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('slug');
            $table->string('name');
        });
        $this->database->table('organizations')->insert([['id' => 7], ['id' => 9]]);
        $this->database->table('authorization_contexts')->insert([
            ['id' => 7, 'type' => 'organization', 'resource_id' => 7],
            ['id' => 9, 'type' => 'organization', 'resource_id' => 9],
        ]);
    }
}
