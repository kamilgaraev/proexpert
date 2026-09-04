<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\User;
use App\Services\User\OrganizationTeamAccess;
use App\Services\User\UserService;
use App\Services\User\AdminUserRolePolicy;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Helpers\AdminPanelAccessHelper;
use App\Services\Logging\LoggingService;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\V1\Landing\OrganizationUserController;
use App\Http\Requests\Api\V1\Landing\User\OrganizationTeamAccessRequest;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Factory as ValidationFactory;
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

final class OrganizationTeamAccessTest extends TestCase
{
    private Capsule $database;
    private mixed $previousFacadeApplication;
    private Container $previousContainer;
    private User $actor;

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
        $this->database->schema()->create('users', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active');
            $table->softDeletes();
        });
        $this->database->schema()->create('organization_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');
            $table->boolean('is_active');
            $table->boolean('is_owner')->default(false);
            $table->string('project_access_mode')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'organization_id']);
        });
        $this->database->table('users')->insert([
            ['id' => 1, 'is_active' => true],
            ['id' => 2, 'is_active' => true],
            ['id' => 3, 'is_active' => true],
        ]);
        $this->database->table('organization_user')->insert([
            ['user_id' => 1, 'organization_id' => 7, 'is_active' => true],
            ['user_id' => 2, 'organization_id' => 7, 'is_active' => true],
            ['user_id' => 2, 'organization_id' => 9, 'is_active' => true],
            ['user_id' => 3, 'organization_id' => 9, 'is_active' => true],
        ]);
        $this->actor = User::query()->findOrFail(1);
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

    public function test_access_changes_only_the_selected_membership_and_repeated_requests_are_idempotent(): void
    {
        $service = $this->service();
        $service->setActive($this->actor, 7, 2, false);
        self::assertFalse($this->membershipActive(2, 7));
        self::assertTrue($this->membershipActive(2, 9));
        self::assertTrue((bool) $this->database->table('users')->where('id', 2)->value('is_active'));
        $updatedAt = $this->database->table('organization_user')->where('user_id', 2)->where('organization_id', 7)->value('updated_at');

        $service->setActive($this->actor, 7, 2, false);
        self::assertSame($updatedAt, $this->database->table('organization_user')->where('user_id', 2)->where('organization_id', 7)->value('updated_at'));
        $service->setActive($this->actor, 7, 2, true);
        self::assertTrue($this->membershipActive(2, 7));
        self::assertTrue($this->membershipActive(2, 9));
    }

    public function test_self_and_company_owner_cannot_be_disabled(): void
    {
        $this->database->table('organization_user')->where('user_id', 2)->where('organization_id', 7)->update(['is_owner' => true]);
        foreach ([1, 2] as $memberId) {
            try {
                $this->service()->setActive($this->actor, 7, $memberId, false);
                self::fail('Защищённый доступ не должен отключаться.');
            } catch (BusinessLogicException $exception) {
                self::assertSame(422, $exception->getCode());
                self::assertSame('Нельзя отключить собственный доступ или доступ владельца компании.', $exception->getMessage());
                self::assertTrue($this->membershipActive($memberId, 7));
            }
        }
    }

    public function test_other_organization_members_and_deleted_accounts_are_not_found(): void
    {
        $this->database->table('users')->where('id', 2)->update(['deleted_at' => '2025-01-01 00:00:00']);
        foreach ([2, 3, 99] as $memberId) {
            try {
                $this->service()->setActive($this->actor, 7, $memberId, false);
                self::fail('Сотрудник вне доступной команды не должен изменяться.');
            } catch (BusinessLogicException $exception) {
                self::assertSame(404, $exception->getCode());
                self::assertSame('Сотрудник не найден в этой компании.', $exception->getMessage());
            }
        }
        self::assertTrue($this->membershipActive(3, 9));
        self::assertTrue($this->membershipActive(2, 7));
    }

    public function test_company_access_cannot_reactivate_a_globally_disabled_account(): void
    {
        $this->database->table('users')->where('id', 2)->update(['is_active' => false]);
        $this->database->table('organization_user')->where('user_id', 2)->where('organization_id', 7)->update(['is_active' => false]);
        try {
            $this->service()->setActive($this->actor, 7, 2, true);
            self::fail('Отключённая учётная запись не должна восстанавливаться через компанию.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertFalse($this->membershipActive(2, 7));
            self::assertFalse((bool) $this->database->table('users')->where('id', 2)->value('is_active'));
        }
    }

    public function test_permission_and_active_actor_membership_are_required(): void
    {
        foreach ([false, true] as $allowed) {
            if ($allowed) {
                $this->database->table('organization_user')->where('user_id', 1)->update(['is_active' => false]);
            }
            try {
                $this->service($allowed)->setActive($this->actor, 7, 2, false);
                self::fail('Запрос без действующего доступа должен быть отклонён.');
            } catch (AuthorizationException) {
                self::assertTrue($this->membershipActive(2, 7));
            }
        }
    }

    public function test_permission_is_checked_for_the_exact_organization(): void
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects(self::once())->method('can')
            ->with($this->actor, 'users.manage', ['organization_id' => 9])->willReturn(true);
        $this->expectException(AuthorizationException::class);
        (new OrganizationTeamAccess($authorization))->setActive($this->actor, 9, 3, false);
    }

    public function test_controller_returns_access_state_and_domain_errors_without_changing_other_companies(): void
    {
        $controller = new OrganizationUserController;
        $response = $controller->setAccess($this->accessRequest(['is_active' => false]), '2', $this->service());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 2, 'is_active' => false], $response->getData(true)['data']);
        self::assertTrue($response->getData(true)['success']);
        self::assertTrue($this->membershipActive(2, 9));

        $protected = $controller->setAccess($this->accessRequest(['is_active' => false]), '1', $this->service());
        self::assertSame(422, $protected->getStatusCode());
        self::assertFalse($protected->getData(true)['success']);
        self::assertSame('Нельзя отключить собственный доступ или доступ владельца компании.', $protected->getData(true)['message']);
        $missing = $controller->setAccess($this->accessRequest(['is_active' => false]), '3', $this->service());
        self::assertSame(404, $missing->getStatusCode());

        $this->expectException(AuthorizationException::class);
        $controller->setAccess($this->accessRequest(['is_active' => false]), '2', $this->service(false));
    }

    public function test_controller_hides_unexpected_database_failures(): void
    {
        $request = $this->accessRequest(['is_active' => false]);
        $this->database->schema()->drop('organization_user');
        $response = (new OrganizationUserController)->setAccess($request, '2', $this->service());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Не удалось изменить доступ сотрудника. Попробуйте ещё раз.', $response->getData(true)['message']);
        self::assertStringNotContainsString('SQL', $response->getContent());
    }

    public function test_access_request_requires_explicit_boolean_and_permission_in_current_company(): void
    {
        $request = $this->accessRequest(['is_active' => false]);
        $validator = new ValidationFactory(Container::getInstance()->make('translator'));
        foreach ([[], ['is_active' => null], ['is_active' => 'false'], ['is_active' => 2], ['is_active' => []]] as $input) {
            self::assertTrue($validator->make($input, $request->rules())->fails());
        }
        foreach ([true, false, 0, 1] as $value) {
            self::assertTrue($validator->make(['is_active' => $value], $request->rules())->passes());
        }
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects(self::once())->method('can')
            ->with($this->actor, 'users.manage', ['organization_id' => 7])->willReturn(false);
        Container::getInstance()->instance(AuthorizationService::class, $authorization);
        self::assertFalse($request->authorize());
        $request->attributes->remove('current_organization_id');
        self::assertFalse($request->authorize());
    }

    public function test_grant_owner_locks_the_membership_inside_the_transaction_and_prevents_deactivation(): void
    {
        $lockingReads = [];
        $this->database->getConnection()->listen(function ($query) use (&$lockingReads): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"organization_user"')) {
                $lockingReads[] = [$query->connection->transactionLevel(), str_contains($query->sql, 'for update')];
            }
        });
        $this->ownerService(true)->grantOrganizationOwner(2, $this->ownerRequest());
        self::assertSame([[1, true]], $lockingReads);
        self::assertTrue((bool) $this->database->table('organization_user')->where('organization_id', 7)->where('user_id', 2)->value('is_owner'));

        try {
            $this->service()->setActive($this->actor, 7, 2, false);
            self::fail('Назначенного владельца нельзя отключить.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertTrue($this->membershipActive(2, 7));
        }
    }

    public function test_deactivated_member_cannot_be_granted_ownership(): void
    {
        $this->service()->setActive($this->actor, 7, 2, false);
        try {
            $this->ownerService(false)->grantOrganizationOwner(2, $this->ownerRequest());
            self::fail('Отключённого сотрудника нельзя назначить владельцем.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertFalse((bool) $this->database->table('organization_user')->where('organization_id', 7)->where('user_id', 2)->value('is_owner'));
            self::assertFalse($this->membershipActive(2, 7));
        }
    }

    private function ownerRequest(): Request
    {
        $request = Request::create('/user-management/organization-users/2/grant-owner', 'POST');
        $request->setUserResolver(fn () => $this->actor);
        $request->attributes->set('current_organization_id', 7);

        return $request;
    }

    private function ownerService(bool $assignsRole): UserService
    {
        $member = User::query()->findOrFail(2);
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('find')->with(2)->willReturn($member);
        $repository->expects($assignsRole ? self::once() : self::never())->method('assignRoleToUser');
        $service = $this->getMockBuilder(UserService::class)
            ->setConstructorArgs([
                $repository,
                $this->createStub(AuthorizationService::class),
                $this->createStub(AdminPanelAccessHelper::class),
                $this->createStub(LoggingService::class),
                new AdminUserRolePolicy(
                    $this->createStub(\App\Domain\Authorization\Services\RoleScanner::class),
                    new \App\Domain\Authorization\Services\RolePayloadFormatter($this->createStub(\App\Services\PermissionTranslationService::class)),
                    $this->createStub(AuthorizationService::class),
                ),
            ])
            ->onlyMethods(['ensureUserIsOwner', 'findOrganizationUserById'])
            ->getMock();
        $service->method('findOrganizationUserById')->willReturn($member);

        return $service;
    }

    private function accessRequest(array $input): OrganizationTeamAccessRequest
    {
        $container = Container::getInstance();
        $responses = $this->createStub(ResponseFactory::class);
        $responses->method('json')->willReturnCallback(fn ($data, $status = 200) => new JsonResponse($data, $status));
        $container->instance(ResponseFactory::class, $responses);
        $request = OrganizationTeamAccessRequest::create('/user-management/organization-users/2/access', 'PATCH', $input);
        $request->setContainer($container);
        $request->setUserResolver(fn () => $this->actor);
        $request->attributes->set('current_organization_id', 7);
        $request->setValidator((new ValidationFactory($container->make('translator')))->make($input, $request->rules()));

        return $request;
    }

    private function service(bool $allowed = true): OrganizationTeamAccess
    {
        $authorization = $this->createStub(AuthorizationService::class);
        $authorization->method('can')->willReturn($allowed);

        return new OrganizationTeamAccess($authorization);
    }

    private function membershipActive(int $memberId, int $organizationId): bool
    {
        return (bool) $this->database->table('organization_user')->where('user_id', $memberId)
            ->where('organization_id', $organizationId)->value('is_active');
    }
}
