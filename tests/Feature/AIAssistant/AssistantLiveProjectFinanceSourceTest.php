<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantLiveProjectFinanceProjection;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginCalculator;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Module;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

final class AssistantLiveProjectFinanceSourceTest extends TestCase
{
    public function test_approved_machinery_shift_has_exact_native_cost_and_current_registered_identity(): void
    {
        [$actor, $project, $input] = $this->fixture();
        $actor->organizations()->syncWithoutDetaching([$actor->current_organization_id => ['is_active' => true, 'project_access_mode' => 'all_projects']]);
        $asset = MachineryAsset::query()->create(['organization_id' => $actor->current_organization_id, 'current_project_id' => $project->id,
            'asset_code' => 'EXACT-SHIFT', 'name' => 'Кран', 'status' => 'in_operation', 'ownership_type' => 'owned']);
        $shift = MachineryShiftReport::query()->create(['organization_id' => $actor->current_organization_id, 'asset_id' => $asset->id,
            'project_id' => $project->id, 'report_date' => '2026-09-10', 'status' => 'approved', 'actual_hours' => '1.25',
            'hourly_rate_snapshot' => '10.01', 'approved_at' => '2026-09-10 12:00:00']);
        $machineryAllowed = true;
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturnCallback(static function (User $user, string $permission) use (&$machineryAllowed): bool {
            return $permission !== 'machinery-operations.view' || $machineryAllowed;
        });
        $modules = $this->createMock(OrganizationEntitlementService::class);
        $modules->method('getEffectiveModules')->willReturn(collect([new Module(['slug' => 'machinery-operations']), new Module(['slug' => 'project-management'])]));
        $projects = $this->createMock(UserProjectAccessService::class);
        $projects->method('queryAccessibleProjects')->willReturnCallback(static fn () => Project::query()->whereKey($project->id));
        $policy = new AssistantDataAccessPolicy($authorization, $projects, $modules);
        $service = new ProjectMarginReportService(new ProjectMarginCalculator, $authorization);
        $native = $service->exactFinancialSourceRows($input, [$project->id], $actor);
        $shiftSources = array_values(array_filter($native['sources'], static fn (array $source): bool => $source['source_type'] === 'machinery_shift'));
        self::assertCount(1, $shiftSources);
        self::assertSame((int) $shift->id, (int) $shiftSources[0]['source_id']);
        self::assertSame((int) $project->id, (int) $shiftSources[0]['project_id']);
        self::assertSame('12.51', $shiftSources[0]['amount_without_vat']);
        self::assertTrue($policy->canReadEntity($actor, (int) $actor->current_organization_id, 'machinery_shift_report', $shift->id));
        self::assertFalse($policy->canReadEntity($actor, (int) $actor->current_organization_id, 'machinery_shift_report', $shift->id + 1000000));
        self::assertFalse($policy->canReadEntity($actor, (int) Organization::factory()->create()->id, 'machinery_shift_report', $shift->id));
        $machineryAllowed = false;
        self::assertFalse($policy->canReadEntity($actor, (int) $actor->current_organization_id, 'machinery_shift_report', $shift->id));
        $machineryAllowed = true;
        $project->forceFill(['status' => 'completed', 'is_archived' => true])->save();
        $this->expectException(AccessDeniedHttpException::class);
        $service->exactFinancialSourceRows($input, [$project->id], $actor);
    }

    public function test_revoked_machinery_permission_blocks_approved_shift_native_cost(): void
    {
        [$actor, $project, $input] = $this->fixture();
        $asset = MachineryAsset::query()->create(['organization_id' => $actor->current_organization_id, 'asset_code' => 'DENIED-SHIFT', 'name' => 'Кран']);
        MachineryShiftReport::query()->create(['organization_id' => $actor->current_organization_id, 'asset_id' => $asset->id, 'project_id' => $project->id,
            'report_date' => '2026-09-10', 'status' => 'approved', 'actual_hours' => '1.25', 'hourly_rate_snapshot' => '10.01', 'approved_at' => '2026-09-10 12:00:00']);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturnCallback(static fn (User $user, string $permission): bool => $permission !== 'machinery-operations.view');
        $this->expectException(AccessDeniedHttpException::class);
        (new ProjectMarginReportService(new ProjectMarginCalculator, $authorization))->exactFinancialSourceRows($input, [$project->id], $actor);
    }

    public function test_actual_native_sql_keeps_decimal_above_double_precision_separates_currency_and_respects_range(): void
    {
        [$actor, $project, $input] = $this->fixture();
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturn(true);
        $authorization->method('can')->willReturn(true);
        $service = new ProjectMarginReportService(new ProjectMarginCalculator, $authorization);
        $legacyBefore = $service->reportForProjectScope($input + ['_skip_data_mart_meta' => true], [$project->id], $actor);
        $native = $service->exactFinancialSourceRows($input, [$project->id], $actor);
        $output = (new AssistantLiveProjectFinanceProjection)->project($native, (int) $actor->current_organization_id, (int) $project->id);
        self::assertSame('success', $output['status']);
        $rows = array_column($output['financial_evidence']['rows'], null, 'currency');
        self::assertSame('9007199254740993.21', $rows['RUB']['fields']['plan_revenue']);
        self::assertSame('9007199254740994.21', $rows['RUB']['fields']['forecast_revenue']);
        self::assertSame('10.25', $rows['USD']['fields']['plan_revenue']);
        self::assertSame('0.00', $rows['RUB']['fields']['actual_revenue']);
        self::assertCount(2, $native['sources']);
        self::assertSame('2026-09-01', $output['source_refs'][0]['composite_key']['period_start']);
        $legacyAfter = $service->reportForProjectScope($input + ['_skip_data_mart_meta' => true], [$project->id], $actor);
        $stableFields = array_flip(['filters', 'period', 'summary', 'totals_by_currency', 'rows', 'groups', 'sources_coverage', 'warnings', 'drill_down_available']);
        self::assertSame(
            self::normalizeLegacyContractDates(array_intersect_key($legacyBefore, $stableFields)),
            self::normalizeLegacyContractDates(array_intersect_key($legacyAfter, $stableFields)),
        );
        self::assertSame('active', $native['budget_version']['status']);
    }

