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
use App\BusinessModules\Features\Budgeting\Services\BudgetCatalogService;
use App\BusinessModules\Features\Budgeting\Services\BudgetPeriodClosureService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\AuthSessionStatus;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use DomainException;
use Illuminate\Support\Str;
use Tests\Support\AdminApiTestContext;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class BudgetPeriodWorkflowSecurityTest extends TestCase
{
    public function test_manager_cannot_change_period_workflow_through_catalog_update(): void
    {
        [$actor] = $this->actor();
        $this->assertManagerPermissions($actor);
        $period = app(BudgetCatalogService::class)->storePeriod($actor->user, $this->periodInput());
        $base = '/api/v1/admin/budgeting/periods/'.$period->uuid;
        $this->postJson($base.'/close', ['reason' => 'Unauthorized close'], $this->headers($actor))->assertForbidden();
        $this->postJson($base.'/reopen-adjustment', $this->reopenInput(), $this->headers($actor))->assertForbidden();
        $before = $period->refresh()->getRawOriginal();

        foreach (['closed', 'soft_closed', 'archived', 'reopened_for_adjustment'] as $status) {
            $response = $this->putJson($base, array_replace($this->periodInput(), [
                'status' => $status, 'name' => 'Blocked workflow transition',
            ]), $this->headers($actor));

            self::assertSame(0, BudgetPeriodClosure::query()->where('budget_period_id', $period->id)->count());
            self::assertSame('open', $period->refresh()->status, $response->getContent());
            $response->assertUnprocessable();
            self::assertSame($before, $period->getRawOriginal());
        }
        foreach ([[], ['status' => 'open']] as $statusInput) {
            $this->putJson($base, array_replace($this->periodInput(), ['name' => 'Allowed settings'], $statusInput), $this->headers($actor))
                ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.name', 'Allowed settings');
        }
    }

    public function test_manager_cannot_escape_legitimate_period_settings_reopen_window(): void
    {
        [$actor, $fixture] = $this->actor();
        $this->assertManagerPermissions($actor);
        $period = $this->closeablePeriod($fixture);
        $owner = $this->context($fixture->organization, $fixture->owner);
        $base = '/api/v1/admin/budgeting/periods/'.$period->uuid;
        $this->postJson($base.'/close', ['reason' => 'Authorized financial period closure'], $this->headers($owner))
            ->assertOk()->assertJsonPath('data.status', 'closed');
        self::assertSame(1, BudgetPeriodClosure::query()->where('budget_period_id', $period->id)->count());
        $this->putJson($base, $this->periodInput() + ['status' => 'open'], $this->headers($actor))->assertUnprocessable();
        self::assertSame('closed', $period->refresh()->status);
        $this->travel(1)->seconds();
        $this->postJson($base.'/reopen-adjustment', $this->reopenInput(), $this->headers($owner))
            ->assertOk()->assertJsonPath('data.status', 'reopened_for_adjustment')
            ->assertJsonPath('data.reopen_active', true)->assertJsonPath('data.allowed_operations', ['period_settings']);
        self::assertSame(2, BudgetPeriodClosure::query()->where('budget_period_id', $period->id)->count());
        $period->refresh();
        $closureBefore = BudgetPeriodClosure::query()->where('budget_period_id', $period->id)->orderBy('id')->get()->map->getRawOriginal()->all();
        self::assertFalse($this->allowsBudgetLines($period));

        foreach (['open', 'closed'] as $status) {
            $response = $this->putJson($base, $this->periodInput() + ['status' => $status], $this->headers($actor));

            self::assertSame('reopened_for_adjustment', $period->refresh()->status, $response->getContent());
            self::assertFalse($this->allowsBudgetLines($period));
            $response->assertUnprocessable();
        }
        foreach ([[], ['status' => 'reopened_for_adjustment']] as $statusInput) {
            $this->putJson($base, array_replace($this->periodInput(), ['name' => 'Allowed window settings'], $statusInput), $this->headers($actor))
                ->assertOk()->assertJsonPath('data.status', 'reopened_for_adjustment')
                ->assertJsonPath('data.name', 'Allowed window settings')->assertJsonPath('data.reopen_active', true);
        }
        self::assertSame($closureBefore, BudgetPeriodClosure::query()->where('budget_period_id', $period->id)->orderBy('id')->get()->map->getRawOriginal()->all());
        $latest = BudgetPeriodClosure::query()->where('budget_period_id', $period->id)->latest('id')->firstOrFail();
        $latest->update(['reopened_until' => now()->subMinute()]);
        $beforeExpiryAttempt = $period->refresh()->getRawOriginal();
        $this->putJson($base, array_replace($this->periodInput(), ['name' => 'Expired window write']), $this->headers($actor))
            ->assertUnprocessable();
        self::assertSame($beforeExpiryAttempt, $period->refresh()->getRawOriginal());
        self::assertSame(2, BudgetPeriodClosure::query()->where('budget_period_id', $period->id)->count());
    }

    public function test_catalog_create_only_accepts_open_periods(): void
    {
        [$actor] = $this->actor();
        foreach ([[], ['status' => 'open']] as $index => $statusInput) {
            $this->postJson('/api/v1/admin/budgeting/periods', array_replace($this->periodInput(), [
                'code' => 'CREATE-'.$index,
            ], $statusInput), $this->headers($actor))->assertCreated()->assertJsonPath('data.status', 'open');
        }
        foreach (['closed', 'soft_closed', 'archived', 'reopened_for_adjustment'] as $status) {
            $this->postJson('/api/v1/admin/budgeting/periods', array_replace($this->periodInput(), [
                'code' => 'INVALID-'.$status, 'status' => $status,
            ]), $this->headers($actor))->assertUnprocessable();
        }
        self::assertSame(2, BudgetPeriod::query()->where('organization_id', $actor->organization->id)->count());
        self::assertSame(0, BudgetPeriodClosure::query()->count());
    }

    public function test_direct_catalog_service_cannot_create_or_transition_workflow_state(): void
    {
        [$actor] = $this->actor();
        $service = app(BudgetCatalogService::class);
        foreach (['closed', 'soft_closed', 'archived', 'reopened_for_adjustment'] as $status) {
            $this->assertDomainRejected(fn () => $service->storePeriod($actor->user, $this->periodInput() + ['status' => $status]));
        }
        self::assertSame(0, BudgetPeriod::query()->where('organization_id', $actor->organization->id)->count());
        $period = $service->storePeriod($actor->user, $this->periodInput() + ['status' => 'open']);
        $before = $period->refresh()->getRawOriginal();
        foreach (['closed', 'soft_closed', 'archived', 'reopened_for_adjustment'] as $status) {
            $this->assertDomainRejected(fn () => $service->updatePeriod($actor->user, $period->uuid, array_replace($this->periodInput(), [
                'status' => $status, 'name' => 'Blocked direct transition',
            ])));
            self::assertSame($before, $period->refresh()->getRawOriginal());
        }
        self::assertSame(0, BudgetPeriodClosure::query()->count());
    }

    private function assertDomainRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Catalog workflow transition was accepted.');
        } catch (DomainException $exception) {
            self::assertNotEmpty($exception->getMessage());
        }
    }

    private function assertManagerPermissions(AdminApiTestContext $actor): void
    {
        $authorization = app(AuthorizationService::class)->forCurrentChecks();
        $context = ['organization_id' => $actor->organization->id];
        self::assertTrue($authorization->can($actor->user, 'budgeting.periods.manage', $context));
        self::assertFalse($authorization->can($actor->user, 'budgeting.periods.close', $context));
        self::assertFalse($authorization->can($actor->user, 'budgeting.periods.reopen', $context));
    }

    private function allowsBudgetLines(BudgetPeriod $period): bool
    {
        try {
            app(BudgetPeriodClosureService::class)->assertPeriodMutable($period, BudgetPeriodClosureService::OPERATION_BUDGET_LINES);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    private function actor(): array
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $user = $fixture->addMember(['budgeting' => ['budgeting.periods.manage']], ['admin.access']);

        return [$this->context($fixture->organization, $user), $fixture];
    }

    private function context(Organization $organization, User $user): AdminApiTestContext
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'session_uuid' => $sessionUuid,
            'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Budget period workflow security',
            'ip_address' => '127.0.0.1',
            'risk_score' => 0,
            'risk_flags' => [],
            'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $token = app(WebAuthTokenService::class)->issue($user, 'admin', $sessionUuid, $organization->id, false);

        return new AdminApiTestContext($organization, $user, $token->accessToken);
    }

    private function headers(AdminApiTestContext $context): array
    {
        auth()->forgetGuards();

        return $context->authHeaders();
    }

    private function periodInput(): array
    {
        return [
            'code' => 'PERIOD-WORKFLOW-2026-10',
            'name' => 'Октябрь',
            'period_type' => 'month',
            'starts_at' => '2026-10-01',
            'ends_at' => '2026-10-31',
        ];
    }

    private function reopenInput(): array
    {
        return [
            'reason' => 'Authorized period settings correction',
            'expires_at' => now()->addHour()->toISOString(),
            'adjustment_mode' => 'period_settings',
            'change_scope' => 'Названия и реквизиты периода',
        ];
    }

    private function closeablePeriod(AssistantRealAuthorizationFixture $fixture): BudgetPeriod
    {
        $organizationId = $fixture->organization->id;
        $period = app(BudgetCatalogService::class)->storePeriod($fixture->owner, $this->periodInput());
        $scenario = BudgetScenario::create([
            'organization_id' => $organizationId,
            'code' => 'BASE', 'name' => 'Базовый', 'scenario_type' => 'base', 'is_default' => true, 'is_active' => true,
        ]);
        $article = BudgetArticle::create([
            'organization_id' => $organizationId,
            'code' => 'WORKS', 'name' => 'Отделка', 'budget_kind' => 'bdds', 'flow_direction' => 'outflow',
            'management_cost_class' => 'labor', 'is_leaf' => true, 'is_active' => true,
        ]);
        $center = ResponsibilityCenter::create([
            'organization_id' => $organizationId,
            'code' => 'PTO', 'name' => 'ПТО', 'center_type' => 'project', 'is_active' => true,
        ]);
        $version = BudgetVersion::create([
            'organization_id' => $organizationId,
            'budget_period_id' => $period->id,
            'scenario_id' => $scenario->id,
            'budget_kind' => 'bdds',
            'version_number' => 1,
            'name' => 'Активный бюджет',
            'status' => 'active',
            'workflow_history' => [],
        ]);
        $line = BudgetLine::create([
            'budget_version_id' => $version->id,
            'budget_article_id' => $article->id,
            'responsibility_center_id' => $center->id,
            'currency' => 'RUB',
        ]);
        BudgetAmount::create([
            'budget_line_id' => $line->id,
            'month' => '2026-10-01',
            'plan_amount' => '100000.00',
            'forecast_amount' => '100000.00',
            'currency' => 'RUB',
        ]);

        return $period;
    }
}
