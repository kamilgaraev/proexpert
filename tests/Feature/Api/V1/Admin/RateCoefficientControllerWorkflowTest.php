<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Enums\RateCoefficient\RateCoefficientScopeEnum;
use App\Enums\RateCoefficient\RateCoefficientTypeEnum;
use App\Models\Organization;
use App\Models\RateCoefficient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class RateCoefficientControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_and_crud_are_scoped_to_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $current = $this->createCoefficient($context->organization->id, [
            'name' => 'Winter work coefficient',
            'code' => 'WINTER',
            'value' => 15,
            'type' => RateCoefficientTypeEnum::PERCENTAGE->value,
            'applies_to' => RateCoefficientAppliesToEnum::WORK_COSTS->value,
            'scope' => RateCoefficientScopeEnum::GLOBAL_ORG->value,
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreign = $this->createCoefficient($foreignOrganization->id, [
            'name' => 'Foreign winter coefficient',
            'code' => 'FOREIGN-WINTER',
            'value' => 25,
            'type' => RateCoefficientTypeEnum::PERCENTAGE->value,
            'applies_to' => RateCoefficientAppliesToEnum::WORK_COSTS->value,
            'scope' => RateCoefficientScopeEnum::GLOBAL_ORG->value,
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/rate-coefficients?name=Winter&type=percentage&sort_by=unknown&sort_direction=sideways&per_page=15&page=1');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $indexResponse->assertJsonPath('meta.total', 1);

        $ids = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($current->id, $ids);
        $this->assertNotContains($foreign->id, $ids);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/rate-coefficients', [
                'name' => 'Night shift addition',
                'code' => 'NIGHT',
                'value' => 1200,
                'type' => RateCoefficientTypeEnum::FIXED_ADDITION->value,
                'applies_to' => RateCoefficientAppliesToEnum::WORK_COSTS->value,
                'scope' => RateCoefficientScopeEnum::PROJECT->value,
                'conditions' => [
                    'project_ids' => [101],
                ],
                'organization_id' => $foreignOrganization->id,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.code', 'NIGHT');

        $createdId = $createResponse->json('data.id');

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/rate-coefficients/{$createdId}");
        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $createdId);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/rate-coefficients/{$createdId}", [
                'value' => 1500,
                'is_active' => false,
            ]);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.value', 1500);
        $updateResponse->assertJsonPath('data.is_active', false);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/rate-coefficients/{$foreign->id}");
        $foreignUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/rate-coefficients/{$foreign->id}", ['name' => 'Leaked update']);
        $foreignDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/rate-coefficients/{$foreign->id}");

        $foreignShowResponse->assertNotFound();
        $foreignUpdateResponse->assertNotFound();
        $foreignDeleteResponse->assertNotFound();

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/rate-coefficients/{$createdId}");
        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertSoftDeleted('rate_coefficients', ['id' => $createdId]);
    }

    public function test_codes_are_unique_only_inside_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();

        $this->createCoefficient($context->organization->id, ['code' => 'SHARED']);
        $this->createCoefficient($foreignOrganization->id, ['code' => 'FOREIGN-ONLY']);

        $duplicateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/rate-coefficients', [
                'name' => 'Duplicate coefficient',
                'code' => 'SHARED',
                'value' => 10,
                'type' => RateCoefficientTypeEnum::PERCENTAGE->value,
                'applies_to' => RateCoefficientAppliesToEnum::WORK_COSTS->value,
                'scope' => RateCoefficientScopeEnum::GLOBAL_ORG->value,
            ]);
        $allowedResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/rate-coefficients', [
                'name' => 'Allowed shared code',
                'code' => 'FOREIGN-ONLY',
                'value' => 12,
                'type' => RateCoefficientTypeEnum::PERCENTAGE->value,
                'applies_to' => RateCoefficientAppliesToEnum::WORK_COSTS->value,
                'scope' => RateCoefficientScopeEnum::GLOBAL_ORG->value,
            ]);

        $duplicateResponse->assertStatus(422);
        $duplicateResponse->assertJsonPath('success', false);
        $allowedResponse->assertCreated();
        $allowedResponse->assertJsonPath('success', true);
    }

    public function test_apply_calculates_active_coefficients_for_requested_context_only(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();

        $this->createCoefficient($context->organization->id, [
            'name' => 'General markup',
            'code' => 'GENERAL',
            'value' => 10,
            'type' => RateCoefficientTypeEnum::PERCENTAGE->value,
            'applies_to' => RateCoefficientAppliesToEnum::WORK_COSTS->value,
            'scope' => RateCoefficientScopeEnum::GLOBAL_ORG->value,
        ]);
        $this->createCoefficient($context->organization->id, [
            'name' => 'Project addition',
            'code' => 'PROJECT-101',
            'value' => 50,
            'type' => RateCoefficientTypeEnum::FIXED_ADDITION->value,
            'applies_to' => RateCoefficientAppliesToEnum::WORK_COSTS->value,
            'scope' => RateCoefficientScopeEnum::PROJECT->value,
            'conditions' => [
                'project_ids' => [101],
            ],
        ]);
        $this->createCoefficient($context->organization->id, [
            'name' => 'Inactive coefficient',
            'code' => 'INACTIVE',
            'value' => 90,
            'type' => RateCoefficientTypeEnum::PERCENTAGE->value,
            'applies_to' => RateCoefficientAppliesToEnum::WORK_COSTS->value,
            'scope' => RateCoefficientScopeEnum::GLOBAL_ORG->value,
            'is_active' => false,
        ]);
        $this->createCoefficient($foreignOrganization->id, [
            'name' => 'Foreign coefficient',
            'code' => 'FOREIGN',
            'value' => 99,
            'type' => RateCoefficientTypeEnum::PERCENTAGE->value,
            'applies_to' => RateCoefficientAppliesToEnum::WORK_COSTS->value,
            'scope' => RateCoefficientScopeEnum::GLOBAL_ORG->value,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/rate-coefficients/apply', [
                'original_value' => 1000,
                'applies_to' => RateCoefficientAppliesToEnum::WORK_COSTS->value,
                'contextual_ids' => [
                    'project_id' => 101,
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.original', 1000);
        $response->assertJsonPath('data.final', 1155);

        $applications = collect($response->json('data.applications'));
        $this->assertSame(['Project addition', 'General markup'], $applications->pluck('name')->all());
    }

    private function createCoefficient(int $organizationId, array $overrides = []): RateCoefficient
    {
        return RateCoefficient::query()->create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Rate coefficient',
            'code' => 'RATE-'.uniqid(),
            'value' => 5,
            'type' => RateCoefficientTypeEnum::PERCENTAGE->value,
            'applies_to' => RateCoefficientAppliesToEnum::WORK_COSTS->value,
            'scope' => RateCoefficientScopeEnum::GLOBAL_ORG->value,
            'description' => null,
            'is_active' => true,
            'valid_from' => null,
            'valid_to' => null,
            'conditions' => null,
        ], $overrides));
    }
}
