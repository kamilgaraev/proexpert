<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseReceiptFromPaymentService;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestStatusChanged;
use App\BusinessModules\Features\SiteRequests\Listeners\CompleteSiteRequestsOnPaymentPaid;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Enums\EstimatePositionItemType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentDocumentEstimateLifecycleTest extends TestCase
{
    private PaymentDocumentService $service;
    private Organization $organization;
    private Organization $counterparty;
    private Project $project;
    private Estimate $estimate;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->service = app(PaymentDocumentService::class);
        $this->organization = Organization::factory()->create();
        $this->counterparty = Organization::factory()->create();
        $this->project = Project::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->estimate = Estimate::query()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'number' => 'EST-001',
            'name' => 'Test estimate',
            'estimate_date' => now()->toDateString(),
        ]);
    }

    public function test_creating_payment_document_with_estimate_splits_does_not_mark_estimate_items_paid(): void
    {
        $item = $this->createEstimateItem([
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-EST-001',
            'status' => PaymentDocumentStatus::APPROVED->value,
        ]);

        $this->assertDatabaseHas('payment_document_estimate_splits', [
            'payment_document_id' => $document->id,
            'estimate_item_id' => $item->id,
            'amount' => 1000,
        ]);

        $item->refresh();

        $this->assertNull($item->actual_quantity);
        $this->assertNull($item->actual_unit_price);
        $this->assertSame('pending', $item->procurement_status);
    }

    public function test_estimate_item_paid_quantity_follows_registered_payment_amount(): void
    {
        $item = $this->createEstimateItem([
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-EST-002',
            'status' => PaymentDocumentStatus::SCHEDULED->value,
        ]);

        $this->service->registerPayment($document, 400, [
            'payment_method' => 'bank_transfer',
            'transaction_date' => now(),
        ]);

        $item->refresh();
        $document->refresh();

        $this->assertEquals(400.0, (float) $document->paid_amount);
        $this->assertEquals(600.0, (float) $document->remaining_amount);
        $this->assertSame(PaymentDocumentStatus::PARTIALLY_PAID, $document->status);
        $this->assertEquals(4.0, (float) $item->actual_quantity);
        $this->assertSame('ordered', $item->procurement_status);

        $this->service->registerPayment($document, 600, [
            'payment_method' => 'bank_transfer',
            'transaction_date' => now(),
        ]);

        $item->refresh();
        $document->refresh();

        $this->assertEquals(1000.0, (float) $document->paid_amount);
        $this->assertEquals(0.0, (float) $document->remaining_amount);
        $this->assertSame(PaymentDocumentStatus::PAID, $document->status);
        $this->assertEquals(10.0, (float) $item->actual_quantity);
        $this->assertEquals(100.0, (float) $item->actual_unit_price);
        $this->assertSame('paid', $item->procurement_status);
    }

    public function test_partial_payment_does_not_dispatch_paid_lifecycle_event(): void
    {
        $item = $this->createEstimateItem([
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-EST-006',
            'status' => PaymentDocumentStatus::SCHEDULED->value,
        ]);

        Event::fake([PaymentDocumentPaid::class]);

        $this->service->registerPayment($document, 400, [
            'payment_method' => 'bank_transfer',
            'transaction_date' => now(),
        ]);

        Event::assertNotDispatched(PaymentDocumentPaid::class);

        $this->service->registerPayment($document->fresh(), 600, [
            'payment_method' => 'bank_transfer',
            'transaction_date' => now(),
        ]);

        Event::assertDispatched(
            PaymentDocumentPaid::class,
            fn (PaymentDocumentPaid $event): bool => $event->document->id === $document->id
                && abs($event->amount - 1000.0) < 0.001
                && $event->transactionId !== null
        );
    }

    public function test_payment_paid_event_does_not_complete_material_site_request_without_delivery(): void
    {
        Event::fake([SiteRequestStatusChanged::class]);

        $user = User::factory()->create([
            'current_organization_id' => $this->organization->id,
        ]);

        $siteRequest = SiteRequest::query()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'user_id' => $user->id,
            'title' => 'Material request awaiting delivery',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::APPROVED->value,
            'priority' => 'medium',
            'material_name' => 'Concrete B25',
            'material_quantity' => 5,
            'material_unit' => 'm3',
        ]);

        $item = $this->createEstimateItem([
            'quantity' => 5,
            'unit_price' => 200,
            'total_amount' => 1000,
        ]);

        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-EST-007',
            'status' => PaymentDocumentStatus::PAID->value,
            'paid_amount' => 1000,
            'created_by_user_id' => $user->id,
        ]);

        $document->siteRequests()->attach($siteRequest->id, ['amount' => 1000]);

        app(CompleteSiteRequestsOnPaymentPaid::class)->handle(
            new PaymentDocumentPaid($document->fresh('siteRequests'), 1000, 1)
        );

        $this->assertSame(
            SiteRequestStatusEnum::APPROVED,
            $siteRequest->fresh()->status
        );
    }

    public function test_warehouse_receipt_from_payment_is_idempotent_per_estimate_split(): void
    {
        $material = $this->createMaterial();

        OrganizationWarehouse::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Main warehouse',
            'code' => 'WH-MAIN',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);

        $item = $this->createEstimateItem([
            'item_type' => EstimatePositionItemType::MATERIAL->value,
            'material_id' => $material->id,
            'measurement_unit_id' => $material->measurement_unit_id,
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        $document = $this->createDocumentWithSplit($item, [
            'document_number' => 'PAY-EST-008',
            'status' => PaymentDocumentStatus::PAID->value,
            'paid_amount' => 1000,
            'paid_at' => now(),
        ]);

        $service = app(WarehouseReceiptFromPaymentService::class);
        $service->createFromPaymentDocument($document);
        $service->createFromPaymentDocument($document->fresh());

        $this->assertSame(
            1,
            WarehouseMovement::query()
                ->where('movement_type', WarehouseMovement::TYPE_RECEIPT)
                ->where('document_number', $document->document_number)
                ->where('material_id', $material->id)
                ->count()
        );
    }

    public function test_documents_can_be_filtered_by_estimate_splits(): void
    {
        $targetItem = $this->createEstimateItem([
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        $otherEstimate = Estimate::query()->create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'number' => 'EST-002',
            'name' => 'Other estimate',
            'estimate_date' => now()->toDateString(),
        ]);

        $otherItem = EstimateItem::query()->create([
            'estimate_id' => $otherEstimate->id,
            'position_number' => '1',
            'name' => 'Other work',
            'quantity' => 5,
            'unit_price' => 100,
            'total_amount' => 500,
        ]);

        $targetDocument = $this->createDocumentWithSplit($targetItem, [
            'document_number' => 'PAY-EST-003',
            'status' => PaymentDocumentStatus::APPROVED->value,
        ]);

        $this->createDocumentWithSplit($otherItem, [
            'document_number' => 'PAY-EST-004',
            'status' => PaymentDocumentStatus::APPROVED->value,
            'amount' => 500,
            'estimate_splits' => [
                [
                    'estimate_item_id' => $otherItem->id,
                    'quantity' => 5,
                    'unit_price_actual' => 100,
                    'amount' => 500,
                    'percentage' => 100,
                ],
            ],
        ]);

        $documents = $this->service->getForOrganization($this->organization->id, [
            'estimate_id' => $this->estimate->id,
        ]);

        $this->assertCount(1, $documents);
        $this->assertSame($targetDocument->id, $documents->first()->id);
    }

    public function test_estimate_splits_require_contract_or_act_basis(): void
    {
        $item = $this->createEstimateItem([
            'quantity' => 10,
            'unit_price' => 100,
            'total_amount' => 1000,
        ]);

        try {
            $this->createDocumentWithSplit($item, [
                'document_number' => 'PAY-EST-005',
                'invoiceable_type' => null,
                'invoiceable_id' => null,
                'source_type' => null,
                'source_id' => null,
            ]);

            $this->fail('Payment document with estimate splits must require a contract or act basis.');
        } catch (\DomainException $exception) {
            $this->assertSame(
                trans_message('payments.validation.estimate_split_source_required'),
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing('payment_document_estimate_splits', [
            'estimate_item_id' => $item->id,
        ]);
    }

    private function createEstimateItem(array $overrides = []): EstimateItem
    {
        return EstimateItem::query()->create(array_merge([
            'estimate_id' => $this->estimate->id,
            'item_type' => EstimatePositionItemType::WORK->value,
            'position_number' => '1',
            'name' => 'Test work',
            'quantity' => 1,
            'unit_price' => 100,
            'total_amount' => 100,
        ], $overrides));
    }

    private function createMaterial(): Material
    {
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Pieces',
            'short_name' => 'pcs',
            'type' => 'material',
            'is_default' => true,
            'is_system' => false,
        ]);

        return Material::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Test material',
            'code' => 'MAT-TEST',
            'measurement_unit_id' => $unit->id,
            'default_price' => 100,
            'is_active' => true,
        ]);
    }

    private function createDocumentWithSplit(EstimateItem $item, array $overrides = []): PaymentDocument
    {
        return $this->service->create(array_merge([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'document_type' => PaymentDocumentType::INVOICE->value,
            'document_number' => 'PAY-EST',
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING->value,
            'invoice_type' => InvoiceType::ACT->value,
            'invoiceable_type' => \App\Models\ContractPerformanceAct::class,
            'invoiceable_id' => 1,
            'payer_organization_id' => $this->organization->id,
            'payee_organization_id' => $this->counterparty->id,
            'amount' => 1000,
            'currency' => 'RUB',
            'vat_rate' => 20,
            'estimate_splits' => [
                [
                    'estimate_item_id' => $item->id,
                    'quantity' => 10,
                    'unit_price_actual' => 100,
                    'amount' => 1000,
                    'percentage' => 100,
                ],
            ],
        ], $overrides));
    }
}
