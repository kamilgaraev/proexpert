<?php

declare(strict_types=1);

namespace Tests\Unit\Landing;

use App\Http\Controllers\Api\V1\Landing\ModuleController;
use App\Models\Module;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Landing\ModulesOverviewService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ActiveModulesResponseTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();

        $container = new Container;
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $application = $this->createStub(Application::class);
        $application->method('getLocale')->willReturn('ru');
        $container->instance('app', $application);
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $container->instance('translator', new Translator(new ArrayLoader, 'ru'));
        $container->instance('log', $this->createStub(LoggerInterface::class));

        $responseFactory = $this->createStub(ResponseFactory::class);
        $responseFactory->method('json')->willReturnCallback(
            static fn (mixed $data, int $status = 200, array $headers = [], int $options = 0): JsonResponse =>
                new JsonResponse($data, $status, $headers, $options),
        );
        $container->instance(ResponseFactory::class, $responseFactory);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function test_active_modules_return_public_data_for_the_selected_organization(): void
    {
        $module = new Module([
            'id' => 7,
            'name' => 'Склад',
            'slug' => 'basic-warehouse',
            'permissions' => ['warehouse.view'],
            'is_active' => true,
            'class_name' => 'InternalModuleClass',
            'service_provider' => 'InternalServiceProvider',
        ]);

        $response = $this->activeResponse(new Collection([$module]));
        $payload = $response->getData(true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertCount(1, $payload['data']);
        self::assertSame('basic-warehouse', $payload['data'][0]['slug']);
        self::assertSame('Склад', $payload['data'][0]['name']);
        self::assertSame(['warehouse.view'], $payload['data'][0]['permissions']);
        self::assertArrayNotHasKey('class_name', $payload['data'][0]);
        self::assertArrayNotHasKey('service_provider', $payload['data'][0]);
    }

    public function test_organization_without_active_modules_returns_an_empty_list(): void
    {
        $response = $this->activeResponse(new Collection);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->getData(true)['success']);
        self::assertSame([], $response->getData(true)['data']);
    }

    private function activeResponse(Collection $modules): JsonResponse
    {
        $entitlements = $this->createMock(OrganizationEntitlementService::class);
        $entitlements->expects(self::once())
            ->method('getEffectiveModules')
            ->with(38)
            ->willReturn($modules);

        $controller = new ModuleController(
            $entitlements,
            $this->createStub(ModulesOverviewService::class),
            $this->createStub(PackageCatalogService::class),
        );
        $request = Request::create('/api/v1/landing/modules/active');
        $request->attributes->set('organization_id', 38);

        return $controller->active($request);
    }
}
