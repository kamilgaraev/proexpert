<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Services\ProcurementChainService;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProcurementChainFulfillmentStageLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_purchase_source_has_completed_stage_label(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $siteRequest = SiteRequest::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'title' => 'Цемент на площадку',
            'status' => SiteRequestStatusEnum::APPROVED,
            'priority' => 'medium',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST,
            'material_name' => 'Цемент М500',
            'material_quantity' => 100,
            'material_unit' => 'кг',
        ]);
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'site_request_id' => $siteRequest->id,
            'request_number' => 'ЗМ-TEST-001',
            'status' => PurchaseRequestStatusEnum::PENDING,
            'budget_currency' => 'RUB',
        ]);

        $siteRequest->update([
            'metadata' => [
                'fulfillment_decision' => [
                    'source' => 'purchase',
                    'purchase_quantity' => 100,
                    'purchase_request_id' => $purchaseRequest->id,
                ],
            ],
        ]);

        $summary = app(ProcurementChainService::class)->forSiteRequest($siteRequest->fresh());
        $stage = $summary->stages->firstWhere('key', 'fulfillment_source_required');

        $this->assertNotNull($stage);
        $this->assertSame('done', $stage->status);
        $this->assertSame('Источник обеспечения выбран', $stage->label);
        $this->assertSame(
            'Источник обеспечения определён, потребность передана в выбранный контур.',
            $stage->description
        );
    }
}
