<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialDeliveryService;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Contracts\PurchaseReceiptReturnAuthorizer;
use App\BusinessModules\Features\Procurement\Contracts\PurchaseReceiptReturnUnitOfWork;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementOwnerWorkflowRuntime;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleOwnerEventRecorder;
use App\BusinessModules\Features\Procurement\Reporting\ProcurementReportingLifecycleRecorder;
use App\BusinessModules\Features\Procurement\Services\ProcurementAuditService;
use App\BusinessModules\Features\Procurement\Services\ProcurementLifecycleService;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPaymentGateService;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPdfService;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderService;
use App\BusinessModules\Features\Procurement\Services\PurchaseReceiptInventoryService;
use App\BusinessModules\Features\Procurement\Services\SupplierPartyService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PurchaseOrderPdfCompensationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[DataProvider('transactionOutcomeProvider')]
    public function test_compensates_only_when_pdf_path_was_not_committed(
        string $persistedPath,
        bool $shouldRemove,
        string $expectedInMemoryPath,
    ): void {
        $path = 'org-12/procurement/purchase-orders/user-34/order-56/object.pdf';
        $previousPath = 'org-12/procurement/purchase-orders/user-34/order-56/previous.pdf';
        $order = new PurchaseOrder;
        $order->setRawAttributes(['id' => 56, 'organization_id' => 12]);
        $order->metadata = ['pdf_path' => $previousPath];

        $pdfs = Mockery::mock(PurchaseOrderPdfService::class);
        if ($shouldRemove) {
            $pdfs->shouldReceive('remove')->once()->with($order, $path);
        } else {
            $pdfs->shouldNotReceive('remove');
        }

        $runtime = Mockery::mock(ProcurementOwnerWorkflowRuntime::class);
        $runtime->shouldReceive('within')
            ->once()
            ->andReturnUsing(static function (callable $workflow): never {
                $workflow();

                throw new RuntimeException('simulated_transaction_boundary_failure');
            });

        $service = new class($pdfs, Mockery::mock(SupplierPartyService::class), Mockery::mock(ProcurementAuditService::class), Mockery::mock(ProcurementLifecycleService::class), new PurchaseOrderPaymentGateService, Mockery::mock(ProjectMaterialDeliveryService::class), (new \ReflectionClass(ProcurementCycleOwnerEventRecorder::class))->newInstanceWithoutConstructor(), $runtime, $path, $persistedPath) extends PurchaseOrderService
        {
            public function __construct(
                PurchaseOrderPdfService $pdfService,
                SupplierPartyService $supplierPartyService,
                ProcurementAuditService $auditService,
                ProcurementLifecycleService $lifecycleService,
                PurchaseOrderPaymentGateService $paymentGateService,
                ProjectMaterialDeliveryService $deliveryService,
                ProcurementCycleOwnerEventRecorder $cycleEventRecorder,
                ProcurementOwnerWorkflowRuntime $ownerWorkflowRuntime,
                private readonly string $path,
                private readonly string $persistedPath,
            ) {
                parent::__construct(
                    $pdfService,
                    $supplierPartyService,
                    $auditService,
                    $lifecycleService,
                    $paymentGateService,
                    $deliveryService,
                    $cycleEventRecorder,
                    $ownerWorkflowRuntime,
                    (new \ReflectionClass(ProcurementReportingLifecycleRecorder::class))->newInstanceWithoutConstructor(),
                    (new \ReflectionClass(PurchaseReceiptInventoryService::class))->newInstanceWithoutConstructor(),
                    Mockery::mock(PurchaseReceiptReturnAuthorizer::class),
                    Mockery::mock(PurchaseReceiptReturnUnitOfWork::class),
                );
            }

            protected function sendToSupplierOwnerWorkflow(PurchaseOrder $order, callable $onSent): PurchaseOrder
            {
                $order->metadata = ['pdf_path' => $this->path];

                return $order;
            }

            protected function persistedPurchaseOrderPdfPath(PurchaseOrder $order): ?string
            {
                return $this->persistedPath;
            }
        };

        try {
            $service->sendToSupplier($order);
            self::fail('Transaction boundary failure was expected.');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated_transaction_boundary_failure', $exception->getMessage());
        }

        self::assertSame($expectedInMemoryPath, $order->metadata['pdf_path']);
    }

    public static function transactionOutcomeProvider(): iterable
    {
        $new = 'org-12/procurement/purchase-orders/user-34/order-56/object.pdf';
        $previous = 'org-12/procurement/purchase-orders/user-34/order-56/previous.pdf';

        yield 'commit rolled back' => [$previous, true, $previous];
        yield 'after-commit callback failed' => [$new, false, $new];
    }
}
