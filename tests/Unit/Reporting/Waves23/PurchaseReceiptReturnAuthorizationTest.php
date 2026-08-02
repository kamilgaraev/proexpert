<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\Procurement\Contracts\PurchaseReceiptReturnAuthorizer;
use App\BusinessModules\Features\Procurement\Http\Requests\ReturnPurchaseReceiptLineRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;

final class PurchaseReceiptReturnAuthorizationTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container;
        Container::setInstance($this->container);
    }

    public function test_request_passes_exact_route_and_actor_facts_to_authorizer(): void
    {
        $actor = new User;
        $actor->forceFill(['id' => 71]);
        $authorizer = new RecordingReturnAuthorizer(true);
        $this->container->instance(PurchaseReceiptReturnAuthorizer::class, $authorizer);

        $request = ReturnPurchaseReceiptLineRequest::create(
            '/api/v1/admin/procurement/purchase-orders/41/receipt-lines/83/return',
            'POST',
        );
        $request->attributes->set('current_organization_id', 19);
        $request->setUserResolver(static fn (): User => $actor);
        $route = $this->createMock(Route::class);
        $route->method('parameter')->willReturnMap([['id', null, 41], ['line', null, 83]]);
        $request->setRouteResolver(static fn (): Route => $route);

        self::assertTrue($request->authorize());
        self::assertSame([71, 19, 41, 83], $authorizer->facts);
    }

    public function test_request_fails_closed_when_resource_authorizer_denies(): void
    {
        $actor = new User;
        $actor->forceFill(['id' => 71]);
        $this->container->instance(
            PurchaseReceiptReturnAuthorizer::class,
            new RecordingReturnAuthorizer(false),
        );
        $request = ReturnPurchaseReceiptLineRequest::create('/return', 'POST');
        $request->attributes->set('current_organization_id', 19);
        $request->setUserResolver(static fn (): User => $actor);
        $route = $this->createMock(Route::class);
        $route->method('parameter')->willReturnMap([['id', null, 41], ['line', null, 83]]);
        $request->setRouteResolver(static fn (): Route => $route);

        self::assertFalse($request->authorize());
    }
}

final class RecordingReturnAuthorizer implements PurchaseReceiptReturnAuthorizer
{
    /** @var list<int> */
    public array $facts = [];

    public function __construct(private readonly bool $allowed) {}

    public function canReturn(
        User $actor,
        int $organizationId,
        int $purchaseOrderId,
        int $receiptLineId,
    ): bool {
        $this->facts = [
            (int) $actor->getAuthIdentifier(),
            $organizationId,
            $purchaseOrderId,
            $receiptLineId,
        ];

        return $this->allowed;
    }

    public function assertCanReturn(
        User $actor,
        int $organizationId,
        int $purchaseOrderId,
        int $receiptLineId,
    ): PurchaseReceiptLine {
        throw new \LogicException('Not used by request authorization tests.');
    }
}
