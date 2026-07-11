<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class EstimateGenerationPackageApiTest extends TestCase
{
    public function test_packages_endpoint_returns_lightweight_package_summaries(): void
    {
        [$user, $project, $session] = $this->makeGenerationSession();

        EstimateGenerationPackage::query()->create([
            'session_id' => $session->id,
            'key' => 'foundation',
            'title' => 'Фундамент',
            'scope_type' => 'foundation',
            'status' => 'ready_for_review',
            'generation_stage' => 'quality_check',
            'generation_progress' => 100,
            'target_items_min' => 30,
            'target_items_max' => 55,
            'actual_items_count' => 42,
            'totals' => [
                'total_cost' => 1200000,
                'items_count' => 42,
                'total_items_count' => 42,
                'priced_items_count' => 14,
                'operation_items_count' => 0,
            ],
            'quality_summary' => ['level' => 'passed', 'critical_flags' => [], 'warning_flags' => []],
            'sort_order' => 100,
        ]);

        $request = Request::create('/packages', 'GET');
        $request->setUserResolver(static fn (): User => $user);

        $response = app(EstimateGenerationController::class)->packages($request, $project, $session);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertCount(1, $payload['data']['packages']);
        $this->assertSame('foundation', $payload['data']['packages'][0]['key']);
        $this->assertArrayNotHasKey('input', $payload['data']['packages'][0]);
        $this->assertSame(14, $payload['data']['packages'][0]['items_breakdown']['priced']);
        $this->assertSame(0, $payload['data']['packages'][0]['items_breakdown']['operations']);
        $this->assertSame(1, $payload['data']['summary']['total']);
        $this->assertSame(1, $payload['data']['summary']['ready']);
        $this->assertSame(14, $payload['data']['summary']['priced_items_count']);
        $this->assertSame(0, $payload['data']['summary']['operation_items_count']);
    }

    public function test_package_detail_endpoint_returns_items_for_selected_package_only(): void
    {
        [$user, $project, $session] = $this->makeGenerationSession();
        $foundation = EstimateGenerationPackage::query()->create([
            'session_id' => $session->id,
            'key' => 'foundation',
            'title' => 'Фундамент',
            'scope_type' => 'foundation',
            'status' => 'ready_for_review',
            'target_items_min' => 30,
            'target_items_max' => 55,
            'sort_order' => 100,
        ]);
        $walls = EstimateGenerationPackage::query()->create([
            'session_id' => $session->id,
            'key' => 'walls',
            'title' => 'Стены',
            'scope_type' => 'walls',
            'status' => 'planned',
            'target_items_min' => 28,
            'target_items_max' => 52,
            'sort_order' => 200,
        ]);

        EstimateGenerationPackageItem::query()->create([
            'package_id' => $foundation->id,
            'key' => 'foundation.concrete',
            'item_type' => 'priced_work',
            'name' => 'Бетонирование фундаментной ленты',
            'unit' => 'м3',
            'quantity' => 18,
            'unit_price' => 9800,
            'total_cost' => 176400,
            'metadata' => [
                'work_composition' => ['Подготовка основания', 'Монтаж опалубки', 'Укладка бетона'],
            ],
            'sort_order' => 100,
        ]);
        EstimateGenerationPackageItem::query()->create([
            'package_id' => $foundation->id,
            'key' => 'foundation.operation',
            'parent_key' => 'foundation.concrete',
            'level' => 1,
            'item_type' => 'operation',
            'name' => 'Контроль геометрии',
            'unit' => 'операция',
            'quantity' => 1,
            'unit_price' => 0,
            'total_cost' => 0,
            'sort_order' => 200,
        ]);
        EstimateGenerationPackageItem::query()->create([
            'package_id' => $walls->id,
            'key' => 'walls.masonry',
            'item_type' => 'priced_work',
            'name' => 'Кладка стен',
            'unit' => 'м3',
            'quantity' => 55,
            'unit_price' => 7600,
            'total_cost' => 418000,
            'sort_order' => 100,
        ]);

        $request = Request::create('/packages/foundation', 'GET');
        $request->setUserResolver(static fn (): User => $user);

        $response = app(EstimateGenerationController::class)->package($request, $project, $session, $foundation);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame('foundation', $payload['data']['package']['key']);
        $this->assertCount(1, $payload['data']['items']);
        $this->assertSame('foundation.concrete', $payload['data']['items'][0]['key']);
        $this->assertSame('priced_work', $payload['data']['items'][0]['item_type']);
        $this->assertCount(3, $payload['data']['items'][0]['work_composition']);
        $this->assertSame(1, $payload['data']['meta']['priced_items_count']);
        $this->assertSame(0, $payload['data']['meta']['operation_items_count']);
        $this->assertSame(0, $payload['data']['meta']['hidden_service_items_count']);
    }

    /**
     * @return array{0: User, 1: Project, 2: EstimateGenerationSession}
     */
    private function makeGenerationSession(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'ready_to_apply',
            'processing_stage' => 'quality_check',
            'processing_progress' => 100,
            'input_payload' => [
                'description' => 'Дом 150 м2',
            ],
            'problem_flags' => [],
        ]);

        return [$user, $project, $session];
    }
}
