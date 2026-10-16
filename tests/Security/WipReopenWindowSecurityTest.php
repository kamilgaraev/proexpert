<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriodClosure;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Models\WipForecastAdjustment;
use App\BusinessModules\Features\Budgeting\Models\WipForecastAuditEvent;
use App\BusinessModules\Features\Budgeting\Models\WipForecastVersion;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\AuthSessionStatus;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Support\Str;
use Tests\Support\AdminApiTestContext;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class WipReopenWindowSecurityTest extends TestCase
{
    public function test_adjustment_manager_cannot_write_in_period_settings_reopen_window(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $member = $fixture->addMember(['budgeting' => ['budgeting.wip_forecast.manage_adjustments']], ['admin.access']);
        $actor = $this->context($fixture->organization, $member);
        $authorization = app(AuthorizationService::class)->forCurrentChecks();
        $scope = ['organization_id' => $fixture->organization->id];
        self::assertTrue($authorization->can($member, 'budgeting.wip_forecast.manage_adjustments', $scope));
        self::assertFalse($authorization->can($member, 'budgeting.periods.reopen', $scope));
        [$period, $budget] = $this->budget($fixture);
        $version = $this->forecast($budget, 'active');
        $this->reopen($fixture, $period, 'period_settings');

        $response = $this->postJson('/api/v1/admin/budgeting/wip-forecast/versions/'.$version->uuid.'/adjustments', [
            'formula_component' => 'ftc', 'amount' => 100, 'reason' => 'Cost correction outside the allowed scope',
        ], $this->headers($actor));

        self::assertSame(0, WipForecastAdjustment::query()->where('forecast_version_id', $version->id)->count(), $response->getContent());
        $response->assertUnprocessable()->assertJsonPath('message', trans_message('budgeting.wip_forecast.errors.closed_period_locked'));
    }

    public function test_reopen_windows_reject_all_wip_mutations_without_side_effects(): void
    {
        foreach (['period_settings', 'mixed', 'expired'] as $mode) {
            [$fixture, $actor] = $this->wipActor();
            [$period, $budget] = $this->budget($fixture);
            [$editing, $submitted, $approved, $active] = $this->versions($budget);
            $this->reopen($fixture, $period, $mode === 'expired' ? 'mixed' : $mode);
            if ($mode === 'expired') {
                BudgetPeriodClosure::query()->where('budget_period_id', $period->id)->latest('id')->firstOrFail()
                    ->update(['reopened_until' => now()->subMinute()]);
            }
            $before = $this->snapshot($fixture->organization->id);

            foreach ($this->mutations($budget, $editing, $submitted, $approved) as [$method, $url, $payload]) {
                $response = $this->json($method, $url, $payload, $this->headers($actor));
                $response->assertUnprocessable()->assertJsonPath('message', trans_message('budgeting.wip_forecast.errors.closed_period_locked'));
                self::assertSame($before, $this->snapshot($fixture->organization->id), $response->getContent());
            }
            $this->getJson('/api/v1/admin/budgeting/wip-forecast/versions/'.$active->uuid, $this->headers($actor))
                ->assertOk()->assertJsonPath('data.version.status', 'active');
        }
    }

    public function test_open_period_preserves_all_six_wip_mutations(): void
    {
        [$fixture, $actor] = $this->wipActor();
        [, $budget] = $this->budget($fixture);
        [$editing, $submitted, $approved, $active] = $this->versions($budget);
        foreach ($this->mutations($budget, $editing, $submitted, $approved) as [$method, $url, $payload]) {
            $response = $this->json($method, $url, $payload, $this->headers($actor));
            self::assertContains($response->status(), [200, 201], $response->getContent());
        }
        self::assertSame('submitted', $editing->refresh()->status);
        self::assertSame('approved', $submitted->refresh()->status);
        self::assertSame('active', $approved->refresh()->status);
        self::assertSame('replaced', $active->refresh()->status);
        self::assertSame('Allowed forecast update', $editing->name);
        self::assertSame(5, WipForecastVersion::query()->where('organization_id', $fixture->organization->id)->count());
        self::assertSame(1, WipForecastAdjustment::query()->where('forecast_version_id', $approved->id)->where('status', 'approved')->count());
    }

    public function test_forecast_without_budget_binding_remains_writable(): void
    {
        [$fixture, $actor] = $this->wipActor();
        [$period, $budget] = $this->budget($fixture);
        $unbound = $this->forecast($budget, 'active');
        $unbound->update(['budget_version_id' => null]);
        $this->reopen($fixture, $period, 'mixed');
        $this->postJson('/api/v1/admin/budgeting/wip-forecast/versions/'.$unbound->uuid.'/adjustments', [
            'formula_component' => 'ftc', 'amount' => 10, 'reason' => 'Unbound forecast correction', 'period' => '2026-10',
        ], $this->headers($actor))->assertCreated()->assertJsonPath('data.adjustment.status', 'approved');
    }

    private function wipActor(): array
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $permissions = array_map(static fn (string $action): string => 'budgeting.wip_forecast.'.$action, [
            'view', 'create_version', 'update_version', 'submit_version', 'approve_version', 'activate_version', 'manage_adjustments',
        ]);
        $member = $fixture->addMember(['budgeting' => $permissions], ['admin.access']);

        return [$fixture, $this->context($fixture->organization, $member)];
    }

    private function versions(BudgetVersion $budget): array
    {
        $editing = $this->forecast($budget, 'editing');
        $editing->lines()->create(['organization_id' => $budget->organization_id, 'period' => '2026-10', 'currency' => 'RUB']);

        return [$editing, $this->forecast($budget, 'submitted'), $this->forecast($budget, 'approved'), $this->forecast($budget, 'active')];
    }

    private function mutations(BudgetVersion $budget, WipForecastVersion $editing, WipForecastVersion $submitted, WipForecastVersion $approved): array
    {
        $base = '/api/v1/admin/budgeting/wip-forecast/versions';

        return [
            ['POST', $base, [
                'name' => 'New forecast', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
                'as_of_date' => '2026-10-31', 'budget_version_uuid' => $budget->uuid, 'currency' => 'RUB',
            ]],
            ['PATCH', $base.'/'.$editing->uuid, ['name' => 'Allowed forecast update']],
            ['POST', $base.'/'.$editing->uuid.'/submit', ['reason' => 'Submit forecast']],
            ['POST', $base.'/'.$submitted->uuid.'/approve', ['reason' => 'Approve forecast']],
            ['POST', $base.'/'.$approved->uuid.'/activate', ['reason' => 'Activate forecast']],
            ['POST', $base.'/'.$approved->uuid.'/adjustments', [
                'formula_component' => 'ftc', 'amount' => 100, 'reason' => 'Cost correction', 'period' => '2026-10',
            ]],
        ];
    }

    private function snapshot(int $organizationId): array
    {
        return array_map(static fn (string $model): array => $model::query()->where('organization_id', $organizationId)
            ->orderBy('id')->get()->map->getRawOriginal()->all(), [
                WipForecastVersion::class, WipForecastAdjustment::class, WipForecastAuditEvent::class,
            ]);
    }

    private function reopen(AssistantRealAuthorizationFixture $fixture, BudgetPeriod $period, string $mode): void
    {
        $owner = $this->context($fixture->organization, $fixture->owner);
        $base = '/api/v1/admin/budgeting/periods/'.$period->uuid;
        $this->postJson($base.'/close', ['reason' => 'Authorized period close'], $this->headers($owner))
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->travel(1)->seconds();
        $this->postJson($base.'/reopen-adjustment', [
            'reason' => 'Authorized limited correction',
            'expires_at' => now()->addHour()->toISOString(),
            'adjustment_mode' => $mode,
            'change_scope' => 'Explicitly approved budget corrections',
        ], $this->headers($owner))->assertOk()->assertJsonPath('data.status', 'reopened_for_adjustment')
            ->assertJsonPath('data.reopen_active', true);
        self::assertSame(2, BudgetPeriodClosure::query()->where('budget_period_id', $period->id)->count());
    }

    private function forecast(BudgetVersion $budget, string $status): WipForecastVersion
    {
        return WipForecastVersion::create([
            'organization_id' => $budget->organization_id,
            'budget_version_id' => $budget->id,
            'version_number' => 1 + (int) WipForecastVersion::query()->where('organization_id', $budget->organization_id)->max('version_number'),
            'name' => 'WIP security fixture',
            'status' => $status,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'as_of_date' => '2026-10-31',
            'currency' => 'RUB',
        ]);
    }

    private function budget(AssistantRealAuthorizationFixture $fixture): array
    {
        $organizationId = $fixture->organization->id;
        $period = BudgetPeriod::create([
            'organization_id' => $organizationId,
            'code' => 'WIP-2026-10', 'name' => 'Октябрь', 'period_type' => 'month',
            'starts_at' => '2026-10-01', 'ends_at' => '2026-10-31', 'status' => 'open',
        ]);
        $scenario = BudgetScenario::create([
            'organization_id' => $organizationId,
            'code' => 'BASE', 'name' => 'Базовый', 'scenario_type' => 'base', 'is_default' => true, 'is_active' => true,
        ]);
        $article = BudgetArticle::create([
            'organization_id' => $organizationId,
            'code' => 'WORKS', 'name' => 'Отделка', 'budget_kind' => 'bdr', 'flow_direction' => 'outflow',
            'management_cost_class' => 'labor', 'is_leaf' => true, 'is_active' => true,
        ]);
        $center = ResponsibilityCenter::create([
            'organization_id' => $organizationId,
            'code' => 'PTO', 'name' => 'ПТО', 'center_type' => 'project', 'is_active' => true,
        ]);
        $version = BudgetVersion::create([
            'organization_id' => $organizationId, 'budget_period_id' => $period->id, 'scenario_id' => $scenario->id,
            'budget_kind' => 'bdr', 'version_number' => 1, 'name' => 'Активный бюджет', 'status' => 'active', 'workflow_history' => [],
        ]);
        $line = BudgetLine::create([
            'budget_version_id' => $version->id, 'budget_article_id' => $article->id,
            'responsibility_center_id' => $center->id, 'currency' => 'RUB',
        ]);
        BudgetAmount::create([
            'budget_line_id' => $line->id, 'month' => '2026-10-01',
            'plan_amount' => '100000.00', 'forecast_amount' => '100000.00', 'currency' => 'RUB',
        ]);

        return [$period, $version];
    }

    private function context(Organization $organization, User $user): AdminApiTestContext
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $user->id, 'organization_id' => $organization->id, 'session_uuid' => $sessionUuid,
            'device_fingerprint' => hash('sha256', $sessionUuid), 'device_name' => 'WIP reopen security',
            'ip_address' => '127.0.0.1', 'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $token = app(WebAuthTokenService::class)->issue($user, 'admin', $sessionUuid, $organization->id, false);

        return new AdminApiTestContext($organization, $user, $token->accessToken);
    }

    private function headers(AdminApiTestContext $context): array
    {
        auth()->forgetGuards();

        return $context->authHeaders();
    }
}
