<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Services\SupplierRequestService;
use App\Models\Organization;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSupplierRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_supplier_can_open_link_submit_proposal_once_and_request_becomes_responded(): void
    {
        $organization = Organization::factory()->create(['name' => 'Buyer Org']);
        $supplier = $this->createSupplier($organization->id);
        $purchaseRequest = $this->createPurchaseRequest($organization->id);
        $supplierRequest = app(SupplierRequestService::class)->create($organization->id, [
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'comment' => 'Please submit your offer.',
        ]);
        $supplierRequest = app(SupplierRequestService::class)->send($supplierRequest);
        $line = $supplierRequest->lines()->firstOrFail();

        $showResponse = $this->getJson("/api/v1/procurement/supplier-requests/{$supplierRequest->public_token}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('success', true);
        $showResponse->assertJsonPath('data.request_number', $supplierRequest->request_number);
        $showResponse->assertJsonPath('data.can_submit', true);
        $showResponse->assertJsonPath('data.lines.0.id', $line->id);
        $this->assertNotNull($supplierRequest->fresh()->public_opened_at);

        $submitResponse = $this->postJson(
            "/api/v1/procurement/supplier-requests/{$supplierRequest->public_token}/proposals",
            $this->proposalPayload($line->id)
        );

        $submitResponse->assertCreated();
        $submitResponse->assertJsonPath('success', true);
        $submitResponse->assertJsonPath('data.status', SupplierProposalStatusEnum::SUBMITTED->value);

        $proposal = SupplierProposal::query()
            ->where('supplier_request_id', $supplierRequest->id)
            ->firstOrFail();
        $proposal->load('intake', 'lines');
        $this->assertSame($supplierRequest->id, $proposal->supplier_request_id);
        $this->assertSame($supplier->id, $proposal->supplier_id);
        $this->assertSame(['public_supplier_response' => true], $proposal->metadata);
        $this->assertSame('other', $proposal->intake?->source->value);
        $this->assertSame($line->id, $proposal->lines->first()?->supplier_request_line_id);
        $this->assertEqualsWithDelta(91.67, (float) $proposal->vat_amount, 0.01);
        $this->assertSame(SupplierRequestStatusEnum::RESPONDED, $supplierRequest->fresh()->status);

        $secondSubmitResponse = $this->postJson(
            "/api/v1/procurement/supplier-requests/{$supplierRequest->public_token}/proposals",
            $this->proposalPayload($line->id)
        );

        $secondSubmitResponse->assertStatus(409);
        $this->assertSame(1, SupplierProposal::query()->where('supplier_request_id', $supplierRequest->id)->count());
    }

    public function test_external_public_supplier_can_submit_proposal(): void
    {
        $organization = Organization::factory()->create(['name' => 'Buyer Org']);
        $otherOrganization = Organization::factory()->create(['name' => 'Other Buyer Org']);
        SupplierProposal::query()->create([
            'organization_id' => $otherOrganization->id,
            'proposal_number' => 'КП-'.now()->format('Ym').'-0001',
            'proposal_date' => now()->toDateString(),
            'status' => SupplierProposalStatusEnum::SUBMITTED,
            'subtotal_amount' => 100,
            'delivery_amount' => 0,
            'vat_amount' => 0,
            'total_amount' => 100,
            'currency' => 'RUB',
            'vat_mode' => 'included',
        ]);

        $purchaseRequest = $this->createPurchaseRequest($organization->id);
        $supplierRequest = app(SupplierRequestService::class)->create($organization->id, [
            'purchase_request_id' => $purchaseRequest->id,
            'external_supplier' => [
                'name' => 'External Supplier',
                'contact_person' => 'External Owner',
                'phone' => '+7 900 000-00-00',
                'email' => 'external-supplier@example.test',
                'tax_number' => '9901000003',
            ],
            'comment' => 'Please submit your offer.',
        ]);
        $supplierRequest = app(SupplierRequestService::class)->send($supplierRequest);
        $line = $supplierRequest->lines()->firstOrFail();

        $submitResponse = $this->postJson(
            "/api/v1/procurement/supplier-requests/{$supplierRequest->public_token}/proposals",
            $this->publicFormProposalPayload($line->id)
        );

        $submitResponse->assertCreated();
        $submitResponse->assertJsonPath('success', true);
        $submitResponse->assertJsonPath('data.status', SupplierProposalStatusEnum::SUBMITTED->value);

        $proposal = SupplierProposal::query()
            ->where('supplier_request_id', $supplierRequest->id)
            ->firstOrFail();
        $this->assertSame('КП-'.now()->format('Ym').'-0002', $proposal->proposal_number);
        $this->assertNull($proposal->supplier_id);
        $this->assertSame($supplierRequest->external_supplier_contact_id, $proposal->external_supplier_contact_id);
        $this->assertSame($supplierRequest->supplier_party_id, $proposal->supplier_party_id);
        $this->assertSame(SupplierRequestStatusEnum::RESPONDED, $supplierRequest->fresh()->status);
    }

    public function test_public_submission_rejects_expired_token_and_foreign_supplier_request_line_without_mutation(): void
    {
        $organization = Organization::factory()->create();
        $supplier = $this->createSupplier($organization->id);
        $purchaseRequest = $this->createPurchaseRequest($organization->id);
        $otherPurchaseRequest = $this->createPurchaseRequest($organization->id);
        $supplierRequest = app(SupplierRequestService::class)->send(
            app(SupplierRequestService::class)->create($organization->id, [
                'purchase_request_id' => $purchaseRequest->id,
                'supplier_id' => $supplier->id,
            ])
        );
        $otherSupplierRequest = app(SupplierRequestService::class)->send(
            app(SupplierRequestService::class)->create($organization->id, [
                'purchase_request_id' => $otherPurchaseRequest->id,
                'supplier_id' => $this->createSupplier($organization->id, 'other@example.test')->id,
            ])
        );
        $foreignLine = $otherSupplierRequest->lines()->firstOrFail();

        $foreignLineResponse = $this->postJson(
            "/api/v1/procurement/supplier-requests/{$supplierRequest->public_token}/proposals",
            $this->proposalPayload($foreignLine->id)
        );

        $foreignLineResponse->assertStatus(422);
        $this->assertSame(0, SupplierProposal::query()->count());
        $this->assertSame(SupplierRequestStatusEnum::SENT, $supplierRequest->fresh()->status);

        $supplierRequest->update(['public_token_expires_at' => now()->subMinute()]);

        $expiredShowResponse = $this->getJson("/api/v1/procurement/supplier-requests/{$supplierRequest->public_token}");
        $expiredSubmitResponse = $this->postJson(
            "/api/v1/procurement/supplier-requests/{$supplierRequest->public_token}/proposals",
            $this->proposalPayload($supplierRequest->lines()->firstOrFail()->id)
        );

        $expiredShowResponse->assertStatus(410);
        $expiredSubmitResponse->assertStatus(410);
        $this->assertSame(SupplierRequestStatusEnum::EXPIRED, $supplierRequest->fresh()->status);
        $this->assertSame(0, SupplierProposal::query()->count());

        $this->getJson('/api/v1/procurement/supplier-requests/short-token')->assertNotFound();
    }

    private function proposalPayload(int $supplierRequestLineId, array $overrides = []): array
    {
        $payload = [
            'proposal_date' => now()->toDateString(),
            'subtotal_amount' => 500,
            'delivery_amount' => 50,
            'vat_amount' => 0,
            'total_amount' => 550,
            'currency' => 'RUB',
            'vat_mode' => 'included',
            'vat_rate' => 20,
            'valid_until' => now()->addDays(7)->toDateString(),
            'delivery_due_date' => now()->addDays(3)->toDateString(),
            'payment_terms' => 'Payment after delivery',
            'delivery_terms' => 'Delivery to warehouse',
            'items' => [
                [
                    'supplier_request_line_id' => $supplierRequestLineId,
                    'name' => 'Concrete B25',
                    'quantity' => 10,
                    'unit' => 'm3',
                    'unit_price' => 50,
                    'total_amount' => 500,
                ],
            ],
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($payload[$key]);

                continue;
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    private function publicFormProposalPayload(int $supplierRequestLineId): array
    {
        return $this->proposalPayload($supplierRequestLineId, [
            'proposal_date' => null,
            'delivery_amount' => 500,
            'lead_time_days' => 3,
            'delivery_due_date' => now()->addDays(3)->toDateString(),
            'payment_terms' => 'Оплата после поставки',
            'delivery_terms' => 'Доставка на объект',
            'warranty_terms' => 'Гарантия поставщика',
            'notes' => 'Ответ из публичной формы',
            'items' => [
                [
                    'supplier_request_line_id' => $supplierRequestLineId,
                    'name' => 'Concrete B25',
                    'quantity' => 10,
                    'unit' => 'm3',
                    'unit_price' => 1100,
                    'total_amount' => 11000,
                    'comment' => 'Позиция из публичной формы',
                ],
            ],
            'subtotal_amount' => 11000,
            'total_amount' => 11500,
        ]);
    }

    private function createPurchaseRequest(int $organizationId): PurchaseRequest
    {
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organizationId,
            'request_number' => 'PR-PUBLIC-'.uniqid(),
            'status' => 'approved',
        ]);

        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'name' => 'Concrete B25',
            'quantity' => 10,
            'unit' => 'm3',
        ]);

        return $purchaseRequest;
    }

    private function createSupplier(int $organizationId, string $email = 'supplier@example.test'): Supplier
    {
        return Supplier::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Supplier '.uniqid(),
            'email' => $email,
            'is_active' => true,
        ]);
    }
}
