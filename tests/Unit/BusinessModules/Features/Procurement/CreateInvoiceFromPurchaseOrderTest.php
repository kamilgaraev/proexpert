<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\Procurement;

use App\BusinessModules\Features\Procurement\Events\PurchaseOrderCreated;
use App\BusinessModules\Features\Procurement\Listeners\CreateInvoiceFromPurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Modules\Core\AccessController;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

final class CreateInvoiceFromPurchaseOrderTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Mockery::close();

        parent::tearDown();
    }

    public function test_listener_skips_auto_creation_when_budgeting_requires_manual_dimensions(): void
    {
        $order = new PurchaseOrder();
        $order->forceFill([
            'id' => 77,
            'organization_id' => 15,
        ]);

        $accessController = Mockery::mock(AccessController::class);
        $accessController
            ->shouldReceive('hasModuleAccess')
            ->once()
            ->with(15, 'payments')
            ->andReturnTrue();
        $accessController
            ->shouldReceive('hasModuleAccess')
            ->once()
            ->with(15, 'budgeting')
            ->andReturnTrue();

        $logger = Mockery::mock();
        $logger
            ->shouldReceive('info')
            ->once()
            ->with('procurement.skip_invoice_creation', [
                'purchase_order_id' => 77,
                'reason' => 'budgeting_dimensions_required',
            ]);

        $container = new Container();
        $container->instance('log', $logger);
        Facade::setFacadeApplication($container);

        (new CreateInvoiceFromPurchaseOrder($accessController))->handle(new PurchaseOrderCreated($order));

        $this->addToAssertionCount(3);
    }
}
