<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentAccessService;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\AuthSessionStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class PaymentCalendarProjectScopeSecurityTest extends TestCase
{
    public function test_calendar_budget_plans_hide_unassigned_projects_before_totals(): void
    {
        [$fixture, $actor, $allowed, $restricted] = $this->context();
        $amounts = $this->budget($fixture->organization->id, [[$allowed->id, 100], [$restricted->id, 900]]);

        $this->getJson($this->url('budget_amount'), $this->headers($actor))
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonCount(1, 'data.events')
            ->assertJsonPath('data.items.0.source_id', $amounts[0]->id)
            ->assertJsonPath('data.items.0.project_id', $allowed->id)
            ->assertJsonPath('data.summary.items_count', 1)->assertJsonPath('data.summary.outflow', 100)
            ->assertJsonPath('data.days.0.outflow', 100);
    }

    public function test_organization_permission_keeps_projectless_and_archived_plans_and_all_projects_mode(): void
    {
        [$fixture, $actor, $allowed, $restricted] = $this->context();
        $allowed->update(['is_archived' => true]);
        $this->budget($fixture->organization->id, [[$allowed->id, 100], [$restricted->id, 900], [null, 50]]);
        $headers = $this->headers($actor);
        $this->getJson($this->url('budget_amount'), $headers)->assertOk()->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.summary.outflow', 150);
        $fixture->organization->users()->updateExistingPivot($actor->id, ['project_access_mode' => 'all_projects']);
        $this->getJson($this->url('budget_amount'), $headers)->assertOk()->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.summary.outflow', 1050);
    }

    public function test_project_only_permission_excludes_other_projects_and_projectless_plans(): void
    {
        [$fixture, $actor, $allowed, $restricted] = $this->context([]);
        $amounts = $this->budget($fixture->organization->id, [[$allowed->id, 100], [$restricted->id, 900], [null, 50]]);
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'Project calendar',
            'slug' => 'calendar_project_'.Str::lower(Str::random(12)), 'system_permissions' => [],
            'module_permissions' => ['payments' => ['payments.schedule.view']],
            'interface_access' => ['admin'], 'is_active' => true, 'created_by' => $fixture->owner->id,
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $actor->id, 'role_slug' => $role->slug, 'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'context_id' => AuthorizationContext::getProjectContext($allowed->id, $fixture->organization->id)->id,
            'assigned_by' => $fixture->owner->id, 'is_active' => true,
        ]);
        $items = $this->collect($fixture->organization->id, $actor);
        self::assertCount(1, $items);
        self::assertSame($amounts[0]->id, $items[0]->sourceId);
        self::assertSame($allowed->id, $items[0]->projectId);
    }

    public function test_live_project_role_and_membership_changes_override_warmed_calendar_access(): void
    {
        [$fixture, $actor, $allowed, $restricted] = $this->context();
        $this->budget($fixture->organization->id, [[$allowed->id, 100], [$restricted->id, 900]]);
        self::assertTrue($actor->can('payments.schedule.view', ['organization_id' => $fixture->organization->id]));
        self::assertCount(1, $this->collect($fixture->organization->id, $actor));
        $allowed->users()->updateExistingPivot($actor->id, ['is_active' => false]);
        self::assertCount(0, $this->collect($fixture->organization->id, $actor));
        $allowed->users()->updateExistingPivot($actor->id, ['is_active' => true]);
        UserRoleAssignment::query()->where('user_id', $actor->id)->update(['is_active' => false]);
        self::assertCount(0, $this->collect($fixture->organization->id, $actor));
        UserRoleAssignment::query()->where('user_id', $actor->id)->update(['is_active' => true]);
        self::assertCount(1, $this->collect($fixture->organization->id, $actor));
        $fixture->organization->users()->updateExistingPivot($actor->id, ['is_active' => false]);
        self::assertCount(0, $this->collect($fixture->organization->id, $actor));
    }

    public function test_tenant_version_boundary_is_kept_when_a_budget_line_references_an_accessible_project(): void
    {
        [$fixture, $actor, $allowed, $restricted] = $this->context();
        $this->budget($fixture->organization->id, [[$allowed->id, 100], [$restricted->id, 900]]);
        $this->budget($fixture->foreignOrganization->id, [[$allowed->id, 9999]]);
        $this->getJson($this->url('budget_amount'), $this->headers($actor))->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.summary.outflow', 100);
        $backgroundItems = (new PaymentCalendarSourceService)->collect($this->filters($fixture->organization->id));
        self::assertCount(2, $backgroundItems);
        self::assertEqualsCanonicalizing([$allowed->id, $restricted->id], array_map(static fn ($item) => $item->projectId, $backgroundItems));
    }

    public function test_calendar_transactions_require_both_snapshot_and_current_parent_projects(): void
    {
        [$fixture, $actor, $allowed, $restricted] = $this->context();
        $allowedDocument = $this->document($allowed);
        $restrictedDocument = $this->document($restricted);
        foreach ([[$allowed->id, $allowedDocument, 100], [$restricted->id, $allowedDocument, 700], [$allowed->id, $restrictedDocument, 500]] as [$projectId, $document, $amount]) {
            PaymentTransaction::query()->create([
                'organization_id' => $fixture->organization->id, 'project_id' => $projectId,
                'payment_document_id' => $document->id, 'amount' => $amount, 'currency' => 'RUB',
                'payment_method' => 'bank_transfer', 'transaction_date' => '2026-10-15', 'status' => 'completed',
            ]);
        }
        $this->getJson($this->url('payment_transaction'), $this->headers($actor))->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.summary.outflow', 100);
    }

    public function test_calendar_reservations_require_both_snapshot_and_current_parent_projects(): void
    {
        [$fixture, $actor, $allowed, $restricted] = $this->context();
        $budget = $this->budget($fixture->organization->id, [[$allowed->id, 100]])[0];
        $line = $budget->line;
        foreach ([[$allowed->id, $allowed, 100], [$restricted->id, $allowed, 700], [$allowed->id, $restricted, 500]] as [$projectId, $project, $amount]) {
            $document = $this->document($project);
            BudgetLimitReservation::query()->create([
                'organization_id' => $fixture->organization->id, 'project_id' => $projectId,
                'payment_document_id' => $document->id, 'budget_article_id' => $line->budget_article_id,
                'responsibility_center_id' => $line->responsibility_center_id, 'period_month' => '2026-10-01',
                'amount' => $amount, 'currency' => 'RUB', 'status' => 'reserved', 'reserved_at' => now(),
            ]);
        }
        $this->getJson($this->url('budget_limit_reservation'), $this->headers($actor))->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.summary.outflow', 100);
    }

    private function context(array $permissions = ['payments.schedule.view']): array
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember(['payments' => $permissions]);
        $allowed = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $restricted = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $allowed->users()->attach($actor->id, ['role' => 'member', 'is_active' => true]);

        return [$fixture, $actor, $allowed, $restricted];
    }

    private function filters(int $organizationId): PaymentCalendarSourceFilters
    {
        return new PaymentCalendarSourceFilters(
            organizationId: $organizationId, periodStart: '2026-10-01', periodEnd: '2026-10-31', sourceType: 'budget_amount',
        );
    }

    private function collect(int $organizationId, User $actor): array
    {
        $access = app(PaymentDocumentAccessService::class);

        return (new PaymentCalendarSourceService)->collect($this->filters($organizationId),
            scopeDocument: fn (Builder $query) => $access->scope($query, $organizationId, $actor, 'payments.schedule.view'),
            scopeProjects: fn (Builder $query) => $access->scopeProjects($query, $organizationId, $actor, 'payments.schedule.view'));
    }

    private function url(string $sourceType): string
    {
        return '/api/v1/admin/payments/documents/calendar?start=2026-10-01&end=2026-10-31&source_type='.$sourceType;
    }

    private function document(Project $project): PaymentDocument
    {
        return PaymentDocument::query()->create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'document_type' => PaymentDocumentType::INVOICE, 'document_number' => 'CAL-'.Str::uuid(),
            'document_date' => '2026-10-01', 'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1000, 'paid_amount' => 0, 'remaining_amount' => 1000, 'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED, 'due_date' => '2026-10-15',
        ]);
    }

    private function budget(int $organizationId, array $lines): array
    {
        $period = BudgetPeriod::query()->create([
            'organization_id' => $organizationId, 'code' => 'SEC-PER-'.Str::uuid(), 'name' => 'October',
            'period_type' => 'month', 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-31', 'status' => 'open',
        ]);
        $scenario = BudgetScenario::query()->create([
            'organization_id' => $organizationId, 'code' => 'SEC-BASE-'.Str::uuid(), 'name' => 'Base',
            'scenario_type' => 'base', 'is_default' => true, 'is_active' => true,
        ]);
        $article = BudgetArticle::query()->create([
            'organization_id' => $organizationId, 'code' => 'SEC-PAY-'.Str::uuid(), 'name' => 'Payments',
            'budget_kind' => 'bdds', 'flow_direction' => 'outflow', 'is_leaf' => true, 'is_active' => true,
        ]);
        $center = ResponsibilityCenter::query()->create([
            'organization_id' => $organizationId, 'code' => 'SEC-CFO-'.Str::uuid(), 'name' => 'Project',
            'center_type' => 'project', 'is_active' => true,
        ]);
        $version = BudgetVersion::query()->create([
            'organization_id' => $organizationId, 'budget_period_id' => $period->id, 'scenario_id' => $scenario->id,
            'budget_kind' => 'bdds', 'version_number' => 1, 'name' => 'Active budget',
            'status' => 'active', 'approved_at' => now(), 'activated_at' => now(),
        ]);
        $amounts = [];
        foreach ($lines as [$projectId, $amount]) {
            $line = BudgetLine::query()->create([
                'budget_version_id' => $version->id, 'budget_article_id' => $article->id,
                'responsibility_center_id' => $center->id, 'project_id' => $projectId, 'currency' => 'RUB',
            ]);
            $amounts[] = BudgetAmount::query()->create([
                'budget_line_id' => $line->id, 'month' => '2026-10-01', 'plan_amount' => $amount,
                'forecast_amount' => $amount, 'currency' => 'RUB',
            ]);
        }

        return $amounts;
    }

    private function headers(User $actor): array
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $actor->id, 'organization_id' => $actor->current_organization_id,
            'session_uuid' => $sessionUuid, 'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Calendar security regression', 'ip_address' => '127.0.0.1',
            'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $tokens = app(WebAuthTokenService::class)->issue($actor, 'admin', $sessionUuid, (int) $actor->current_organization_id, false);

        return ['Authorization' => 'Bearer '.$tokens->accessToken, 'Accept' => 'application/json', 'Origin' => 'https://admin.1мост.рф'];
    }
}
