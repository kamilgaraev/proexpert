<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProcurementRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProjectPulseRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\SiteRequestRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\WarehouseRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\WorkCompletionRagSource;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\CompletedWork;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use Tests\TestCase;

class RagSourceCollectorsTest extends TestCase
{
    public function test_remaining_collectors_scope_by_organization_and_project(): void
    {
        config()->set('ai-assistant.rag.enabled', true);

        [$organization, $projectA, $projectB, $user] = $this->seedRagDomainRecords();

        foreach ($this->collectors() as [$collector, $sourceType]) {
            $allChunks = iterator_to_array($collector->collectForOrganization($organization->id));
            $projectChunks = iterator_to_array($collector->collectForOrganization($organization->id, $projectA->id));

            $this->assertNotEmpty($allChunks, "{$sourceType} collector returned no organization chunks");
            $this->assertNotEmpty($projectChunks, "{$sourceType} collector returned no project chunks");
            $this->assertSame(array_fill(0, count($allChunks), $sourceType), array_map(static fn ($chunk): string => $chunk->sourceType, $allChunks));
            $this->assertSame(array_fill(0, count($allChunks), $organization->id), array_map(static fn ($chunk): int => $chunk->organizationId, $allChunks));
            $this->assertSame(array_fill(0, count($projectChunks), $projectA->id), array_map(static fn ($chunk): int => (int) $chunk->projectId, $projectChunks));
            $this->assertNotContains($projectB->id, array_map(static fn ($chunk): ?int => $chunk->projectId, $projectChunks));
            $this->assertNotSame('', trim($projectChunks[0]->content));
            $this->assertNotSame('', trim($projectChunks[0]->title));
            $this->assertNotEmpty($projectChunks[0]->metadata);
        }
    }

    /**
     * @return array<int, array{0: RagSourceCollectorInterface, 1: string}>
     */
    private function collectors(): array
    {
        return [
            [new ProcurementRagSource(), 'procurement'],
            [new WarehouseRagSource(), 'warehouse'],
            [new SiteRequestRagSource(), 'site_request'],
            [new WorkCompletionRagSource(), 'work_completion'],
            [new ProjectPulseRagSource(), 'project_pulse'],
        ];
    }

    /**
     * @return array{0: Organization, 1: Project, 2: Project, 3: User}
     */
    private function seedRagDomainRecords(): array
    {
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $projectA = Project::factory()->create(['organization_id' => $organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $material = Material::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Бетон B25',
            'code' => 'MAT-B25',
            'is_active' => true,
        ]);
        $workType = WorkType::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Монтаж перекрытий',
            'code' => 'WT-SLAB',
            'is_active' => true,
        ]);

        $siteA = $this->siteRequest($organization->id, $projectA->id, $user->id, 'Материалы для секции А');
        $siteB = $this->siteRequest($organization->id, $projectB->id, $user->id, 'Материалы для секции Б');
        $this->siteRequest($foreignOrganization->id, $foreignProject->id, $user->id, 'Чужая заявка');

        $purchaseA = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'site_request_id' => $siteA->id,
            'assigned_to' => $user->id,
            'request_number' => 'PR-A',
            'status' => 'pending',
            'needed_by' => now()->addDays(3)->toDateString(),
            'budget_amount' => 120000,
            'budget_currency' => 'RUB',
            'notes' => 'Проверить срок поставки',
        ]);
        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseA->id,
            'material_id' => $material->id,
            'name' => 'Бетон B25',
            'quantity' => 12,
            'unit' => 'м3',
        ]);
        PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'site_request_id' => $siteB->id,
            'request_number' => 'PR-B',
            'status' => 'draft',
        ]);

        ProjectMaterialDelivery::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectA->id,
            'material_id' => $material->id,
            'site_request_id' => $siteA->id,
            'purchase_request_id' => $purchaseA->id,
            'status' => 'reserved',
            'requested_quantity' => 12,
            'reserved_quantity' => 8,
            'planned_delivery_date' => now()->addDays(2)->toDateString(),
        ]);
        ProjectMaterialDelivery::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectB->id,
            'material_id' => $material->id,
            'status' => 'requested',
            'requested_quantity' => 5,
        ]);

        CompletedWork::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectA->id,
            'work_type_id' => $workType->id,
            'user_id' => $user->id,
            'quantity' => 10,
            'completed_quantity' => 10,
            'price' => 5000,
            'total_amount' => 50000,
            'completion_date' => now()->toDateString(),
            'status' => 'confirmed',
            'notes' => 'Принято технадзором',
        ]);
        CompletedWork::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectB->id,
            'work_type_id' => $workType->id,
            'user_id' => $user->id,
            'quantity' => 4,
            'completion_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        ProjectPulseReport::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectA->id,
            'scope_type' => 'project',
            'report_date' => now()->toDateString(),
            'period_preset' => 'today',
            'status' => 'risk',
            'ai_status' => 'rules_only',
            'summary' => ['text' => 'Есть риск по срокам'],
            'metrics' => ['risk_count' => 1],
            'urgent_actions' => [['title' => 'Ускорить поставку']],
            'risk_groups' => [['title' => 'График']],
            'finance' => [],
            'activity' => [],
            'recommendations' => [['title' => 'Проверить закупку']],
            'generated_at' => now(),
        ]);
        ProjectPulseReport::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectB->id,
            'scope_type' => 'project',
            'report_date' => now()->toDateString(),
            'status' => 'good',
            'summary' => ['text' => 'Без критичных рисков'],
            'metrics' => [],
            'urgent_actions' => [],
            'risk_groups' => [],
            'finance' => [],
            'activity' => [],
            'recommendations' => [],
            'generated_at' => now(),
        ]);

        return [$organization, $projectA, $projectB, $user];
    }

    private function siteRequest(int $organizationId, int $projectId, int $userId, string $title): SiteRequest
    {
        return SiteRequest::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'user_id' => $userId,
            'title' => $title,
            'description' => 'Нужны материалы на объект',
            'status' => SiteRequestStatusEnum::PENDING->value,
            'priority' => SiteRequestPriorityEnum::HIGH->value,
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'required_date' => now()->addDays(5)->toDateString(),
            'material_name' => 'Бетон B25',
            'material_quantity' => 12,
            'material_unit' => 'м3',
        ]);
    }
}
