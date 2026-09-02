<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class OrganizationOkpoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_owner_can_save_okpo_used_by_official_warehouse_forms(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');

        $response = $this->actingAs($context->user, 'api_landing')
            ->patchJson('/api/v1/landing/organization', [
                'okpo' => '12345678',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.okpo', '12345678');
        $this->assertDatabaseHas('organizations', [
            'id' => $context->organization->id,
            'okpo' => '12345678',
        ]);

        $this->actingAs($context->user, 'api_landing')
            ->patchJson('/api/v1/landing/organization', [
                'okpo' => '1234567890',
            ])
            ->assertOk()
            ->assertJsonPath('data.okpo', '1234567890');

        $this->actingAs($context->user, 'api_landing')
            ->patchJson('/api/v1/landing/organization', [
                'okpo' => '1234567',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['okpo']);
    }

    public function test_owner_can_save_okpo_when_unchanged_email_is_duplicated_by_legacy_data(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $email = $context->organization->email;

        Organization::factory()->create([
            'email' => $email,
        ]);

        $response = $this->actingAs($context->user, 'api_landing')
            ->patchJson('/api/v1/landing/organization', [
                'email' => $email,
                'okpo' => '12345678',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.okpo', '12345678');
        $this->assertDatabaseHas('organizations', [
            'id' => $context->organization->id,
            'email' => $email,
            'okpo' => '12345678',
        ]);

        $conflictingOrganization = Organization::factory()->create();

        $this->actingAs($context->user, 'api_landing')
            ->patchJson('/api/v1/landing/organization', [
                'email' => $conflictingOrganization->email,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
