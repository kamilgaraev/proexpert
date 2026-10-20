<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\Crm\Models\CrmActivity;
use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CrmActivityControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\EnablesImmutableAuditWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
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

    public function test_deal_filter_scopes_active_and_archived_pages_without_other_deals_or_organizations(): void
    {
        [$context, $deal, $first, $second, $archived] = $this->activityFixture();
        $base = '/api/v1/admin/crm/activities?'.http_build_query([
            'deal_id' => $deal->id,
            'per_page' => 1,
            'sort_by' => 'due_at',
            'sort_dir' => 'asc',
            'archived' => 'false',
        ]);

        $this->withHeaders($context->authHeaders())->getJson($base.'&page=1')
            ->assertOk()->assertJsonPath('success', true)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.0.deal_id', $deal->id)
            ->assertJsonPath('data.0.is_archived', false)->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.last_page', 2);

        $this->withHeaders($context->authHeaders())->getJson($base.'&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 2);

        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/crm/activities?'.http_build_query([
            'deal_id' => $deal->id, 'archived' => 'true', 'per_page' => 20,
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $archived->id)
            ->assertJsonPath('data.0.is_archived', true)->assertJsonPath('meta.total', 1);
    }

    public function test_unknown_or_foreign_deal_filter_returns_empty_instead_of_unfiltered_activities(): void
    {
        [$context, , , , , $foreignDeal] = $this->activityFixture();
        foreach ([(string) Str::uuid(), $foreignDeal->id] as $dealId) {
            $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/crm/activities?'.http_build_query([
                'deal_id' => $dealId, 'archived' => 'false',
            ]))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        }
    }

    public function test_invalid_deal_identifier_is_rejected(): void
    {
        $context = AdminApiTestContext::create();
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/crm/activities?deal_id=not-a-uuid')
            ->assertUnprocessable()->assertJsonValidationErrors('deal_id');
    }

    public function test_omitted_deal_filter_preserves_organization_list_and_excludes_archive(): void
    {
        [$context] = $this->activityFixture();
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/crm/activities?archived=false')
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
    }

    private function activityFixture(): array
    {
        $context = AdminApiTestContext::create();
        $deal = $this->deal((int) $context->organization->id, 'Target deal');
        $other = $this->deal((int) $context->organization->id, 'Other deal');
        $foreign = $this->deal((int) Organization::factory()->verified()->create()->id, 'Foreign deal');
        $first = $this->activity($deal, 'Target first', '2026-11-01 09:00:00');
        $second = $this->activity($deal, 'Target second', '2026-11-02 09:00:00');
        $archived = $this->activity($deal, 'Target archived', '2026-11-03 09:00:00');
        $archived->update(['status' => 'archived']);
        $archived->delete();
        $this->activity($other, 'Other active', '2026-10-01 09:00:00');
        $otherArchived = $this->activity($other, 'Other archived', '2026-10-02 09:00:00');
        $otherArchived->update(['status' => 'archived']);
        $otherArchived->delete();
        $this->activity($foreign, 'Foreign active', '2026-10-03 09:00:00');

        return [$context, $deal, $first, $second, $archived, $foreign];
    }

    private function deal(int $organizationId, string $title): CrmDeal
    {
        $company = CrmCompany::query()->create([
            'organization_id' => $organizationId, 'name' => $title.' company', 'company_type' => 'legal_entity',
            'status' => 'active', 'roles' => [], 'tags' => [], 'custom_fields' => [],
        ]);

        return CrmDeal::query()->create([
            'organization_id' => $organizationId, 'company_id' => $company->id, 'title' => $title,
            'pipeline_code' => 'default', 'stage_code' => 'new', 'status' => 'open', 'currency' => 'RUB', 'custom_fields' => [],
        ]);
    }

    private function activity(CrmDeal $deal, string $subject, string $dueAt): CrmActivity
    {
        return CrmActivity::query()->create([
            'organization_id' => $deal->organization_id, 'deal_id' => $deal->id, 'type' => 'note',
            'subject' => $subject, 'status' => 'planned', 'due_at' => $dueAt,
        ]);
    }
}
