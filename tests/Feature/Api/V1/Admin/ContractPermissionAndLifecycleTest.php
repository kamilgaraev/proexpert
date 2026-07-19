<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\BusinessModules\Features\ContractManagement\ContractManagementModule;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStateEventTypeEnum;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Api\V1\Admin\ContractController;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\StoreContractPerformanceActRequest;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\UpdateContractPerformanceActRequest;
use App\Http\Requests\Api\V1\Admin\Contract\StoreContractRequest;
use App\Http\Requests\Api\V1\Admin\Contract\UpdateContractRequest;
use App\Http\Responses\AdminResponse;
use App\Models\Contract;
use App\Models\User;
use App\Repositories\Interfaces\ContractStateEventRepositoryInterface;
use App\Services\Contract\ContractLifecycleService;
use App\Services\Contract\ContractService;
use App\Services\Contract\ContractStateCalculatorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;

final class ContractPermissionAndLifecycleTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_contract_routes_require_canonical_permissions(): void
    {
        $this->assertRoutePermission('GET', 'api/v1/admin/contracts', 'contracts.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/contracts', 'contracts.create');
        $this->assertRoutePermission('GET', 'api/v1/admin/contracts/{contract}', 'contracts.view');
        $this->assertRoutePermission('PUT', 'api/v1/admin/contracts/{contract}', 'contracts.edit');
        $this->assertRoutePermission('DELETE', 'api/v1/admin/contracts/{contract}', 'contracts.delete');
        $this->assertRoutePermission('POST', 'api/v1/admin/contracts/{contract}/activate', 'contracts.edit');
        $this->assertRoutePermission('POST', 'api/v1/admin/contracts/{contract}/archive', 'contracts.archive');

        $this->assertRoutePermission('GET', 'api/v1/admin/projects/{project}/contracts', 'contracts.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/projects/{project}/contracts', 'contracts.create');
        $this->assertRoutePermission('GET', 'api/v1/admin/projects/{project}/contracts/{contract}', 'contracts.view');
        $this->assertRoutePermission('PUT', 'api/v1/admin/projects/{project}/contracts/{contract}', 'contracts.edit');
        $this->assertRoutePermission('DELETE', 'api/v1/admin/projects/{project}/contracts/{contract}', 'contracts.delete');
        $this->assertRoutePermission('POST', 'api/v1/admin/projects/{project}/contracts/{contract}/archive', 'contracts.archive');

        $this->assertRoutePermission('GET', 'api/v1/admin/contracts/{contract}/performance-acts', 'contracts.performance_acts.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/contracts/{contract}/performance-acts', 'contracts.performance_acts.create');
        $this->assertRoutePermission('GET', 'api/v1/admin/performance-acts/{performance_act}', 'contracts.performance_acts.view');
        $this->assertRoutePermission('PUT', 'api/v1/admin/performance-acts/{performance_act}', 'contracts.performance_acts.edit');
        $this->assertRoutePermission('DELETE', 'api/v1/admin/performance-acts/{performance_act}', 'contracts.performance_acts.delete');
        $this->assertRoutePermission('GET', 'api/v1/admin/contracts/{contract}/performance-acts/{performance_act}/export/pdf', 'contracts.performance_acts.export');

        $this->assertRoutePermission('GET', 'api/v1/admin/projects/{project}/contracts/{contract}/performance-acts', 'contracts.performance_acts.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/projects/{project}/contracts/{contract}/performance-acts', 'contracts.performance_acts.create');
        $this->assertRoutePermission('GET', 'api/v1/admin/projects/{project}/contracts/{contract}/performance-acts/{performance_act}', 'contracts.performance_acts.view');
        $this->assertRoutePermission('PUT', 'api/v1/admin/projects/{project}/contracts/{contract}/performance-acts/{performance_act}', 'contracts.performance_acts.edit');
        $this->assertRoutePermission('DELETE', 'api/v1/admin/projects/{project}/contracts/{contract}/performance-acts/{performance_act}', 'contracts.performance_acts.delete');
    }

    public function test_user_without_create_permission_receives_forbidden_response(): void
    {
        $user = new User;
        $user->id = 15;
        $request = Request::create('/api/v1/admin/contracts', 'POST');
        $request->setUserResolver(static fn (): User => $user);
        $middleware = new AuthorizeMiddleware($this->mockAuthorization(static fn (): bool => false));

        $response = $middleware->handle($request, static fn () => null, 'contracts.create');

        self::assertSame(403, $response->status());
    }

    public function test_lifecycle_service_applies_the_complete_transition_matrix(): void
    {
        $actor = new User;
        $actor->id = 42;
        $service = app(ContractLifecycleService::class);

        foreach ([
            ['draft', 'activate', 'active'],
            ['draft', 'archive', 'archived'],
            ['active', 'suspend', 'on_hold'],
            ['active', 'complete', 'completed'],
            ['active', 'terminate', 'terminated'],
            ['on_hold', 'resume', 'active'],
            ['on_hold', 'terminate', 'terminated'],
            ['completed', 'archive', 'archived'],
            ['terminated', 'archive', 'archived'],
        ] as [$from, $action, $to]) {
            $contract = $this->contractWithStatus($from);

            $transitioned = $service->transition($contract, $action, $actor, 'Основание перехода');

            self::assertSame($to, $transitioned->status->value);
        }
    }

    public function test_invalid_transition_is_reported_as_conflict(): void
    {
        $actor = new User;
        $actor->id = 42;
        $contract = $this->contractWithStatus('draft', false);

        try {
            app(ContractLifecycleService::class)->transition($contract, 'complete', $actor, null);
            self::fail('Недопустимый переход должен завершаться конфликтом.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }

        self::assertSame('draft', $contract->status->value);
    }

    public function test_archive_action_archives_draft_and_rejects_repeated_archive(): void
    {
        $actor = new User;
        $actor->id = 42;
        $contract = $this->contractWithStatus('draft');
        $service = app(ContractLifecycleService::class);

        $service->transition($contract, 'archive', $actor, 'Документ перенесен в юридический архив');
        self::assertSame('archived', $contract->status->value);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(409);
        $service->transition($contract, 'archive', $actor, null);
    }

    public function test_legacy_delete_is_safe_and_returns_conflict(): void
    {
        $controller = new ContractController(
            \Mockery::mock(ContractService::class),
            \Mockery::mock(OfficialFormsExportService::class),
            app(ContractLifecycleService::class)
        );

        $response = $controller->destroy(123, Request::create('/api/v1/admin/contracts/123', 'DELETE'));

        self::assertSame(409, $response->status());
    }

    public function test_project_transition_uses_contract_route_parameter_and_persists_event(): void
    {
        $this->createContractTables();
        $this->withoutMiddleware();

        $wrongContract = $this->persistContract(11, 99);
        $targetContract = $this->persistContract(22, 11);
        $user = $this->user(7);

        $service = \Mockery::mock(ContractService::class);
        $service->shouldReceive('getContractById')
            ->once()
            ->with(22, 7, 11)
            ->andReturn($targetContract);
        $this->app->instance(ContractService::class, $service);
        $response = $this->actingAs($user)->postJson('/api/v1/admin/projects/11/contracts/22/activate');

        $response->assertOk();
        self::assertSame('active', Contract::query()->findOrFail(22)->status->value);
        self::assertSame('draft', Contract::query()->findOrFail($wrongContract->id)->status->value);
        self::assertDatabaseHas('contract_state_events', [
            'contract_id' => 22,
            'event_type' => 'status_transition',
            'created_by_user_id' => $user->id,
        ]);
    }

    public function test_project_create_rejects_foreign_project_targets_and_accepts_route_project(): void
    {
        $this->createContractTables();
        $this->app->instance(AuthorizationService::class, $this->mockAuthorization(static fn (): bool => true));

        Route::post('/__review/projects/{project}/scoped-contracts', static function (StoreContractRequest $request) {
            $dto = $request->toDto();

            return AdminResponse::success([
                'project_id' => $dto->project_id,
                'project_ids' => $dto->project_ids,
            ]);
        });

        $payload = [
            'project_id' => 17,
            'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR->value,
            'number' => 'PROJECT-SCOPE-CREATE',
            'date' => '2026-07-19',
            'is_self_execution' => true,
        ];

        $this->actingAs($this->user(7))
            ->postJson('/__review/projects/11/scoped-contracts', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_id');

        $this->actingAs($this->user(7))
            ->postJson('/__review/projects/11/scoped-contracts', [...$payload, 'project_id' => 11])
            ->assertOk()
            ->assertJsonPath('data.project_id', 11);
    }

    public function test_project_create_rejects_project_ids_outside_route_project(): void
    {
        $this->createContractTables();
        $this->app->instance(AuthorizationService::class, $this->mockAuthorization(static fn (): bool => true));

        Route::post('/__review/projects/{project}/scoped-multi-contracts', static fn (StoreContractRequest $request) => AdminResponse::success());

        $this->actingAs($this->user(7))
            ->postJson('/__review/projects/11/scoped-multi-contracts', [
                'project_id' => 11,
                'project_ids' => [11, 17],
                'is_multi_project' => true,
                'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR->value,
                'number' => 'PROJECT-SCOPE-MULTI-CREATE',
                'date' => '2026-07-19',
                'is_self_execution' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_ids');
    }

    public function test_project_update_rejects_foreign_project_targets_and_accepts_current_route_project(): void
    {
        $this->createContractTables();
        $contract = $this->persistContract(71, 11);
        $this->app->instance(AuthorizationService::class, $this->mockAuthorization(static fn (): bool => true));

        Route::put('/__review/projects/{project}/scoped-contracts/{contract}', static function (UpdateContractRequest $request) {
            $dto = $request->toDto();

            return AdminResponse::success([
                'project_id' => $dto->project_id,
                'project_ids' => $dto->project_ids,
            ]);
        });

        $this->actingAs($this->user(7))
            ->putJson("/__review/projects/11/scoped-contracts/{$contract->id}", ['project_id' => 17])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_id');

        $this->actingAs($this->user(7))
            ->putJson("/__review/projects/11/scoped-contracts/{$contract->id}", ['project_id' => 11])
            ->assertOk()
            ->assertJsonPath('data.project_id', 11);
    }

    public function test_project_update_rejects_project_ids_outside_route_project(): void
    {
        $this->createContractTables();
        $contract = $this->persistContract(72, 11);
        $this->app->instance(AuthorizationService::class, $this->mockAuthorization(static fn (): bool => true));

        Route::put('/__review/projects/{project}/scoped-multi-contracts/{contract}', static fn (UpdateContractRequest $request) => AdminResponse::success());

        $this->actingAs($this->user(7))
            ->putJson("/__review/projects/11/scoped-multi-contracts/{$contract->id}", [
                'project_id' => 11,
                'project_ids' => [11, 17],
                'is_multi_project' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_ids');
    }

    public function test_project_update_rejects_contract_outside_route_project(): void
    {
        $this->createContractTables();
        $contract = $this->persistContract(73, 17);
        $this->app->instance(AuthorizationService::class, $this->mockAuthorization(static fn (): bool => true));

        Route::put('/__review/projects/{project}/membership-contracts/{contract}', static fn (UpdateContractRequest $request) => AdminResponse::success());

        $this->actingAs($this->user(7))
            ->putJson("/__review/projects/11/membership-contracts/{$contract->id}", ['project_id' => 11])
            ->assertForbidden();
    }

    public function test_project_update_cannot_clear_route_project(): void
    {
        $this->createContractTables();
        $contract = $this->persistContract(74, 11);
        $this->app->instance(AuthorizationService::class, $this->mockAuthorization(static fn (): bool => true));

        Route::put('/__review/projects/{project}/required-project-contracts/{contract}', static fn (UpdateContractRequest $request) => AdminResponse::success());

        $this->actingAs($this->user(7))
            ->putJson("/__review/projects/11/required-project-contracts/{$contract->id}", ['project_id' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_id');
    }

    public function test_lifecycle_transition_preserves_financial_projection_and_invalidates_event_cache(): void
    {
        $this->createContractTables();
        $contract = $this->persistContract(81, 11);
        $contract->forceFill(['status' => 'active', 'total_amount' => 125.50])->save();
        $actor = $this->user(7);

        $repository = app(ContractStateEventRepositoryInterface::class);
        $repository->createEvent([
            'contract_id' => $contract->id,
            'event_type' => ContractStateEventTypeEnum::CREATED,
            'triggered_by_type' => Contract::class,
            'triggered_by_id' => $contract->id,
            'specification_id' => 777,
            'amount_delta' => 125.50,
            'effective_from' => '2026-07-19',
            'metadata' => [],
            'created_by_user_id' => $actor->id,
        ]);

        $calculator = app(ContractStateCalculatorService::class);
        $before = $calculator->recalculateContractState($contract);
        self::assertCount(1, $repository->findActiveEvents($contract->id));

        app(ContractLifecycleService::class)->transition($contract, 'suspend', $actor, 'Проверка проекции');

        $events = $repository->findActiveEvents($contract->id);
        self::assertCount(2, $events, 'Repository cache должен быть сброшен после lifecycle-события.');
        $transitionEvent = $events->last();
        self::assertSame('status_transition', $transitionEvent->event_type->value);
        self::assertSame([
            'action' => 'suspend',
            'from_status' => 'active',
            'to_status' => 'on_hold',
            'reason' => 'Проверка проекции',
            'actor_id' => $actor->id,
        ], $transitionEvent->metadata);

        $after = $calculator->recalculateContractState($contract->refresh());
        self::assertSame('125.50', $before->current_total_amount);
        self::assertSame($before->current_total_amount, $after->current_total_amount);
        self::assertSame(777, $before->active_specification_id);
        self::assertSame($before->active_specification_id, $after->active_specification_id);
    }

    public function test_http_invalid_transition_and_legacy_delete_return_conflict(): void
    {
        $this->createContractTables();
        $this->withoutMiddleware();
        $contract = $this->persistContract(31, 17);
        $user = $this->user(7);

        $service = \Mockery::mock(ContractService::class);
        $service->shouldReceive('getContractById')->once()->with(31, 7, 17)->andReturn($contract);
        $this->app->instance(ContractService::class, $service);

        $this->actingAs($user)
            ->postJson('/api/v1/admin/projects/17/contracts/31/complete')
            ->assertStatus(409);
        $this->actingAs($user)
            ->deleteJson('/api/v1/admin/contracts/31')
            ->assertStatus(409);
    }

    public function test_create_api_ignores_requested_status_and_persists_draft_without_archive_permission(): void
    {
        $this->createContractTables();
        $this->createProjectsTable();
        \DB::table('projects')->insert(['id' => 41, 'organization_id' => 7]);
        Contract::flushEventListeners();

        $authorization = $this->mockAuthorization(static fn (string $permission): bool => $permission === 'contracts.create');
        $this->app->instance(AuthorizationService::class, $authorization);

        Route::post('/__review/contracts', static function (StoreContractRequest $request) {
            $dto = $request->toDto();
            $contract = Contract::create([
                'organization_id' => 7,
                'project_id' => $dto->project_id,
                'number' => $dto->number,
                'date' => $dto->date,
                'status' => $dto->status,
                'is_self_execution' => true,
            ]);

            return AdminResponse::success(['id' => $contract->id, 'status' => $contract->status->value], null, 201);
        });

        $response = $this->actingAs($this->user(7))->postJson('/__review/contracts', [
            'project_id' => 41,
            'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR->value,
            'number' => 'DRAFT-ONLY',
            'date' => '2026-07-19',
            'status' => 'archived',
            'is_self_execution' => true,
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'draft');
        self::assertSame('draft', Contract::query()->where('number', 'DRAFT-ONLY')->firstOrFail()->status->value);
    }

    public function test_form_requests_forward_project_route_context_to_authorization_service(): void
    {
        $this->createContractTables();
        $this->persistContract(22, 11);
        $user = $this->user(7);
        $expected = [
            StoreContractRequest::class => 'contracts.create',
            UpdateContractRequest::class => 'contracts.edit',
            StoreContractPerformanceActRequest::class => 'contracts.performance_acts.create',
            UpdateContractPerformanceActRequest::class => 'contracts.performance_acts.edit',
        ];

        foreach ($expected as $requestClass => $permission) {
            $authorization = \Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('can')
                ->once()
                ->with($user, $permission, ['organization_id' => 7, 'project_id' => 11])
                ->andReturnTrue();
            $this->app->instance(AuthorizationService::class, $authorization);

            $request = $this->requestWithProjectRoute($requestClass, $user, 11);
            self::assertTrue($request->authorize());
        }
    }

    public function test_performance_act_permissions_deny_direct_http_requests(): void
    {
        $this->app->instance(AuthorizationService::class, $this->mockAuthorization(static fn (): bool => false));
        $user = $this->user(7);

        foreach ([
            'view' => 'GET',
            'create' => 'POST',
            'edit' => 'PUT',
            'delete' => 'DELETE',
            'export' => 'GET',
        ] as $permission => $method) {
            Route::match([$method], "/__review/performance-acts/{$permission}", static fn () => AdminResponse::success())
                ->middleware("authorize:contracts.performance_acts.{$permission}");

            $this->actingAs($user)
                ->json($method, "/__review/performance-acts/{$permission}")
                ->assertForbidden();
        }
    }

    public function test_module_permissions_match_contract_management_json(): void
    {
        $modulePermissions = app(ContractManagementModule::class)->getPermissions();
        $jsonPermissions = json_decode(
            file_get_contents(config_path('ModuleList/features/contract-management.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        )['permissions'];

        sort($modulePermissions);
        sort($jsonPermissions);

        self::assertContains('contracts.archive', $modulePermissions);
        self::assertSame($jsonPermissions, $modulePermissions);
    }

    private function contractWithStatus(string $status, bool $expectsSave = true): Contract
    {
        $contract = \Mockery::mock(Contract::class)->makePartial();
        if ($expectsSave) {
            $contract->shouldReceive('save')->once()->andReturnTrue();
        }
        $contract->setRawAttributes([
            'id' => 10,
            'status' => $status,
        ]);

        return $contract;
    }

    private function mockAuthorization(callable $can): AuthorizationService
    {
        return $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($can): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission, ?array $context = null): bool => $can($permission)
            );
        });
    }

    private function user(int $organizationId): User
    {
        $user = new User;
        $user->id = 42;
        $user->current_organization_id = $organizationId;

        return $user;
    }

    private function requestWithProjectRoute(string $requestClass, User $user, int $projectId): Request
    {
        $request = $requestClass::create("/__review/projects/{$projectId}/contracts/22", 'POST');
        $request->setUserResolver(static fn (): User => $user);
        $request->attributes->set('current_organization_id', 7);
        $route = new LaravelRoute(['POST'], '__review/projects/{project}/contracts/{contract}', static fn () => null);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);

        return $request;
    }

    private function createContractTables(): void
    {
        Schema::dropIfExists('contract_current_state');
        Schema::dropIfExists('contract_state_events');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('organizations');
        Schema::create('organizations', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        \DB::table('organizations')->insert([
            'id' => 7,
            'name' => 'Test organization',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createProjectsTable();
        Schema::dropIfExists('project_organization');
        Schema::create('project_organization', static function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('role')->nullable();
            $table->string('role_new')->nullable();
            $table->json('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('added_by_user_id')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        \DB::table('projects')->insert([
            ['id' => 11, 'organization_id' => 7, 'name' => 'Project 11'],
            ['id' => 17, 'organization_id' => 7, 'name' => 'Project 17'],
            ['id' => 99, 'organization_id' => 7, 'name' => 'Project 99'],
        ]);
        Schema::create('contracts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('number');
            $table->date('date');
            $table->string('status');
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->boolean('is_self_execution')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::dropIfExists('contract_parties');
        Schema::create('contract_parties', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->string('side');
            $table->string('role')->nullable();
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->unsignedBigInteger('linked_organization_id')->nullable();
            $table->string('name')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('inn')->nullable();
            $table->string('kpp')->nullable();
            $table->string('ogrn')->nullable();
            $table->text('legal_address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });
        Schema::create('contract_state_events', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->string('event_type');
            $table->string('triggered_by_type')->nullable();
            $table->unsignedBigInteger('triggered_by_id')->nullable();
            $table->unsignedBigInteger('specification_id')->nullable();
            $table->decimal('amount_delta', 15, 2)->default(0);
            $table->date('effective_from')->nullable();
            $table->unsignedBigInteger('supersedes_event_id')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('contract_current_state', static function (Blueprint $table): void {
            $table->unsignedBigInteger('contract_id')->primary();
            $table->unsignedBigInteger('active_specification_id')->nullable();
            $table->decimal('current_total_amount', 15, 2)->default(0);
            $table->json('active_events')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
        });
        Contract::flushEventListeners();
    }

    private function createProjectsTable(): void
    {
        Schema::dropIfExists('projects');
        Schema::create('projects', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function persistContract(int $id, int $projectId): Contract
    {
        return Contract::unguarded(static fn (): Contract => Contract::query()->create([
            'id' => $id,
            'organization_id' => 7,
            'project_id' => $projectId,
            'number' => "C-{$id}",
            'date' => '2026-07-19',
            'status' => 'draft',
        ]));
    }

    private function assertRoutePermission(string $method, string $uri, string $permission): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(
            static fn (LaravelRoute $route): bool => $route->uri() === $uri && in_array($method, $route->methods(), true)
        );

        self::assertNotEmpty($routes, "Маршрут {$method} {$uri} не найден.");

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $hasPermissionMiddleware = collect($middleware)->contains(
                static fn (string $value): bool => str_starts_with($value, "authorize:{$permission}")
                    || str_starts_with($value, AuthorizeMiddleware::class.":{$permission}")
            );

            self::assertTrue(
                $hasPermissionMiddleware,
                "Маршрут {$method} {$uri} не защищен правом {$permission}. Стек: ".implode(', ', $middleware)
            );
        }
    }
}
