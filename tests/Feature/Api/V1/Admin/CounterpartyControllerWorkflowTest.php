<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Counterparty;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class CounterpartyControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_counterparty_registry_is_scoped_searchable_and_protects_used_records(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignCounterparty = $this->createCounterparty($foreignContext->organization->id, [
            'name' => 'Foreign Customer',
            'inn' => '7701000001',
            'kpp' => '770101001',
        ]);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/counterparties', [
                'name' => 'Northern Customer',
                'legal_name' => 'Northern Customer LLC',
                'inn' => '7701000001',
                'kpp' => '770101001',
                'email' => 'customer@example.test',
                'roles' => ['customer'],
                'bank_details' => [
                    'bank_name' => 'Test Bank',
                    'account' => '40702810000000000001',
                ],
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.organization_id', $context->organization->id);
        $createResponse->assertJsonPath('data.name', 'Northern Customer');
        $createResponse->assertJsonPath('data.roles.0', 'customer');

        $counterpartyId = (int) $createResponse->json('data.id');

        $searchResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/counterparties/search?role=customer&q=Northern&limit=10');

        $searchResponse->assertOk();
        $searchResponse->assertJsonPath('success', true);
        $this->assertSame([$counterpartyId], collect($searchResponse->json('data'))->pluck('id')->all());

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/counterparties?name=Customer&per_page=20&sort_by=unknown&sort_direction=bad');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $this->assertSame([$counterpartyId], collect($indexResponse->json('data'))->pluck('id')->all());
        $indexResponse->assertJsonPath('meta.total', 1);

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/counterparties/{$counterpartyId}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $counterpartyId);
        $showResponse->assertJsonPath('data.bank_details.bank_name', 'Test Bank');

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/counterparties/{$counterpartyId}", [
                'name' => 'Northern Customer Updated',
                'roles' => ['customer', 'supplier'],
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.name', 'Northern Customer Updated');
        $this->assertSame(['customer', 'supplier'], $updateResponse->json('data.roles'));
        $this->assertDatabaseHas('counterparties', [
            'id' => $counterpartyId,
            'email' => 'customer@example.test',
        ]);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/counterparties/{$foreignCounterparty->id}");
        $foreignUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/counterparties/{$foreignCounterparty->id}", ['name' => 'Leaked update']);
        $foreignDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/counterparties/{$foreignCounterparty->id}");

        $foreignShowResponse->assertNotFound();
        $foreignUpdateResponse->assertNotFound();
        $foreignDeleteResponse->assertNotFound();

        Project::factory()->create([
            'organization_id' => $context->organization->id,
            'customer_counterparty_id' => $counterpartyId,
        ]);

        $blockedDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/counterparties/{$counterpartyId}");

        $blockedDeleteResponse->assertStatus(422);
        $blockedDeleteResponse->assertJsonPath('success', false);
        $this->assertDatabaseHas('counterparties', [
            'id' => $counterpartyId,
            'deleted_at' => null,
        ]);

        $unusedCounterparty = $this->createCounterparty($context->organization->id, [
            'name' => 'Unused Customer',
            'inn' => '7702000001',
            'kpp' => '770201001',
        ]);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/counterparties/{$unusedCounterparty->id}");

        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertSoftDeleted('counterparties', ['id' => $unusedCounterparty->id]);
    }

    public function test_inn_and_kpp_pair_is_unique_only_inside_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();

        $this->createCounterparty($context->organization->id, [
            'name' => 'Duplicate Customer',
            'inn' => '7710000001',
            'kpp' => '771001001',
        ]);

        $this->createCounterparty($foreignContext->organization->id, [
            'name' => 'Foreign Shared Customer',
            'inn' => '7720000001',
            'kpp' => '772001001',
        ]);

        $duplicateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/counterparties', [
                'name' => 'Duplicate Customer Copy',
                'inn' => '7710000001',
                'kpp' => '771001001',
                'roles' => ['customer'],
            ]);

        $duplicateResponse->assertStatus(422);
        $duplicateResponse->assertJsonPath('success', false);

        $allowedResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/counterparties', [
                'name' => 'Foreign Shared Customer',
                'inn' => '7720000001',
                'kpp' => '772001001',
                'roles' => ['customer'],
            ]);

        $allowedResponse->assertCreated();
        $allowedResponse->assertJsonPath('success', true);
        $allowedResponse->assertJsonPath('data.organization_id', $context->organization->id);
    }

    private function createCounterparty(int $organizationId, array $overrides = []): Counterparty
    {
        return Counterparty::query()->create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Customer',
            'legal_name' => null,
            'inn' => null,
            'kpp' => null,
            'ogrn' => null,
            'email' => null,
            'phone' => null,
            'contact_person' => null,
            'legal_address' => null,
            'postal_address' => null,
            'bank_details' => null,
            'roles' => ['customer'],
            'source' => 'manual',
            'is_active' => true,
        ], $overrides));
    }
}
