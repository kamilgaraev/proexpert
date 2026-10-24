<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Database\NativeParameterPostgresConnection;
use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Http\Controllers\PurchaseRequestController;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseReceiptResource;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseRequestResource;
use App\BusinessModules\Features\Procurement\Services\ProcurementChainService;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPaymentGateService;
use App\BusinessModules\Features\Procurement\Services\PurchaseRequestService;
use App\BusinessModules\Features\Procurement\Exceptions\PurchaseReceiptPaymentException;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PurchaseRequestListPaymentBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_serialization_batches_financial_reads_without_changing_rows(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]{24}_testing$/D', DB::connection()->getDatabaseName());
        self::assertInstanceOf(NativeParameterPostgresConnection::class, DB::connection());

        self::assertSame('testing', app()->environment());
        Queue::fake();
        $this->freezeTime();

        $organization = Organization::factory()->create();
        $requestIds = [];
        $expectedPaidAmounts = [];
        $expectedAdvanceAmounts = [];
        $verifiedAdvanceCount = 0;
        for ($index = 0; $index < 8; $index++) {
            $purchaseRequest = PurchaseRequest::query()->create([
                'organization_id' => $organization->id,
                'request_number' => 'PR-LIST-CAUSAL-'.$index,
                'status' => PurchaseRequestStatusEnum::APPROVED,
                'budget_currency' => 'RUB',
            ]);
            PurchaseRequestLine::query()->create([
                'purchase_request_id' => $purchaseRequest->id,
                'name' => 'Synthetic material',
                'quantity' => 1,
                'unit' => 'pcs',
            ]);

            $proposalId = null;
            $versionId = null;
            if ($index < 2) {
                [$proposalId, $versionId] = $this->createVerifiedAdvanceProposal($purchaseRequest, $index);
                $verifiedAdvanceCount++;
                $expectedAdvanceAmounts[$purchaseRequest->id] = 150.0;
            } else {
                $expectedAdvanceAmounts[$purchaseRequest->id] = null;
            }

            $order = PurchaseOrder::query()->create([
                'organization_id' => $organization->id,
                'purchase_request_id' => $purchaseRequest->id,
                'accepted_supplier_proposal_id' => $proposalId,
                'accepted_supplier_proposal_version_id' => $versionId,
                'order_number' => 'PO-LIST-CAUSAL-'.$index,
                'order_date' => now()->toDateString(),
                'status' => PurchaseOrderStatusEnum::CONFIRMED,
                'total_amount' => 500,
                'currency' => 'RUB',
            ]);
            $item = PurchaseOrderItem::query()->create([
                'purchase_order_id' => $order->id,
                'material_name' => 'Synthetic material',
                'quantity' => 5,
                'unit' => 'pcs',
                'unit_price' => 100,
                'total_price' => 500,
            ]);

            $isFullyPaid = $index % 2 === 0;
            $paidAmount = $isFullyPaid ? 500.0 : 250.0;
            $expectedPaidAmounts[$purchaseRequest->id] = $paidAmount;
            PaymentDocument::query()->create([
                'organization_id' => $organization->id,
                'document_type' => PaymentDocumentType::PAYMENT_ORDER,
                'document_number' => 'PAY-LIST-CAUSAL-'.$index,
                'document_date' => now()->toDateString(),
                'direction' => InvoiceDirection::OUTGOING,
                'invoice_type' => InvoiceType::MATERIAL_PURCHASE,
                'payer_organization_id' => $organization->id,
                'payee_organization_id' => $organization->id,
                'amount' => 500,
                'currency' => 'RUB',
                'paid_amount' => $paidAmount,
                'remaining_amount' => 500 - $paidAmount,
                'status' => $isFullyPaid ? PaymentDocumentStatus::PAID : PaymentDocumentStatus::APPROVED,
                'paid_at' => $isFullyPaid ? now() : null,
                'metadata' => [
                    'purchase_order_id' => $order->id,
                    'purchase_order_number' => $order->order_number,
                ],
            ]);

            $warehouse = OrganizationWarehouse::query()->create([
                'organization_id' => $organization->id,
                'name' => 'Synthetic warehouse '.$index,
                'code' => 'WH-LIST-CAUSAL-'.$index,
                'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
                'is_main' => true,
                'is_active' => true,
            ]);
            $receipt = PurchaseReceipt::query()->create([
                'organization_id' => $organization->id,
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouse->id,
                'receipt_number' => 'REC-LIST-CAUSAL-'.$index,
                'receipt_date' => now()->toDateString(),
                'status' => 'posted',
            ]);
            $receipt->lines()->create([
                'purchase_order_item_id' => $item->id,
                'quantity_received' => 1,
                'price' => 100,
                'total_amount' => 100,
            ]);
            $requestIds[] = (int) $purchaseRequest->id;
        }

        $capture = (object) [
            'active' => false,
            'total_count' => 0,
            'total_ms' => 0.0,
            'table_counts' => [],
            'table_ms' => [],
            'source_counts' => [],
        ];
        DB::listen(static function (QueryExecuted $query) use ($capture): void {
            if (! $capture->active) {
                return;
            }

            $capture->total_count++;
            $capture->total_ms += $query->time;
            $sql = strtolower($query->sql);
            foreach ([
                'payment_documents' => 'payment_documents',
                'purchase_receipt_lines' => 'purchase_receipt_lines',
                'supplier_proposal_versions' => 'supplier_proposal_versions',
            ] as $table => $needle) {
                if (str_contains($sql, $needle)) {
                    $capture->table_counts[$table]++;
                    $capture->table_ms[$table] += $query->time;
                }
            }

            $sourceByFile = [
                'PurchaseRequestService.php' => 'request_service',
                'PurchaseOrderPaymentGateService.php' => 'payment_gate',
                'ProcurementChainResolver.php' => 'chain_resolver',
                'ProcurementLifecycleService.php' => 'lifecycle_service',
                'PurchaseRequestResource.php' => 'request_resource',
                'PurchaseOrderResource.php' => 'order_resource',
            ];
            $source = 'other';
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 32) as $frame) {
                $file = basename(str_replace('\\', '/', (string) ($frame['file'] ?? '')));
                if (isset($sourceByFile[$file])) {
                    $source = $sourceByFile[$file];
                    break;
                }
            }
            $capture->source_counts[$source]++;
        });

        $readList = function (int $perPage) use ($organization, $capture): array {
            $capture->active = true;
            $capture->total_count = 0;
            $capture->total_ms = 0.0;
            $capture->table_counts = array_fill_keys([
                'payment_documents',
                'purchase_receipt_lines',
                'supplier_proposal_versions',
            ], 0);
            $capture->table_ms = array_fill_keys([
                'payment_documents',
                'purchase_receipt_lines',
                'supplier_proposal_versions',
            ], 0.0);
            $capture->source_counts = array_fill_keys([
                'request_service',
                'payment_gate',
                'chain_resolver',
                'lifecycle_service',
                'request_resource',
                'order_resource',
                'other',
            ], 0);

            $request = Request::create('/api/v1/admin/procurement/purchase-requests', 'GET', [
                'per_page' => $perPage,
                'sort_by' => 'id',
                'sort_dir' => 'asc',
            ]);
            $request->attributes->set('current_organization_id', (int) $organization->id);
            $startedAt = hrtime(true);
            try {
                $response = app(PurchaseRequestController::class)->index($request);
                $payload = $response->getData(true);
                $elapsedMs = round((hrtime(true) - $startedAt) / 1_000_000, 3);
            } finally {
                $capture->active = false;
            }

            return [
                'status' => $response->getStatusCode(),
                'payload' => $payload,
                'elapsed_ms' => $elapsedMs,
                'sql_count' => $capture->total_count,
                'sql_total_ms' => round($capture->total_ms, 3),
                'table_counts' => $capture->table_counts,
                'table_ms' => array_map(static fn (float $value): float => round($value, 3), $capture->table_ms),
                'source_counts' => $capture->source_counts,
            ];
        };

        $one = $readList(1);
        $eight = $readList(8);
        self::assertSame(200, $one['status']);
        self::assertSame(200, $eight['status']);
        self::assertCount(1, $one['payload']['data']);
        self::assertCount(8, $eight['payload']['data']);
        self::assertSame($requestIds, array_column($eight['payload']['data'], 'id'));

        $firstId = $one['payload']['data'][0]['id'];
        $firstInEight = collect($eight['payload']['data'])->firstWhere('id', $firstId);
        self::assertIsArray($firstInEight);
        self::assertSame($one['payload']['data'][0], $firstInEight, 'The complete first serialized entry must match across page sizes.');

        foreach ($eight['payload']['data'] as $entry) {
            $expectedPaid = $expectedPaidAmounts[$entry['id']];
            self::assertCount(1, $entry['purchase_orders']);
            $orderPayload = $entry['purchase_orders'][0];
            self::assertEquals($expectedPaid, $orderPayload['payment_summary']['paid_amount']);
            self::assertEquals(100, $orderPayload['payment_summary']['received_amount']);
            self::assertEquals($expectedPaid - 100, $orderPayload['payment_summary']['available_paid_amount']);
            $advance = $orderPayload['payment_summary']['required_advance_amount'];
            if ($expectedAdvanceAmounts[$entry['id']] === null) {
                self::assertNull($advance);
            } else {
                self::assertTrue(is_int($advance) || is_float($advance));
                self::assertSame($expectedAdvanceAmounts[$entry['id']], (float) $advance);
            }
            self::assertSame(1, $orderPayload['payment_summary']['documents_count']);
            self::assertCount(1, $orderPayload['receipts']);
            self::assertSame('posted', $orderPayload['receipts'][0]['status']);
            self::assertCount(1, $orderPayload['receipts'][0]['lines']);
        }
        self::assertSame($one['sql_count'], $eight['sql_count']);
        self::assertSame($one['table_counts'], $eight['table_counts']);
        self::assertSame(2, $eight['table_counts']['payment_documents']);
        self::assertSame(3, $eight['table_counts']['purchase_receipt_lines']);
        self::assertSame(1, $eight['table_counts']['supplier_proposal_versions']);

        $service = app(PurchaseRequestService::class);
        $page = $service->paginateForListView((int) $organization->id, 8, ['sort_by' => 'id', 'sort_dir' => 'asc']);
        $legacyModels = $page->getCollection()->map(static fn ($item) => $item->purchaseRequest);
        $request = Request::create('/api/v1/admin/procurement/purchase-requests', 'GET');
        $request->attributes->set('current_organization_id', (int) $organization->id);
        $legacyPayload = PurchaseRequestResource::collection($legacyModels)
            ->response($request)->getData(true);
        self::assertSame($eight['payload']['data'], $legacyPayload['data']);
        foreach ($eight['payload']['data'] as $entry) {
            if ($expectedAdvanceAmounts[$entry['id']] === null) {
                self::assertArrayNotHasKey('accepted_supplier_proposal_version', $entry['purchase_orders'][0]);
            } else {
                self::assertArrayHasKey('accepted_supplier_proposal_version', $entry['purchase_orders'][0]);
            }
        }
        self::assertSame(4, count(array_filter($expectedPaidAmounts, static fn (float $amount): bool => $amount === 250.0)));
        self::assertSame(4, count(array_filter($expectedPaidAmounts, static fn (float $amount): bool => $amount === 500.0)));
        self::assertSame(2, $verifiedAdvanceCount);

        $changedOrder = $page->getCollection()->last()->purchaseRequest->purchaseOrders->first();
        $collection = PurchaseRequestResource::collection($page);
        $before = $collection->response($request)->getData(true);
        $payment = PaymentDocument::query()->where('metadata->purchase_order_id', $changedOrder->id)->firstOrFail();
        $gate = app(PurchaseOrderPaymentGateService::class);
        $staleContext = $gate->batchForListSerialization(collect([$changedOrder]));
        $payment->update(['paid_amount' => 50, 'remaining_amount' => 450]);
        $after = $collection->response($request)->getData(true);
        self::assertEquals(250, data_get($before, 'data.7.purchase_orders.0.payment_summary.paid_amount'));
        self::assertEquals(50, data_get($after, 'data.7.purchase_orders.0.payment_summary.paid_amount'));
        self::assertEquals(0, data_get($after, 'data.7.purchase_orders.0.payment_summary.available_paid_amount'));
        self::assertEquals(250, $gate->summary($changedOrder, $staleContext)['paid_amount']);
        self::assertEquals(50, $gate->summary($changedOrder)['paid_amount']);
        try {
            $gate->assertCanReceive($changedOrder, [['quantity_received' => 1, 'price' => 1]]);
            self::fail('A stale serialization snapshot must not authorize a new receipt.');
        } catch (PurchaseReceiptPaymentException) {
            self::assertTrue(true);
        }

        $newPayment = $this->createBatchPayment($changedOrder, 'NEW', ['paid_amount' => 100]);
        $afterInsert = $collection->response($request)->getData(true);
        self::assertEquals(150, data_get($afterInsert, 'data.7.purchase_orders.0.payment_summary.paid_amount'));
        self::assertSame(2, data_get($afterInsert, 'data.7.purchase_orders.0.payment_summary.documents_count'));
        $gate->assertCanReceive($changedOrder, [['quantity_received' => 1, 'price' => 1]]);
        $newPayment->delete();
        $afterDelete = $collection->response($request)->getData(true);
        self::assertEquals(50, data_get($afterDelete, 'data.7.purchase_orders.0.payment_summary.paid_amount'));
        self::assertSame(1, data_get($afterDelete, 'data.7.purchase_orders.0.payment_summary.documents_count'));
        $receipt = $changedOrder->receipts->first();
        $numericKey = PurchaseReceiptResource::collection(collect([7 => $receipt]))->response($request)->getData(true);
        $stringKey = PurchaseReceiptResource::collection(collect(['receipt' => $receipt]))->response($request)->getData(true);
        self::assertSame(array_values($numericKey['data']), array_values($stringKey['data']));
        self::assertSame([], PurchaseRequestResource::collection(collect())->response($request)->getData(true)['data']);

        fwrite(STDOUT, PHP_EOL.'PURCHASE_REQUEST_LIST_PAYMENT_READ_BATCH '.json_encode([
            'fixture_requests' => 8,
            'returned_rows_one' => count($one['payload']['data']),
            'returned_rows_eight' => count($eight['payload']['data']),
            'one' => array_intersect_key($one, array_flip(['elapsed_ms', 'sql_count', 'sql_total_ms', 'table_counts', 'table_ms', 'source_counts'])),
            'eight' => array_intersect_key($eight, array_flip(['elapsed_ms', 'sql_count', 'sql_total_ms', 'table_counts', 'table_ms', 'source_counts'])),
            'full_first_entry_parity' => true,
            'payment_and_posted_receipt_values_verified' => true,
            'verified_advance_proposal_fixture_count' => $verifiedAdvanceCount,
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_batch_preserves_document_matching_tenant_scope_and_posted_receipt_filters(): void
    {
        Queue::fake();
        $this->freezeTime();
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $contractor = Contractor::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Synthetic supplier',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $organization->id,
            'contractor_id' => $contractor->id,
            'contract_category' => 'procurement',
            'number' => 'CONTRACT-BATCH',
            'currency' => 'RUB',
            'date' => now()->toDateString(),
            'subject' => 'Synthetic supply',
            'work_type_category' => ContractWorkTypeCategoryEnum::SUPPLY,
            'base_amount' => 2000,
            'total_amount' => 2000,
            'status' => ContractStatusEnum::DRAFT,
            'is_fixed_amount' => true,
        ]);
        $first = $this->createBatchOrder($organization, 'A', $contract);
        $second = $this->createBatchOrder($organization, 'B', $contract);
        $foreign = $this->createBatchOrder($foreignOrganization, 'FOREIGN');
        $empty = $this->createBatchOrder($organization, 'EMPTY');
        $integerDocument = $this->createBatchPayment($first, 'INTEGER', ['paid_amount' => 10]);
        $stringDocument = $this->createBatchPayment($first, 'STRING', [
            'paid_amount' => 20,
            'document_date' => now()->subDay()->toDateString(),
            'metadata' => ['purchase_order_id' => (string) $first->id],
        ]);
        $sharedDocument = $this->createBatchPayment($first, 'OVERLAP', [
            'source_type' => Contract::class,
            'source_id' => $contract->id,
            'amount' => 30,
            'paid_amount' => 0,
            'remaining_amount' => 0,
            'status' => PaymentDocumentStatus::PAID,
            'paid_at' => now(),
        ]);
        $secondDocument = $this->createBatchPayment($second, 'SECOND', ['paid_amount' => 40]);
        foreach ([
            'INCOMING' => ['direction' => InvoiceDirection::INCOMING],
            'REJECTED' => ['status' => PaymentDocumentStatus::REJECTED],
            'CANCELLED' => ['status' => PaymentDocumentStatus::CANCELLED],
            'OTHER-ORDER' => ['metadata' => ['purchase_order_id' => $foreign->id]],
            'OTHER-ORG' => ['organization_id' => $foreignOrganization->id],
            'WRONG-INVOICE' => [
                'metadata' => [],
                'source_type' => Contract::class,
                'source_id' => $contract->id,
                'invoice_type' => InvoiceType::OTHER,
            ],
        ] as $suffix => $attributes) {
            $this->createBatchPayment($first, $suffix, $attributes);
        }
        $this->createBatchPayment($first, 'DELETED')->delete();
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Synthetic warehouse',
            'code' => 'WH-BATCH',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_active' => true,
        ]);
        foreach ([
            ['POSTED', 'posted', 100, false, false],
            ['CANCELLED', 'cancelled', 200, false, false],
            ['DELETED', 'posted', 400, true, false],
            ['REVERSED', 'posted', 800, false, true],
        ] as [$suffix, $status, $amount, $deleted, $reversed]) {
            $receipt = PurchaseReceipt::query()->create([
                'organization_id' => $organization->id,
                'purchase_order_id' => $first->id,
                'warehouse_id' => $warehouse->id,
                'receipt_number' => 'REC-BATCH-'.$suffix,
                'receipt_date' => now()->toDateString(),
                'status' => $status,
            ]);
            $receipt->lines()->create([
                'purchase_order_item_id' => $first->items()->firstOrFail()->id,
                'quantity_received' => 1,
                'price' => $amount,
                'total_amount' => $amount,
                'reversed_at' => $reversed ? now() : null,
            ]);
            if ($deleted) {
                $receipt->delete();
            }
        }
        $gate = app(PurchaseOrderPaymentGateService::class);
        $context = $gate->batchForListSerialization(collect([$first, $second, $foreign, $empty]));
        foreach ([$first, $second, $foreign, $empty] as $order) {
            self::assertSame($gate->summary($order), $gate->summary($order, $context));
            self::assertSame($gate->linkedDocuments($order)->pluck('id')->all(), $gate->linkedDocuments($order, $context)->pluck('id')->all());
        }
        self::assertEquals(60, $gate->summary($first, $context)['paid_amount']);
        self::assertEquals(100, $gate->summary($first, $context)['received_amount']);
        self::assertSame(3, $gate->summary($first, $context)['documents_count']);
        self::assertEquals(70, $gate->summary($second, $context)['paid_amount']);
        self::assertSame([$secondDocument->id, $sharedDocument->id], $gate->linkedDocuments($second, $context)->pluck('id')->all());
        self::assertSame([$sharedDocument->id, $integerDocument->id, $stringDocument->id], $gate->linkedDocuments($first, $context)->pluck('id')->all());
        $chain = app(ProcurementChainService::class);
        self::assertSame(
            $chain->forPurchaseOrder($first, includePermissions: false)->toArray(),
            $chain->forPurchaseOrder($first, includePermissions: false, paymentReadContext: $context)->toArray(),
        );
        $outsideBatch = $this->createBatchOrder($organization, 'FALLBACK');
        $this->createBatchPayment($outsideBatch, 'FALLBACK', ['paid_amount' => 75]);
        self::assertSame($gate->summary($outsideBatch), $gate->summary($outsideBatch, $context));
        self::assertEquals(75, $gate->summary($outsideBatch, $context)['paid_amount']);
    }

    private function createBatchOrder(Organization $organization, string $suffix, ?Contract $contract = null): PurchaseOrder
    {
        $request = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'request_number' => 'PR-BATCH-'.$suffix,
            'status' => PurchaseRequestStatusEnum::APPROVED,
            'budget_currency' => 'RUB',
        ]);
        $order = PurchaseOrder::query()->create([
            'organization_id' => $organization->id,
            'purchase_request_id' => $request->id,
            'contract_id' => $contract?->id,
            'order_number' => 'PO-BATCH-'.$suffix,
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 500,
            'currency' => 'RUB',
        ]);
        $order->items()->create([
            'material_name' => 'Synthetic material',
            'quantity' => 5,
            'unit' => 'pcs',
            'unit_price' => 100,
            'total_price' => 500,
        ]);

        return $order;
    }

    private function createBatchPayment(PurchaseOrder $order, string $suffix, array $attributes = []): PaymentDocument
    {
        return PaymentDocument::query()->create(array_replace([
            'organization_id' => $order->organization_id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'document_number' => 'PAY-BATCH-'.$suffix,
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'invoice_type' => InvoiceType::MATERIAL_PURCHASE,
            'payer_organization_id' => $order->organization_id,
            'payee_organization_id' => $order->organization_id,
            'amount' => 100,
            'currency' => 'RUB',
            'paid_amount' => 10,
            'remaining_amount' => 90,
            'status' => PaymentDocumentStatus::APPROVED,
            'metadata' => ['purchase_order_id' => $order->id],
        ], $attributes));
    }

    private function createVerifiedAdvanceProposal(PurchaseRequest $purchaseRequest, int $index): array
    {
        $supplierRequest = SupplierRequest::query()->create([
            'organization_id' => $purchaseRequest->organization_id,
            'purchase_request_id' => $purchaseRequest->id,
            'request_number' => 'SR-LIST-CAUSAL-'.$index,
            'status' => SupplierRequestStatusEnum::SENT,
            'public_token' => 'list-causal-token-'.$index.str_repeat('x', 32),
            'public_token_expires_at' => now()->addDay(),
        ]);
        $proposal = SupplierProposal::query()->create([
            'organization_id' => $purchaseRequest->organization_id,
            'supplier_request_id' => $supplierRequest->id,
            'proposal_number' => 'KP-LIST-CAUSAL-'.$index,
            'proposal_date' => now()->toDateString(),
            'status' => SupplierProposalStatusEnum::SUBMITTED,
            'subtotal_amount' => 100,
            'delivery_amount' => 0,
            'vat_amount' => 0,
            'total_amount' => 100,
            'currency' => 'RUB',
        ]);
        $version = SupplierProposalVersion::query()->create([
            'organization_id' => $purchaseRequest->organization_id,
            'supplier_proposal_id' => $proposal->id,
            'version_number' => 1,
            'commercial_snapshot' => ['payment_schedule' => [
                'mode' => 'mixed',
                'advance_percent' => 30,
                'deferment_days' => 15,
            ]],
            'content_hash' => hash('sha256', 'purchase-request-list-causal-'.$index),
            'integrity_status' => 'verified',
        ]);

        return [(int) $proposal->id, (int) $version->id];
    }
}
