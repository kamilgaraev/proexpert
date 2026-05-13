<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\OneCExchangeMapping;
use App\Models\OneCExchangeRun;
use App\Models\OneCExchangeToken;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class OneCExchangeControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_exchange_workflow_is_scoped_and_does_not_expose_token_hashes(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $module = $this->createOneCModule();
        $this->activateModule($context->organization->id, $module->id);
        $this->activateModule($foreignContext->organization->id, $module->id);

        OneCExchangeToken::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'label' => 'Foreign exchange',
            'token_hash' => hash('sha256', 'foreign-token'),
        ]);
        OneCExchangeMapping::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'scope' => 'materials',
            'external_id' => 'foreign-material',
            'external_name' => 'Foreign material',
            'local_type' => 'materials',
            'local_id' => 999,
        ]);

        $statusResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/status');

        $statusResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.tokens_count', 0);

        $createTokenResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/tokens', [
                'label' => 'Main 1C exchange',
            ]);

        $createTokenResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token.label', 'Main 1C exchange');
        $plainToken = (string) $createTokenResponse->json('data.plain_token');
        $this->assertStringStartsWith('ph_1c_', $plainToken);
        $this->assertArrayNotHasKey('token_hash', $createTokenResponse->json('data.token'));

        $tokensResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/tokens');

        $tokensResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'Main 1C exchange');
        $this->assertArrayNotHasKey('token_hash', $tokensResponse->json('data.0'));

        $mappingResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/mappings', [
                'scope' => 'materials',
                'external_id' => '1c-material-42',
                'external_name' => 'Concrete M350',
                'local_type' => 'materials',
                'local_id' => 42,
                'payload' => ['unit' => 'm3'],
            ]);

        $mappingResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.external_id', '1c-material-42')
            ->assertJsonPath('data.payload.unit', 'm3');

        $mappingsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/mappings?scope=materials');

        $mappingsResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', '1c-material-42');

        $importResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/import', [
                'scope' => 'materials',
                'items' => [
                    ['external_id' => '1c-material-42'],
                    ['external_id' => '1c-material-43'],
                ],
                'dry_run' => true,
            ]);

        $importResponse->assertOk()
            ->assertJsonPath('data.direction', 'import')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_count', 2)
            ->assertJsonPath('data.created_count', 2)
            ->assertJsonPath('data.summary.dry_run', true);

        $exportResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/export', [
                'scope' => 'materials',
                'filters' => ['changed_since' => '2026-05-01'],
            ]);

        $exportResponse->assertOk()
            ->assertJsonPath('data.direction', 'export')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_count', 0);

        $historyResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/history?per_page=1');

        $historyResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2);
        $this->assertSame(
            2,
            OneCExchangeRun::query()->where('organization_id', $context->organization->id)->count()
        );
        $this->assertSame(
            0,
            OneCExchangeRun::query()->where('organization_id', $foreignContext->organization->id)->count()
        );
    }

    public function test_admin_without_one_c_permissions_cannot_use_exchange_routes(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'admin_viewer');
        $module = $this->createOneCModule();
        $this->activateModule($context->organization->id, $module->id);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/status')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/tokens', [
                'label' => 'Forbidden token',
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('one_c_exchange_tokens', [
            'organization_id' => $context->organization->id,
            'label' => 'Forbidden token',
        ]);
    }

    private function createOneCModule(): Module
    {
        return Module::query()->create([
            'name' => '1C Basic Exchange',
            'slug' => 'one-c-basic-exchange',
            'version' => '1.0.0',
            'type' => 'addon',
            'billing_model' => 'free',
            'category' => 'integrations',
            'is_active' => true,
            'is_system_module' => false,
            'can_deactivate' => true,
            'permissions' => [
                'one_c_exchange.view',
                'one_c_exchange.manage_tokens',
                'one_c_exchange.manage_mappings',
                'one_c_exchange.import',
                'one_c_exchange.export',
                'one_c_exchange.history.view',
            ],
        ]);
    }

    private function activateModule(int $organizationId, int $moduleId): void
    {
        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $moduleId,
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }
}
