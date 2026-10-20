<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\BusinessModules\Features\Crm\Models\CrmActivity;
use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Crm\Services\CrmRegistryService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_creating_later_or_undated_activity_keeps_the_earliest_pending_deadline(): void
    {
        $context = AdminApiTestContext::create();
        $deal = $this->deal((int) $context->organization->id, 'Next activity');
        $this->postActivity($context, $deal, 'Earliest', '2026-11-01T09:00:00Z');
        $this->postActivity($context, $deal, 'Later', '2026-11-03T09:00:00Z');
        $this->assertNextDate($deal, '2026-11-01 09:00:00');
        $this->postActivity($context, $deal, 'Without deadline', null);
        $this->assertNextDate($deal, '2026-11-01 09:00:00');
    }

    public function test_editing_or_clearing_one_deadline_reselects_another_pending_activity(): void
    {
        $context = AdminApiTestContext::create();
        $deal = $this->deal((int) $context->organization->id, 'Deadline changes');
        $later = $this->postActivity($context, $deal, 'Later', '2026-11-03T09:00:00Z');
        $first = $this->postActivity($context, $deal, 'First', '2026-11-01T09:00:00Z');
        $this->withHeaders($context->authHeaders())->patchJson("/api/v1/admin/crm/activities/{$first}", ['due_at' => '2026-11-05T09:00:00Z'])->assertOk();
        $this->assertNextDate($deal, '2026-11-03 09:00:00');
        $this->withHeaders($context->authHeaders())->patchJson("/api/v1/admin/crm/activities/{$later}", ['due_at' => null])->assertOk();
        $this->assertNextDate($deal, '2026-11-05 09:00:00');
        $this->withHeaders($context->authHeaders())->patchJson("/api/v1/admin/crm/activities/{$first}", ['status' => 'overdue'])->assertOk();
        $this->assertNextDate($deal, '2026-11-05 09:00:00');
    }

    public function test_complete_cancel_archive_and_restore_refresh_the_next_date(): void
    {
        $context = AdminApiTestContext::create();
        $deal = $this->deal((int) $context->organization->id, 'Lifecycle');
        $later = $this->postActivity($context, $deal, 'Later', '2026-11-03T09:00:00Z');
        $first = $this->postActivity($context, $deal, 'First', '2026-11-01T09:00:00Z');
        $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/crm/activities/{$first}/complete", ['result' => 'Teaching result'])->assertOk();
        $this->assertNextDate($deal, '2026-11-03 09:00:00');
        $this->withHeaders($context->authHeaders())->patchJson("/api/v1/admin/crm/activities/{$later}", ['status' => 'cancelled'])->assertOk();
        $this->assertNextDate($deal, null);
        $this->withHeaders($context->authHeaders())->deleteJson("/api/v1/admin/crm/activities/{$first}")->assertOk();
        $this->assertNextDate($deal, null);
        $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/crm/activities/{$first}/restore")
            ->assertOk()->assertJsonPath('data.status', 'planned')->assertJsonPath('data.result', 'Teaching result');
        $this->assertNextDate($deal, '2026-11-01 09:00:00');
        $this->withHeaders($context->authHeaders())->deleteJson("/api/v1/admin/crm/activities/{$first}")->assertOk();
        $this->assertNextDate($deal, null);
    }

    public function test_moving_activity_recalculates_both_deals_and_keeps_foreign_organization_unchanged(): void
    {
        $context = AdminApiTestContext::create();
        $source = $this->deal((int) $context->organization->id, 'Source');
        $target = $this->deal((int) $context->organization->id, 'Target');
        $foreign = $this->deal((int) Organization::factory()->verified()->create()->id, 'Foreign');
        $foreign->update(['next_activity_at' => '2026-12-01 09:00:00']);
        $this->postActivity($context, $source, 'Remaining', '2026-11-03T09:00:00Z');
        $moving = $this->postActivity($context, $source, 'Moving', '2026-11-01T09:00:00Z');
        $this->postActivity($context, $target, 'Target later', '2026-11-05T09:00:00Z');
        $this->withHeaders($context->authHeaders())->patchJson("/api/v1/admin/crm/activities/{$moving}", ['deal_id' => $target->id])->assertOk();
        $this->assertNextDate($source, '2026-11-03 09:00:00');
        $this->assertNextDate($target, '2026-11-01 09:00:00');
        $this->withHeaders($context->authHeaders())->patchJson("/api/v1/admin/crm/activities/{$moving}", ['deal_id' => null])->assertOk();
        $this->assertNextDate($target, '2026-11-05 09:00:00');
        $this->withHeaders($context->authHeaders())->patchJson("/api/v1/admin/crm/activities/{$moving}", ['deal_id' => $foreign->id])->assertUnprocessable();
        $this->assertNextDate($foreign, '2026-12-01 09:00:00');
        $this->assertNextDate($source, '2026-11-03 09:00:00');
        $this->assertNextDate($target, '2026-11-05 09:00:00');
    }

    public function test_archiving_a_previously_loaded_activity_refreshes_its_current_deal_after_a_move(): void
    {
        $context = AdminApiTestContext::create();
        $source = $this->deal((int) $context->organization->id, 'Before move');
        $target = $this->deal((int) $context->organization->id, 'After move');
        $id = $this->postActivity($context, $source, 'Moving before archive', '2026-11-01T09:00:00Z');
        $previouslyLoaded = CrmActivity::query()->findOrFail($id);
        $this->withHeaders($context->authHeaders())->patchJson("/api/v1/admin/crm/activities/{$id}", ['deal_id' => $target->id])->assertOk();
        $this->assertNextDate($target, '2026-11-01 09:00:00');

        $this->app->make(CrmRegistryService::class)->archive($previouslyLoaded, (int) $context->user->id);

        $this->assertNextDate($source, null);
        $this->assertNextDate($target, null);
        self::assertTrue(CrmActivity::withTrashed()->findOrFail($id)->trashed());
    }

    public function test_creating_activity_does_not_wait_for_another_inserts_foreign_key_lock(): void
    {
        $context = AdminApiTestContext::create();
        $deal = $this->deal((int) $context->organization->id, 'Concurrent foreign key check');
        $primary = DB::connection();
        self::assertSame(1, $primary->transactionLevel());
        $primary->commit();
        $primary->beginTransaction();
        $peerName = 'crm_activity_lock_peer';
        config(["database.connections.{$peerName}" => $primary->getConfig()]);
        DB::purge($peerName);
        $peer = DB::connection($peerName);

        try {
            self::assertNotSame($primary->getPdo(), $peer->getPdo());
            $peer->beginTransaction();
            $peer->table('crm_activities')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $deal->organization_id,
                'deal_id' => $deal->id, 'type' => 'note', 'subject' => 'Uncommitted peer activity',
                'status' => 'planned', 'due_at' => '2026-11-01 09:00:00',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $primary->statement("SET LOCAL lock_timeout = '500ms'");

            $this->postActivity($context, $deal, 'Concurrent request', '2026-11-03T09:00:00Z');

            $this->assertNextDate($deal, '2026-11-03 09:00:00');
            self::assertSame(1, CrmActivity::query()->where('deal_id', $deal->id)->count());
        } finally {
            $peer->rollBack();
            DB::purge($peerName);
            $primary->rollBack();
            $primary->beginTransaction();
        }
    }

    private function postActivity(AdminApiTestContext $context, CrmDeal $deal, string $subject, ?string $dueAt): string
    {
        $response = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/crm/activities', [
            'deal_id' => $deal->id, 'type' => 'note', 'status' => 'planned', 'subject' => $subject, 'due_at' => $dueAt,
        ])->assertCreated();

        return (string) $response->json('data.id');
    }

    private function assertNextDate(CrmDeal $deal, ?string $expected): void
    {
        self::assertSame($expected, $deal->fresh()->next_activity_at?->format('Y-m-d H:i:s'));
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
