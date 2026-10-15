<?php

declare(strict_types=1);

namespace Tests\Feature\Budgeting;

use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetImportBatch;
use App\BusinessModules\Features\Budgeting\Models\BudgetImportRow;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Services\BudgetPeriodClosureService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BudgetEmptyImportPostgresTest extends TestCase
{
    use RefreshDatabase;

    private const EMPTY_MESSAGE = 'В файле нет строк данных. Заполните шаблон и повторите проверку.';

    public function test_header_only_preview_returns_a_russian_validation_error_without_creating_a_batch(): void
    {
        [$user, $version, $line] = $this->fixture();
        $this->withoutMiddleware()->actingAs($user);
        $this->postJson("/api/v1/admin/budgeting/versions/{$version->uuid}/import/preview", [
            'file' => UploadedFile::fake()->createWithContent('empty.csv', "статья;цфо;месяц;план\n"),
            'mapping_mode' => 'by_code',
        ])->assertStatus(422)->assertJsonPath('message', self::EMPTY_MESSAGE);
        self::assertSame(0, BudgetImportBatch::query()->where('budget_version_id', $version->id)->count());
        $this->assertPlanPreserved($version, $line);
    }

    public static function emptyBatches(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('emptyBatches')]
    public function test_old_empty_or_unusable_batch_cannot_erase_existing_budget_lines(bool $withUnusableRow): void
    {
        [$user, $version, $line] = $this->fixture();
        $batch = BudgetImportBatch::create(['organization_id' => $version->organization_id, 'budget_version_id' => $version->id, 'source_format' => 'csv', 'mapping_mode' => 'by_code', 'status' => 'previewed', 'uploaded_by' => $user->id, 'preview_summary' => ['rows_total' => $withUnusableRow ? 1 : 0, 'rows_invalid' => 0], 'error_summary' => []]);
        if ($withUnusableRow) {
            BudgetImportRow::create(['budget_import_batch_id' => $batch->id, 'row_number' => 2, 'raw_payload' => [], 'normalized_payload' => [], 'validation_status' => 'valid', 'validation_errors' => [], 'validation_warnings' => []]);
        }
        $this->withoutMiddleware()->actingAs($user);
        $this->postJson("/api/v1/admin/budgeting/versions/{$version->uuid}/import/commit", [
            'import_batch_id' => $batch->uuid,
            'mode' => 'replace_lines',
        ])->assertStatus(422)->assertJsonPath('message', self::EMPTY_MESSAGE);
        self::assertSame('preview_failed', $batch->fresh()->status);
        self::assertNotContains('pending_imports', array_column(app(BudgetPeriodClosureService::class)->blockers($version->period), 'code'));
        $this->assertPlanPreserved($version, $line);
    }

    public static function versionStatuses(): array
    {
        return [['draft', true], ['on_approval', false], ['approved', false], ['active', false], ['replaced', false], ['archived', false]];
    }

    #[DataProvider('versionStatuses')]
    public function test_only_imports_that_can_still_be_committed_block_period_closure(string $status, bool $blocks): void
    {
        [$user, $version] = $this->fixture();
        $version->forceFill(['status' => $status])->save();
        BudgetImportBatch::create(['organization_id' => $version->organization_id, 'budget_version_id' => $version->id, 'source_format' => 'csv', 'mapping_mode' => 'by_code', 'status' => 'previewed', 'uploaded_by' => $user->id, 'preview_summary' => ['rows_total' => 1, 'rows_invalid' => 0], 'error_summary' => []]);

        $codes = array_column(app(BudgetPeriodClosureService::class)->blockers($version->period), 'code');

        self::assertSame($blocks, in_array('pending_imports', $codes, true));
        self::assertSame(in_array($status, ['draft', 'on_approval', 'approved'], true), in_array('unfinished_versions', $codes, true));
    }

    public function test_a_nonempty_file_with_zero_amounts_can_still_be_imported(): void
    {
        [$user, $version] = $this->fixture();
        $this->withoutMiddleware()->actingAs($user);
        $preview = $this->postJson("/api/v1/admin/budgeting/versions/{$version->uuid}/import/preview", [
            'file' => UploadedFile::fake()->createWithContent('zero.csv', "статья;цфо;месяц;план;прогноз\nWORKS;PTO;2026-10;0;0\n"),
            'mapping_mode' => 'by_code',
        ])->assertOk()->assertJsonPath('data.status', 'previewed');
        $this->postJson("/api/v1/admin/budgeting/versions/{$version->uuid}/import/commit", [
            'import_batch_id' => $preview->json('data.id'),
            'mode' => 'replace_lines',
        ])->assertOk()->assertJsonPath('data.status', 'committed');
        $line = BudgetLine::query()->where('budget_version_id', $version->id)->sole();
        self::assertSame(0.0, (float) BudgetAmount::query()->where('budget_line_id', $line->id)->sum('plan_amount'));
    }

    private function assertPlanPreserved(BudgetVersion $version, BudgetLine $line): void
    {
        self::assertSame($line->id, BudgetLine::query()->where('budget_version_id', $version->id)->sole()->id);
        self::assertSame(100000.0, (float) BudgetAmount::query()->where('budget_line_id', $line->id)->sum('plan_amount'));
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $user->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $period = BudgetPeriod::create(['organization_id' => $organization->id, 'code' => '2026-10', 'name' => 'Октябрь', 'period_type' => 'month', 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-31', 'status' => 'open']);
        $scenario = BudgetScenario::create(['organization_id' => $organization->id, 'code' => 'BASE', 'name' => 'Базовый', 'scenario_type' => 'base', 'is_default' => true, 'is_active' => true]);
        $article = BudgetArticle::create(['organization_id' => $organization->id, 'code' => 'WORKS', 'name' => 'Отделка', 'budget_kind' => 'bdds', 'flow_direction' => 'outflow', 'management_cost_class' => 'labor', 'is_leaf' => true, 'is_active' => true]);
        $center = ResponsibilityCenter::create(['organization_id' => $organization->id, 'code' => 'PTO', 'name' => 'ПТО', 'center_type' => 'project', 'is_active' => true]);
        $version = BudgetVersion::create(['organization_id' => $organization->id, 'budget_period_id' => $period->id, 'scenario_id' => $scenario->id, 'budget_kind' => 'bdds', 'version_number' => 1, 'name' => 'Бюджет ПТО', 'status' => 'draft', 'workflow_history' => []]);
        $line = BudgetLine::create(['budget_version_id' => $version->id, 'budget_article_id' => $article->id, 'responsibility_center_id' => $center->id, 'currency' => 'RUB']);
        BudgetAmount::create(['budget_line_id' => $line->id, 'month' => '2026-10-01', 'plan_amount' => 100000, 'forecast_amount' => 100000, 'currency' => 'RUB']);

        return [$user, $version, $line];
    }
}
