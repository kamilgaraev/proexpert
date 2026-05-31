<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\StagingAreaService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\VoiceCommandService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class EstimateImportExportControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_status_validation_is_localized_and_does_not_call_service_without_job_id(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();

        $this->mock(EstimateImportService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getImportStatus');
        });

        $missing = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/estimates/import/status");
        $missing->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Укажите задачу импорта.');
        $this->assertStringNotContainsString('Job ID', (string) $missing->json('message'));

        $invalid = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/estimates/import/status/not-a-uuid");
        $invalid->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Некорректный идентификатор задачи импорта.');
        $this->assertStringNotContainsString('Invalid Job', (string) $invalid->json('message'));
    }

    public function test_import_history_normalizes_admin_limit_before_service_call(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();

        $this->mock(EstimateImportService::class, function (MockInterface $mock) use ($context): void {
            $mock->shouldReceive('getImportHistory')
                ->once()
                ->with($context->organization->id, 100)
                ->andReturn(collect());
        });

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/estimates/import/history?limit=10000");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', []);
    }

    public function test_import_map_returns_preview_validation_and_file_totals(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();

        $this->mock(EstimateImportService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('preview')
                ->once()
                ->with('session-1', [])
                ->andReturn(new EstimateImportDTO(
                    fileName: 'estimate.xlsx',
                    fileSize: 1024,
                    fileFormat: 'xlsx',
                    sections: [],
                    items: [[
                        'row_number' => 2,
                        'item_name' => 'Монтаж стоек каркаса',
                        'unit' => 'м3',
                        'quantity' => 0.464,
                        'unit_price' => 4500,
                        'current_total_amount' => 2088,
                        'code' => null,
                    ]],
                    totals: [
                        'total_amount' => 2088,
                        'items_count' => 1,
                    ],
                    metadata: [],
                    validationSummary: [
                        'errors' => ['Проверьте строку 2.'],
                        'warnings' => ['Разделы не найдены.'],
                    ],
                ));
        });

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/import/map", [
                'file_id' => 'session-1',
                'column_mapping' => [],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.preview.items.0.total', 2088)
            ->assertJsonPath('data.validation.errors.0', 'Проверьте строку 2.')
            ->assertJsonPath('data.validation.warnings.0', 'Разделы не найдены.');
    }

    public function test_staging_and_voice_command_errors_are_localized_without_broken_text(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();

        $this->mock(StagingAreaService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('buildPreview')
                ->once()
                ->andThrow(new \RuntimeException('storage exploded'));
        });

        $staging = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/import/staging", [
                'session_id' => 'session-1',
            ]);
        $staging->assertStatus(500)
            ->assertJsonPath('message', 'Не удалось получить предварительный просмотр импорта.');
        $this->assertStringNotContainsString('storage exploded', (string) $staging->json('message'));

        $this->mock(VoiceCommandService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('parseCommand')
                ->once()
                ->andReturn(['success' => false]);
        });

        $voice = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/import/voice-command", [
                'text' => 'сделай красиво',
                'rows' => [],
            ]);
        $voice->assertStatus(422)
            ->assertJsonPath('message', 'Команда не распознана.');
    }

    public function test_excel_export_failure_returns_generic_message_without_exception_leak(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $this->allowAdminAccess();

        $this->mock(EstimateExportService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('exportToExcel')
                ->once()
                ->andThrow(new \RuntimeException('S3 credentials are missing'));
        });

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/export/excel?include_sections=true");

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Не удалось экспортировать смету.');
        $this->assertStringNotContainsString('S3 credentials', (string) $response->json('message'));
    }

    public function test_official_form_exports_require_contract_act_with_business_messages(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project, ['contract_id' => null]);
        $this->allowAdminAccess();

        foreach ([
            'ks2' => 'Для экспорта КС-2 нужен хотя бы один акт по контракту.',
            'ks3' => 'Для экспорта КС-3 нужен хотя бы один акт по контракту.',
            'summary' => 'Для экспорта сводки нужен хотя бы один акт по контракту.',
        ] as $endpoint => $message) {
            $response = $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/export/{$endpoint}");

            $response->assertStatus(400)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', $message);
        }
    }

    private function createEstimate(Organization $organization, Project $project, array $overrides = []): Estimate
    {
        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'IMP-EXP-' . random_int(10000, 99999),
            'name' => 'Import export workflow',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => '2026-06-01',
            'total_direct_costs' => 0,
            'total_overhead_costs' => 0,
            'total_estimated_profit' => 0,
            'total_amount' => 0,
            'total_amount_with_vat' => 0,
        ], $overrides));
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
