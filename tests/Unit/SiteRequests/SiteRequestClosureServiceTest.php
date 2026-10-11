<?php

declare(strict_types=1);

namespace Tests\Unit\SiteRequests;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestClosureService;
use App\BusinessModules\Features\Procurement\Services\ProcurementChainService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SiteRequestClosureServiceTest extends TestCase
{
    private ?Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $container->instance('app', Mockery::mock()->shouldReceive('getLocale')->andReturn('ru')->getMock());
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $loader = new ArrayLoader;
        $loader->addMessages('ru', 'site_requests', require __DIR__.'/../../../lang/ru/site_requests.php');
        $loader->addMessages('ru', 'procurement', require __DIR__.'/../../../lang/ru/procurement.php');
        $loader->addMessages('ru', 'errors', ['unauthorized' => 'Нет доступа.']);
        $container->instance('translator', new Translator($loader, 'ru'));
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($this->previousContainer);
        Mockery::close();
        parent::tearDown();
    }

    public function test_closure_requires_permission_for_request_organization_and_project(): void
    {
        $actor = new User(['current_organization_id' => 7]);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects(self::once())->method('can')
            ->with($actor, 'site_requests.change_status', ['organization_id' => 7, 'project_id' => 9])
            ->willReturn(true);
        $service = new SiteRequestClosureService($authorization);

        $service->assertAllowed($this->request(), $actor, 'completed', 'Материалы уже есть на объекте');
    }

    public function test_actor_from_another_organization_is_rejected_before_permission_check(): void
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects(self::never())->method('can');
        $service = new SiteRequestClosureService($authorization);
        $this->expectException(AuthorizationException::class);
        $service->assertAllowed($this->request(), new User(['current_organization_id' => 8]), 'completed', 'Не требуется');
    }

    public function test_missing_permission_is_rejected(): void
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(false);
        $service = new SiteRequestClosureService($authorization);
        $this->expectException(AuthorizationException::class);
        $service->assertAllowed($this->request(), new User(['current_organization_id' => 7]), 'completed', 'Не требуется');
    }

    #[DataProvider('statuses')]
    public function test_only_operational_material_requests_can_be_closed(SiteRequestStatusEnum $status, bool $expected): void
    {
        $service = new SiteRequestClosureService($this->createMock(AuthorizationService::class));
        $request = $this->request();
        $request->status = $status;
        self::assertSame($expected, $service->isAvailable($request));
    }

    public static function statuses(): array
    {
        return array_map(static fn (SiteRequestStatusEnum $status): array => [$status, in_array($status, [
            SiteRequestStatusEnum::APPROVED, SiteRequestStatusEnum::IN_PROGRESS,
            SiteRequestStatusEnum::FULFILLED, SiteRequestStatusEnum::ON_HOLD,
        ], true)], SiteRequestStatusEnum::cases());
    }

    #[DataProvider('linkedRelations')]
    public function test_linked_documents_prevent_closure(string $relation): void
    {
        $service = new SiteRequestClosureService($this->createMock(AuthorizationService::class));
        $request = $this->request();
        $request->setRelation($relation, new Collection([new SiteRequest]));
        self::assertFalse($service->isAvailable($request));
    }

    public static function linkedRelations(): array
    {
        return [['purchaseRequests'], ['paymentDocuments'], ['materialDeliveries']];
    }

    public function test_warehouse_reservation_and_legacy_payment_prevent_closure(): void
    {
        $service = new SiteRequestClosureService($this->createMock(AuthorizationService::class));
        $request = $this->request();
        $request->metadata = ['material_reserved' => true];
        self::assertFalse($service->isAvailable($request));
        $request->metadata = ['fulfillment_decision' => ['source' => 'warehouse']];
        self::assertFalse($service->isAvailable($request));
        $request->metadata = [];
        $request->payment_document_id = 11;
        self::assertFalse($service->isAvailable($request));
    }

    public function test_closed_request_has_no_next_procurement_action_or_pending_stages(): void
    {
        $request = $this->request();
        $request->status = SiteRequestStatusEnum::COMPLETED;
        $request->metadata = ['closure_without_procurement' => ['reason' => 'Материалы уже есть']];
        $service = (new ReflectionClass(ProcurementChainService::class))->newInstanceWithoutConstructor();
        [$stage, $nextAction, $blockers] = (new ReflectionMethod($service, 'resolveCurrentState'))->invoke(
            $service, ['site_request' => $request], null, 7
        );
        self::assertSame('closed_without_procurement', $stage);
        self::assertNull($nextAction);
        self::assertTrue($blockers->isEmpty());
        $stages = (new ReflectionMethod($service, 'stages'))->invoke($service, $stage, collect(), $blockers, false, []);
        self::assertCount(1, $stages);
        self::assertSame('done', $stages->first()->status);
        self::assertSame('Заявка закрыта без закупок', $stages->first()->label);
    }

    public function test_closure_without_procurement_is_not_available_for_equipment_request(): void
    {
        $request = $this->request();
        $request->request_type = SiteRequestTypeEnum::EQUIPMENT_REQUEST;
        $service = new SiteRequestClosureService($this->createMock(AuthorizationService::class));
        self::assertFalse($service->isAvailable($request));
    }

    #[DataProvider('invalidCommands')]
    public function test_invalid_closure_commands_are_rejected(string $status, ?string $reason): void
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $service = new SiteRequestClosureService($authorization);
        $this->expectException(DomainException::class);
        $service->assertAllowed($this->request(), new User(['current_organization_id' => 7]), $status, $reason);
    }

    public static function invalidCommands(): array
    {
        return [['fulfilled', 'Не требуется'], ['completed', null], ['completed', '  '], ['completed', 'да'], ['completed', str_repeat('я', 1001)]];
    }

    private function request(): SiteRequest
    {
        $request = new SiteRequest([
            'organization_id' => 7,
            'project_id' => 9,
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST,
            'status' => SiteRequestStatusEnum::APPROVED,
            'metadata' => [],
            'payment_document_id' => null,
        ]);
        foreach (['purchaseRequests', 'paymentDocuments', 'materialDeliveries'] as $relation) {
            $request->setRelation($relation, new Collection);
        }
        return $request;
    }
}
