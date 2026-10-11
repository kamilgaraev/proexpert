<?php

declare(strict_types=1);

namespace Tests\Feature\Budgeting;

use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Services\BudgetCatalogService;
use App\BusinessModules\Features\Budgeting\Services\BudgetStarterCatalogService;
use App\Models\Organization;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class BudgetStarterCatalogPostgresTest extends TestCase
{
    private const GROUPS = ['scenarios', 'responsibility_centers', 'articles'];

    public function test_starter_catalog_is_complete_persistent_and_idempotent(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $service = app(BudgetStarterCatalogService::class);
        $expected = ['scenarios' => 3, 'responsibility_centers' => 6, 'articles' => 24];
        self::assertSame($expected, $service->apply($fixture->owner, self::GROUPS));
        self::assertSame(array_fill_keys(self::GROUPS, 0), $service->apply($fixture->owner, self::GROUPS));
        self::assertSame(1, BudgetScenario::where('is_default', true)->count());
        $catalog = app(BudgetCatalogService::class)->catalogs($fixture->owner, ['budget_kind' => 'bdds']);
        self::assertCount(12, $catalog['articles']);
        self::assertCount(24, $catalog['starter_catalogs']['articles']);
        self::assertSame(['outflow'], array_values(array_unique(array_column($catalog['articles'], 'flow_direction'))));
        self::assertSame(12, BudgetArticle::where('budget_kind', 'bdr')->where('flow_direction', 'expense')->count());
        self::assertSame(4, BudgetArticle::where('management_cost_class', 'labor')->count());
        self::assertSame(0, BudgetArticle::where('organization_id', '!=', $fixture->organization->id)->count());
    }

    public function test_existing_customized_inactive_and_deleted_records_are_preserved(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $service = app(BudgetStarterCatalogService::class);
        $service->apply($fixture->owner, self::GROUPS);
        $base = BudgetScenario::where('code', 'BASE')->firstOrFail();
        $base->update(['name' => 'Мой план', 'is_default' => false]);
        BudgetScenario::where('code', 'OPTIMISTIC')->update(['is_default' => true]);
        $center = ResponsibilityCenter::where('code', 'SUPPLY')->firstOrFail();
        $center->update(['is_active' => false]);
        $article = BudgetArticle::where('code', 'BDR-MATERIALS')->firstOrFail();
        $article->delete();
        self::assertSame(array_fill_keys(self::GROUPS, 0), $service->apply($fixture->owner, self::GROUPS));
        self::assertSame('Мой план', $base->refresh()->name);
        self::assertFalse($base->is_default);
        self::assertFalse($center->refresh()->is_active);
        self::assertTrue($article->refresh()->trashed());
    }

    public function test_matching_name_with_custom_code_is_not_duplicated(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        BudgetArticle::create([
            'organization_id' => $fixture->organization->id,
            'code' => 'MY-MATERIALS', 'name' => 'Материалы и комплектующие',
            'budget_kind' => 'both', 'flow_direction' => 'expense',
            'management_cost_class' => 'non_labor', 'is_active' => true, 'is_leaf' => true,
        ]);
        $result = app(BudgetStarterCatalogService::class)->apply($fixture->owner, ['articles']);
        self::assertSame(22, $result['articles']);
        self::assertSame(1, BudgetArticle::where('name', 'Материалы и комплектующие')->count());
    }

    public function test_only_selected_group_is_created_and_foreign_organization_is_untouched(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $currentDefault = BudgetScenario::create([
            'organization_id' => $fixture->organization->id, 'code' => 'MY-DEFAULT',
            'name' => 'Мой сценарий', 'scenario_type' => 'custom', 'is_default' => true, 'is_active' => false,
        ]);
        $foreignOrganization = Organization::factory()->create();
        $foreignScenario = BudgetScenario::create([
            'organization_id' => $foreignOrganization->id, 'code' => 'BASE',
            'name' => 'Чужой план', 'scenario_type' => 'base', 'is_default' => true, 'is_active' => true,
        ]);
        self::assertSame(['scenarios' => 3, 'responsibility_centers' => 0, 'articles' => 0],
            app(BudgetStarterCatalogService::class)->apply($fixture->owner, ['scenarios']));
        self::assertSame('Чужой план', $foreignScenario->refresh()->name);
        self::assertTrue($currentDefault->refresh()->is_default);
        self::assertFalse(BudgetScenario::where('organization_id', $fixture->organization->id)->where('code', 'BASE')->firstOrFail()->is_default);
        self::assertSame(0, ResponsibilityCenter::count());
        self::assertSame(0, BudgetArticle::count());
    }

    public function test_every_requested_permission_is_checked_before_any_write(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $actor = $fixture->addMember(['budgeting' => ['budgeting.scenarios.manage']]);
        $service = app(BudgetStarterCatalogService::class);
        try {
            $service->apply($actor, ['scenarios', 'articles']);
            self::fail('Expected denied articles permission');
        } catch (AccessDeniedHttpException) {
            self::assertSame(0, BudgetScenario::count());
            self::assertSame(0, BudgetArticle::count());
        }
        self::assertSame(3, $service->apply($actor, ['scenarios'])['scenarios']);
    }

    public function test_foreign_context_and_inactive_actor_cannot_write(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $fixture->owner->current_organization_id = $fixture->foreignOrganization->id;
        try {
            app(BudgetStarterCatalogService::class)->apply($fixture->owner, ['scenarios']);
            self::fail('Expected denied foreign organization');
        } catch (AccessDeniedHttpException) {
            self::assertSame(0, BudgetScenario::count());
        }
        $fixture->owner->current_organization_id = $fixture->organization->id;
        $fixture->owner->is_active = false;
        $this->expectException(AccessDeniedHttpException::class);
        app(BudgetStarterCatalogService::class)->apply($fixture->owner, ['scenarios']);
    }

    public function test_failure_rolls_back_the_whole_set(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $event = 'eloquent.creating: '.ResponsibilityCenter::class;
        $this->app['events']->listen($event, fn () => throw new RuntimeException('test catalog failure'));
        try {
            app(BudgetStarterCatalogService::class)->apply($fixture->owner, self::GROUPS);
            self::fail('Expected catalog failure');
        } catch (RuntimeException $exception) {
            self::assertSame('test catalog failure', $exception->getMessage());
            self::assertSame(0, BudgetScenario::count());
            self::assertSame(0, ResponsibilityCenter::count());
            self::assertSame(0, BudgetArticle::count());
        } finally {
            $this->app['events']->forget($event);
        }
    }

    public function test_http_validates_groups_and_cannot_override_current_organization(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $this->withoutMiddleware()->actingAs($fixture->owner);
        $this->postJson('/api/v1/admin/budgeting/catalogs/starter', ['groups' => ['unknown']])->assertUnprocessable();
        $this->postJson('/api/v1/admin/budgeting/catalogs/starter', ['groups' => ['articles', 'articles']])->assertUnprocessable();
        $this->postJson('/api/v1/admin/budgeting/catalogs/starter', [
            'groups' => ['scenarios'], 'organization_id' => $fixture->foreignOrganization->id,
        ])->assertOk()->assertJsonPath('data.created.scenarios', 3);
        self::assertSame(0, BudgetScenario::where('organization_id', $fixture->foreignOrganization->id)->count());
    }
}