    public function test_source_permission_revoked_after_report_authorization_prevents_native_exact_publication(): void
    {
        [$actor, $project, $input] = $this->fixture();
        $authorization = $this->createMock(AuthorizationService::class);
        $permissions = [];
        $authorization->method('canCurrent')->willReturnCallback(static function (User $user, string $permission) use (&$permissions): bool {
            $permissions[] = $permission;
            return $permission !== 'budgeting.budgets.view';
        });
        $service = new ProjectMarginReportService(new ProjectMarginCalculator, $authorization);
        try {
            $service->exactFinancialSourceRows($input, [$project->id], $actor);
            self::fail('A report grant cannot bypass a currently denied money source.');
        } catch (AccessDeniedHttpException) {
            self::assertContains('budgeting.project_margin.view', $permissions);
            self::assertContains('budgeting.budgets.view', $permissions);
        }
    }

    public function test_closed_project_cannot_be_read_even_with_financial_report_permissions(): void
    {
        [$actor, $project, $input] = $this->fixture();
        $project->forceFill(['status' => 'completed', 'is_archived' => true])->save();
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturn(true);
        $this->expectException(AccessDeniedHttpException::class);
        (new ProjectMarginReportService(new ProjectMarginCalculator, $authorization))->exactFinancialSourceRows($input, [$project->id], $actor);
    }

    public function test_foreign_project_cannot_be_selected_into_current_organization_financial_scope(): void
    {
        [$actor, $project, $input] = $this->fixture();
        $foreign = Project::factory()->create(['organization_id' => Organization::factory()->create()->id, 'status' => 'active', 'is_archived' => false]);
        $input['project_id'] = $foreign->id;
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturn(true);
        $this->expectException(AccessDeniedHttpException::class);
        (new ProjectMarginReportService(new ProjectMarginCalculator, $authorization))->exactFinancialSourceRows($input, [$foreign->id], $actor);
    }

    private static function normalizeLegacyContractDates(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return ['date_time' => $value->format('Y-m-d\TH:i:s.uP'), 'timezone' => $value->getTimezone()->getName()];
        }

        if (is_array($value)) {
            return array_map(self::normalizeLegacyContractDates(...), $value);
        }

        return $value;
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => 'active', 'is_archived' => false]);
        $contractor = Contractor::query()->create(['organization_id' => $organization->id, 'name' => 'Подрядчик финансового теста']);
        $contract = Contract::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'contractor_id' => $contractor->id, 'number' => 'EXACT-FINANCE', 'date' => '2026-09-01', 'total_amount' => '0.00', 'currency' => 'RUB']);
        $base = ['uuid' => (string) Str::uuid(), 'organization_id' => $organization->id, 'created_at' => now(), 'updated_at' => now()];
        $period = DB::table('budget_periods')->insertGetId($base + ['code' => 'exact_period', 'name' => 'Период', 'period_type' => 'year', 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);
        $scenario = DB::table('budget_scenarios')->insertGetId(array_replace($base, ['uuid' => (string) Str::uuid()]) + ['code' => 'exact_scenario', 'name' => 'Сценарий', 'scenario_type' => 'base', 'is_default' => true, 'is_active' => true]);
        $version = DB::table('budget_versions')->insertGetId(array_replace($base, ['uuid' => (string) Str::uuid()]) + ['budget_period_id' => $period, 'scenario_id' => $scenario, 'budget_kind' => 'bdr', 'version_number' => 1, 'name' => 'Действующий бюджет', 'status' => 'active']);
        $article = DB::table('budget_articles')->insertGetId(array_replace($base, ['uuid' => (string) Str::uuid()]) + ['code' => 'exact_revenue', 'name' => 'Выручка', 'budget_kind' => 'bdr', 'flow_direction' => 'income']);
        $center = DB::table('responsibility_centers')->insertGetId(array_replace($base, ['uuid' => (string) Str::uuid()]) + ['code' => 'exact_center', 'name' => 'Центр', 'center_type' => 'project']);
        foreach (['RUB' => ['9007199254740993.21', '9007199254740994.21'], 'USD' => ['10.25', '11.25']] as $currency => [$plan, $forecast]) {
            $line = DB::table('budget_lines')->insertGetId(['uuid' => (string) Str::uuid(), 'budget_version_id' => $version, 'budget_article_id' => $article, 'responsibility_center_id' => $center,
                'project_id' => $project->id, 'contract_id' => $contract->id, 'counterparty_id' => $contractor->id, 'currency' => $currency]);
            DB::table('budget_amounts')->insert(['budget_line_id' => $line, 'month' => '2026-09-01', 'plan_amount' => $plan, 'forecast_amount' => $forecast, 'currency' => $currency]);
            DB::table('budget_amounts')->insert(['budget_line_id' => $line, 'month' => '2026-10-01', 'plan_amount' => '99.00', 'forecast_amount' => '99.00', 'currency' => $currency]);
        }
        return [$actor, $project, ['organization_id' => $organization->id, 'project_id' => $project->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => null]];
    }
}
