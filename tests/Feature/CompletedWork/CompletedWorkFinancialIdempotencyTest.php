<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\Models\CompletedWork;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Services\RateCoefficient\RateCoefficientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CompletedWorkFinancialIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\AuthorizationService::class);
        \Illuminate\Support\Facades\Http::fake(['nominatim.openstreetmap.org/*' => \Illuminate\Support\Facades\Http::response([], 200)]);
        $this->mock(RateCoefficientService::class)->shouldReceive('calculateAdjustedValueDetailed')->andReturnUsing(
            static fn ($organization, $amount, ...$rest): array => ['final' => round($amount * 1.1, 2), 'applications' => []],
        );
    }

    public function test_repeated_material_updates_preserve_base_and_adjusted_money_and_allow_clear(): void
    {
        [$context, $project, $work] = $this->fixture();
        $unit = MeasurementUnit::query()->where('organization_id', $context->organization->id)->firstOrFail();
        $material = Material::query()->create([
            'organization_id' => $context->organization->id, 'name' => 'Материал',
            'measurement_unit_id' => $unit->id,
        ]);
        $url = "/api/v1/admin/projects/{$project->id}/works/{$work->id}";
        foreach ([1, 2] as $attempt) {
            $this->withHeaders($context->authHeaders())->putJson($url, [
                'materials' => [['material_id' => $material->id, 'quantity' => 2, 'unit_price' => 20]],
            ])->assertOk();
            self::assertSame('550.00', $work->fresh()->total_amount);
            self::assertSame(500.0, (float) data_get($work->fresh()->additional_info, 'financial_calculation.base_total_amount'));
            self::assertSame(1, $work->materials()->count());
        }
        $this->withHeaders($context->authHeaders())->putJson($url, ['description' => 'Изменено описание'])->assertOk();
        self::assertSame(1, $work->materials()->count());
        foreach ([1, 2] as $attempt) {
            $this->withHeaders($context->authHeaders())->putJson($url, ['materials' => []])->assertOk();
            self::assertSame(0, $work->materials()->count());
            self::assertSame('550.00', $work->fresh()->total_amount);
        }
    }

    public function test_calendar_dates_explicit_zero_and_nullable_clear_are_preserved(): void
    {
        [$context, $project, $work] = $this->fixture();
        $url = "/api/v1/admin/projects/{$project->id}/works/{$work->id}";
        self::assertSame('2026-09-20', $work->completion_date->toDateString());
        $this->withHeaders($context->authHeaders())->putJson($url, [
            'completion_date' => '2026-10-01', 'price' => 0, 'total_amount' => 0,
            'notes' => null,
        ])->assertOk();
        self::assertSame('2026-10-01', $work->fresh()->completion_date->toDateString());
        self::assertSame('0.00', $work->fresh()->price);
        self::assertSame('0.00', $work->fresh()->total_amount);
        self::assertNull($work->fresh()->notes);
        $this->withHeaders($context->authHeaders())->putJson($url, ['completion_date' => '2026-02-30'])
            ->assertUnprocessable()->assertJsonValidationErrors('completion_date');
        self::assertSame('2026-10-01', $work->fresh()->completion_date->toDateString());
    }

    private function fixture(): array
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $response = $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works",
            ['project_id' => $project->id, 'quantity' => 10, 'price' => 50,
                'completion_date' => '2026-09-20', 'status' => 'pending', 'notes' => 'Исходное примечание'],
        )->assertCreated();
        $work = CompletedWork::query()->findOrFail($response->json('data.id'));
        self::assertSame('550.00', $work->total_amount);

        return [$context, $project, $work];
    }
}
