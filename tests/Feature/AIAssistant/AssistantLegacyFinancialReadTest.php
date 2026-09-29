<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Contracts\GetContractDetailsAction;
use App\BusinessModules\Features\AIAssistant\Actions\Projects\AnalyzeProjectRisksAction;
use App\BusinessModules\Features\AIAssistant\Actions\Projects\GetProjectBudgetAction;
use App\BusinessModules\Features\AIAssistant\Actions\Projects\GetProjectDetailsAction;
use App\BusinessModules\Features\AIAssistant\Actions\Projects\GetProjectStatusAction;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantLegacyFinancialRead;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssistantLegacyFinancialReadTest extends TestCase
{
    private array $denied = [];

    public function test_actual_tenant_corruption_does_not_change_lifetime_project_totals(): void
    {
        [$org, $actor, $project, $work] = $this->fixture();
        DB::table('completed_works')->insert($work + ['total_amount' => '80.01']);
        $foreign = Organization::factory()->create();
        DB::table('completed_works')->insert(array_replace($work, ['organization_id' => $foreign->id, 'total_amount' => '999999.99']));
        $budget = (new GetProjectBudgetAction)->execute($org->id, ['project_id' => $project->id], $actor);
        self::assertSame('80.01', $budget['total_spent']);
        self::assertSame('19.99', $budget['total_remaining']);
        $details = (new GetProjectDetailsAction)->execute($org->id, ['project_id' => $project->id], $actor);
        self::assertSame('80.01', $details['budget']['spent']);
        self::assertSame(1, $details['budget']['works_count']);
        $risks = (new AnalyzeProjectRisksAction)->execute($org->id, ['project_id' => $project->id], $actor);
        self::assertSame('80.01', $risks['budget_risks'][0]['spent']);
        self::assertSame('80.01', $risks['budget_risks'][0]['percentage_used']);
    }

    public function test_current_budget_and_source_revocation_yield_missing_values(): void
    {
        [$org, $actor, $project, $work] = $this->fixture();
        DB::table('completed_works')->insert($work + ['total_amount' => '50.01']);
        $action = new GetProjectBudgetAction;
        self::assertSame('50.01', $action->execute($org->id, ['project_id' => $project->id], $actor)['total_spent']);
        $this->denied = ['contracts.completed_works.view'];
        $result = $action->execute($org->id, ['project_id' => $project->id], $actor);
        self::assertNull($result['total_spent']);
        self::assertNull($result['total_remaining']);
        $this->denied = ['finance.view_project_budget'];
        $result = $action->execute($org->id, ['project_id' => $project->id], $actor);
        self::assertNull($result['total_budget']);
        self::assertSame('50.01', $result['total_spent']);
        self::assertNull((new GetProjectStatusAction)->execute($org->id, ['project_id' => $project->id], $actor)['projects'][0]['budget']);
    }

    public function test_archived_and_unassigned_projects_cannot_supply_money(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $project->update(['status' => 'completed', 'is_archived' => true]);
        self::assertSame([], (new GetProjectDetailsAction)->execute($org->id, ['project_id' => $project->id], $actor));
        $result = (new GetProjectBudgetAction)->execute($org->id, ['project_id' => $project->id], $actor);
        self::assertSame([], $result['projects']);
        self::assertNull($result['total_budget']);
        $project->update(['status' => 'active', 'is_archived' => false]);
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertSame([], (new GetProjectDetailsAction)->execute($org->id, ['project_id' => $project->id], $actor));
    }

    public function test_postgres_sum_above_double_precision_keeps_every_kopeck(): void
    {
        [$org, $actor, $project, $work] = $this->fixture();
        foreach (array_chunk(array_fill(0, 1001, $work + ['total_amount' => '9007199254740.99']), 250) as $rows) {
            DB::table('completed_works')->insert($rows);
        }
        $result = (new GetProjectBudgetAction)->execute($org->id, ['project_id' => $project->id], $actor);
        self::assertSame((string) BigDecimal::of('9007199254740.99')->multipliedBy(1001), $result['total_spent']);
        self::assertSame(1001, (new AssistantLegacyFinancialRead)->projectSpent($actor, $org->id, $project->id)['works_count']);
    }

    public function test_contract_uses_canonical_acts_exact_totals_and_currency_separation(): void
    {
        [$org, $actor, $project] = $this->fixture();
        $contractor = Contractor::query()->create(['organization_id' => $org->id, 'name' => 'Подрядчик финансового теста', 'legal_address' => 'Москва, улица Монтажная, 1']);
        $contract = Contract::query()->create(['organization_id' => $org->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id, 'number' => 'EXACT-LEGACY', 'date' => '2026-09-01', 'total_amount' => '100.00', 'currency' => 'RUB']);
        foreach (['0.10', '0.20'] as $amount) {
            DB::table('contract_performance_acts')->insert(['contract_id' => $contract->id, 'project_id' => $project->id, 'act_date' => '2026-09-01', 'amount' => $amount, 'currency' => 'RUB', 'status' => 'approved', 'is_approved' => true]);
        }
        foreach (['0.10', '0.20'] as $index => $amount) {
            DB::table('payment_documents')->insert(['organization_id' => $org->id, 'project_id' => $project->id, 'document_type' => 'invoice', 'document_number' => 'EXACT-LEGACY-'.$contract->id.'-'.$index, 'document_date' => '2026-09-01', 'direction' => 'outgoing', 'invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id, 'amount' => $amount, 'paid_amount' => $amount, 'remaining_amount' => '0.00', 'amount_without_vat' => $amount, 'currency' => 'RUB', 'status' => 'paid']);
        }
        $action = new GetContractDetailsAction;
        $result = $action->execute($org->id, ['contract_id' => $contract->id], $actor);
        self::assertSame('Москва, улица Монтажная, 1', $result['contractor']['address']);
        self::assertSame('0.30', $result['financial']['total_acted']);
        self::assertSame('0.30', $result['financial']['total_invoiced']);
        self::assertSame('0.30', $result['financial']['total_paid']);
        self::assertSame('99.70', $result['financial']['remaining']);
        self::assertSame('0.30', $result['financial']['completion_percentage']);
        $this->denied = ['payments.invoice.view', 'payments.invoice.view_all'];
        self::assertNull($action->execute($org->id, ['contract_id' => $contract->id], $actor)['financial']['total_paid']);
        $this->denied = ['contracts.performance_acts.view'];
        self::assertNull($action->execute($org->id, ['contract_id' => $contract->id], $actor)['financial']['total_acted']);
        $this->denied = [];
        DB::table('contract_performance_acts')->insert(['contract_id' => $contract->id, 'project_id' => $project->id, 'act_date' => '2026-09-01', 'amount' => '1.00', 'currency' => 'USD', 'status' => 'approved', 'is_approved' => true]);
        self::assertNull($action->execute($org->id, ['contract_id' => $contract->id], $actor)['financial']['total_acted']);
    }

    private function fixture(): array
    {
        $org = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $org->id, 'is_active' => true]);
        $actor->organizations()->attach($org->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $project = Project::factory()->create(['organization_id' => $org->id, 'status' => 'active', 'is_archived' => false, 'budget_amount' => '100.00', 'end_date' => null]);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturnCallback(fn (User $user, string $permission): bool => ! in_array($permission, $this->denied, true));
        $authorization->method('forCurrentChecks')->willReturnSelf();
        $modules = $this->createMock(OrganizationEntitlementService::class);
        $modules->method('getEffectiveModules')->willReturn(collect(['ai-assistant', 'project-management', 'contract-management', 'payments', 'users'])->map(static fn (string $slug): object => (object) ['slug' => $slug]));
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->app->instance(AssistantDataAccessPolicy::class, new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules));
        $unit = DB::table('measurement_units')->insertGetId(['organization_id' => $org->id, 'name' => 'Единица финансового теста', 'short_name' => 'фин-тест', 'type' => 'work']);
        $type = DB::table('work_types')->insertGetId(['organization_id' => $org->id, 'name' => 'Монтаж', 'measurement_unit_id' => $unit, 'is_active' => true]);

        return [$org, $actor, $project, ['organization_id' => $org->id, 'project_id' => $project->id, 'work_type_id' => $type, 'user_id' => $actor->id, 'quantity' => '1.000', 'completion_date' => '2026-01-01', 'status' => 'confirmed']];
    }
}
