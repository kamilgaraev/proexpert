<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Services\SupplierRequestService;
use App\Models\Organization;
use App\Models\Supplier;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupplierRequestServiceTest extends TestCase
{
    public function test_supplier_request_is_created_without_buyer_total_for_registered_supplier(): void
    {
        $organization = Organization::factory()->create();
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'name' => 'ООО Металл Снаб',
            'is_active' => true,
        ]);

        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'request_number' => 'PR-202605-0001',
            'status' => 'approved',
        ]);

        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'name' => 'Арматура А500С 12 мм',
            'quantity' => 120.5,
            'unit' => 'м',
        ]);

        $supplierRequest = app(SupplierRequestService::class)->create($organization->id, [
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'comment' => 'Нужны коммерческие условия по поставке.',
        ]);

        $this->assertSame($purchaseRequest->id, $supplierRequest->purchase_request_id);
        $this->assertSame($supplier->id, $supplierRequest->supplier_id);
        $this->assertNull($supplierRequest->external_supplier_contact_id);
        $this->assertSame('draft', $supplierRequest->status->value);
        $this->assertCount(1, $supplierRequest->lines);
        $this->assertArrayNotHasKey('total_amount', $supplierRequest->getAttributes());
    }

    public function test_supplier_request_can_be_created_for_external_supplier_contact(): void
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'request_number' => 'PR-202605-0002',
            'status' => 'approved',
        ]);

        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'name' => 'Бетон B25',
            'quantity' => 18,
            'unit' => 'м3',
        ]);

        $supplierRequest = app(SupplierRequestService::class)->create($organization->id, [
            'purchase_request_id' => $purchaseRequest->id,
            'external_supplier' => [
                'name' => 'ИП Петров',
                'email' => 'petrov@example.com',
                'phone' => '+7 900 100-20-30',
            ],
        ]);

        $this->assertNull($supplierRequest->supplier_id);
        $this->assertNotNull($supplierRequest->external_supplier_contact_id);
        $this->assertSame('ИП Петров', $supplierRequest->externalSupplierContact->name);
        $this->assertSame('petrov@example.com', $supplierRequest->externalSupplierContact->email);
        $this->assertCount(1, $supplierRequest->lines);
    }

    public function test_send_queues_public_link_email_for_registered_supplier(): void
    {
        Mail::fake();
        config()->set('app.frontend_url', 'https://lk.test');

        $organization = Organization::factory()->create(['name' => 'Buyer Org']);
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Registered Supplier',
            'email' => 'registered-supplier@example.test',
            'is_active' => true,
        ]);
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'request_number' => 'PR-202605-0003',
            'status' => 'approved',
        ]);
        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'name' => 'Concrete B25',
            'quantity' => 12,
            'unit' => 'm3',
        ]);

        $supplierRequest = app(SupplierRequestService::class)->create($organization->id, [
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplier->id,
        ]);
        $sentSupplierRequest = app(SupplierRequestService::class)->send($supplierRequest);
        $publicUrl = $sentSupplierRequest->publicUrl();

        $this->assertNotNull($publicUrl);
        Mail::assertQueued(function (Mailable $mail) use ($supplier, $publicUrl, $sentSupplierRequest): bool {
            $html = $mail->render();

            return $mail->hasTo($supplier->email)
                && str_contains($html, $publicUrl)
                && str_contains($html, $sentSupplierRequest->request_number);
        });
        Mail::assertQueuedCount(1);
    }

    public function test_send_queues_public_link_email_for_external_supplier_contact(): void
    {
        Mail::fake();
        config()->set('app.frontend_url', 'https://lk.test');

        $organization = Organization::factory()->create(['name' => 'Buyer Org']);
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'request_number' => 'PR-202605-0004',
            'status' => 'approved',
        ]);
        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'name' => 'Rebar A500',
            'quantity' => 40,
            'unit' => 'm',
        ]);

        $supplierRequest = app(SupplierRequestService::class)->create($organization->id, [
            'purchase_request_id' => $purchaseRequest->id,
            'external_supplier' => [
                'name' => 'External Supplier',
                'email' => 'external-supplier@example.test',
            ],
        ]);
        $sentSupplierRequest = app(SupplierRequestService::class)->send($supplierRequest);
        $publicUrl = $sentSupplierRequest->publicUrl();

        $this->assertNotNull($publicUrl);
        Mail::assertQueued(function (Mailable $mail) use ($publicUrl, $sentSupplierRequest): bool {
            $html = $mail->render();

            return $mail->hasTo('external-supplier@example.test')
                && str_contains($html, $publicUrl)
                && str_contains($html, $sentSupplierRequest->request_number);
        });
        Mail::assertQueuedCount(1);
    }
}
