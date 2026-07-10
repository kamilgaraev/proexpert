<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Events\PurchaseRequestCreated;
use App\BusinessModules\Features\Procurement\Services\PurchaseRequestService;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

final class PurchaseRequestServiceTransactionTest extends TestCase
{
    public function test_purchase_request_created_event_and_cache_invalidation_are_discarded_when_outer_transaction_rolls_back(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $siteRequest = SiteRequest::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'title' => 'Материалы на объект',
            'status' => SiteRequestStatusEnum::APPROVED,
            'priority' => 'medium',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST,
            'material_name' => 'Монтажная пена',
            'material_quantity' => 12.5,
            'material_unit' => 'баллон',
        ]);
        Event::fake([PurchaseRequestCreated::class]);
        Cache::spy();

        try {
            DB::transaction(function () use ($siteRequest, $user): void {
                app(PurchaseRequestService::class)->createFromSiteRequest($siteRequest, (int) $user->id);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        Event::assertNotDispatched(PurchaseRequestCreated::class);
        Cache::shouldNotHaveReceived('forget', [
            "procurement_purchase_requests_{$organization->id}",
        ]);
    }
}
