<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Crm\Models\CrmLead;
use App\BusinessModules\Features\Crm\Services\CrmRegistryService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CrmLeadControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\EnablesImmutableAuditWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
        $indexingState = new AssistantIndexingState();
        $indexingState->beginMigration();
        $this->app->instance(AssistantIndexingState::class, $indexingState);
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess', 'canUserAccessModule')->andReturn(true);
        });
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface', 'can', 'canCurrent', 'hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static fn (User $user, ?AuthorizationContext $context = null) => $user->roleAssignments()
                    ->where('is_active', true)
                    ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                    ->get()
            );
        });
    }

    protected function tearDown(): void
    {
        $this->app->make(AssistantIndexingState::class)->endMigration();
        parent::tearDown();
    }

    public function test_converted_lead_rejects_status_changes_but_preserves_metadata_edits(): void
    {
        [$context, $lead] = $this->convertedLead();
        $dealId = $lead->converted_deal_id;
        $convertedAt = $lead->getRawOriginal('converted_at');

        foreach (['new', 'qualified', 'in_work', 'lost', null] as $status) {
            $this->withHeaders($context->authHeaders())->patchJson('/api/v1/admin/crm/leads/'.$lead->id, [
                'title' => 'Must not be saved', 'status' => $status,
            ])->assertUnprocessable()->assertJsonValidationErrors('status');
            self::assertSame('Converted workflow lead', $lead->fresh()->title);
            self::assertSame('converted', $lead->fresh()->status);
        }

        $this->withHeaders($context->authHeaders())->patchJson('/api/v1/admin/crm/leads/'.$lead->id, [
            'title' => 'Metadata edit', 'estimated_amount' => 0, 'need_description' => null,
        ])->assertOk()->assertJsonPath('data.title', 'Metadata edit')
            ->assertJsonPath('data.status', 'converted')->assertJsonPath('data.converted_deal_id', $dealId);
        self::assertSame($convertedAt, $lead->fresh()->getRawOriginal('converted_at'));
    }

    public function test_archive_restore_preserves_conversion_and_prevents_requalification_or_duplicate_deal(): void
    {
        [$context, $lead] = $this->convertedLead();
        $dealId = $lead->converted_deal_id;
        $convertedAt = $lead->getRawOriginal('converted_at');
        $dealCount = CrmDeal::query()->count();
        $this->withHeaders($context->authHeaders())->deleteJson('/api/v1/admin/crm/leads/'.$lead->id)
            ->assertOk()->assertJsonPath('data.is_archived', true);
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/crm/leads/'.$lead->id.'/restore')
            ->assertOk()->assertJsonPath('data.status', 'converted')
            ->assertJsonPath('data.is_archived', false)->assertJsonPath('data.converted_deal_id', $dealId);
        self::assertSame($convertedAt, $lead->fresh()->getRawOriginal('converted_at'));
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/crm/leads/'.$lead->id.'/qualify')
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/crm/leads/'.$lead->id.'/convert', $this->conversionPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('lead');
        self::assertSame($dealCount, CrmDeal::query()->count());
    }

    public function test_conversion_pointer_blocks_qualification_even_with_stale_status(): void
    {
        [$context, $lead] = $this->convertedLead();
        $lead->update(['status' => 'new']);
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/crm/leads/'.$lead->id.'/qualify')
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        self::assertSame('new', $lead->fresh()->status);
        self::assertNotNull($lead->fresh()->converted_deal_id);
        self::assertNotNull($lead->fresh()->converted_at);
    }

    public function test_unconverted_update_qualification_and_restore_keep_the_existing_workflow(): void
    {
        $context = AdminApiTestContext::create();
        $lead = CrmLead::query()->create(['organization_id' => $context->organization->id, 'title' => 'New lead']);
        $this->withHeaders($context->authHeaders())->patchJson('/api/v1/admin/crm/leads/'.$lead->id, [
            'title' => 'Edited lead', 'estimated_amount' => 0, 'priority' => 'high',
        ])->assertOk()->assertJsonPath('data.title', 'Edited lead')->assertJsonPath('data.status', 'new');
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/crm/leads/'.$lead->id.'/qualify')
            ->assertOk()->assertJsonPath('data.status', 'qualified');
        $this->withHeaders($context->authHeaders())->deleteJson('/api/v1/admin/crm/leads/'.$lead->id)->assertOk();
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/crm/leads/'.$lead->id.'/restore')
            ->assertOk()->assertJsonPath('data.status', 'new')->assertJsonPath('data.converted_deal_id', null);
        self::assertNull($lead->fresh()->converted_at);
    }

    public function test_conversion_status_cannot_be_assigned_without_the_conversion_workflow(): void
    {
        $context = AdminApiTestContext::create();
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/crm/leads', [
            'title' => 'False converted lead', 'status' => 'converted',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $lead = CrmLead::query()->create(['organization_id' => $context->organization->id, 'title' => 'Unconverted lead']);
        $this->withHeaders($context->authHeaders())->patchJson('/api/v1/admin/crm/leads/'.$lead->id, ['status' => 'converted'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        self::assertSame('new', $lead->fresh()->status);
        self::assertNull($lead->fresh()->converted_deal_id);
        self::assertNull($lead->fresh()->converted_at);
    }

    public function test_retained_conversion_timestamp_prevents_conversion_after_the_deal_pointer_is_missing(): void
    {
        [$context, $lead] = $this->convertedLead();
        $lead->update(['status' => 'new', 'converted_deal_id' => null]);
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/crm/leads/'.$lead->id.'/qualify')
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/crm/leads/'.$lead->id.'/convert', $this->conversionPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('lead');
        $this->withHeaders($context->authHeaders())->deleteJson('/api/v1/admin/crm/leads/'.$lead->id)->assertOk();
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/crm/leads/'.$lead->id.'/restore')
            ->assertOk()->assertJsonPath('data.status', 'converted');
        self::assertNotNull($lead->fresh()->converted_at);
    }

    public function test_restore_reloads_conversion_metadata_instead_of_trusting_a_stale_model(): void
    {
        $context = AdminApiTestContext::create();
        $lead = CrmLead::query()->create(['organization_id' => $context->organization->id, 'title' => 'Stale lead']);
        $stale = CrmLead::query()->findOrFail($lead->id);
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/crm/leads/'.$lead->id.'/convert', $this->conversionPayload())->assertOk();
        $this->withHeaders($context->authHeaders())->deleteJson('/api/v1/admin/crm/leads/'.$lead->id)->assertOk();
        $restored = $this->app->make(CrmRegistryService::class)->restore($stale, null);
        self::assertSame('converted', $restored->status);
        self::assertNotNull($restored->converted_deal_id);
        self::assertNotNull($restored->converted_at);
        self::assertFalse($restored->trashed());
    }

    public function test_lead_mutations_do_not_cross_organization_scope(): void
    {
        [$owner, $lead] = $this->convertedLead();
        $other = AdminApiTestContext::create();
        self::assertNotSame($owner->organization->id, $other->organization->id);
        $url = '/api/v1/admin/crm/leads/'.$lead->id;
        $this->withHeaders($other->authHeaders())->patchJson($url, ['title' => 'Foreign edit'])->assertNotFound();
        $this->withHeaders($other->authHeaders())->postJson($url.'/qualify')->assertNotFound();
        $this->withHeaders($other->authHeaders())->postJson($url.'/convert', $this->conversionPayload())->assertNotFound();
        $this->withHeaders($other->authHeaders())->deleteJson($url)->assertNotFound();
        $this->withHeaders($other->authHeaders())->postJson($url.'/restore')->assertNotFound();
        self::assertSame('Converted workflow lead', $lead->fresh()->title);
        self::assertSame('converted', $lead->fresh()->status);
    }

    private function convertedLead(): array
    {
        $context = AdminApiTestContext::create();
        $lead = CrmLead::query()->create([
            'organization_id' => $context->organization->id, 'title' => 'Converted workflow lead',
        ]);
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/crm/leads/'.$lead->id.'/convert', $this->conversionPayload())
            ->assertOk()->assertJsonPath('data.lead.status', 'converted');
        $lead->refresh();
        self::assertNotNull($lead->converted_deal_id);
        self::assertNotNull($lead->converted_at);

        return [$context, $lead];
    }

    private function conversionPayload(): array
    {
        return ['company' => ['name' => 'Teaching workflow company'], 'deal' => ['title' => 'Teaching workflow deal', 'amount' => 0]];
    }
}
