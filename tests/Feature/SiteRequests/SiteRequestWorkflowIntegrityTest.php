<?php

declare(strict_types=1);

namespace Tests\Feature\SiteRequests;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestGroup;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class SiteRequestWorkflowIntegrityTest extends TestCase
{
    public function test_approved_material_request_cannot_be_completed_before_fulfillment_or_delivery(): void
    {
        Event::fake();

        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $request = $this->createMaterialRequest($organization, $project, $user, SiteRequestStatusEnum::APPROVED);

        $this->expectException(DomainException::class);

        try {
            app(SiteRequestService::class)->changeStatus(
                $request,
                $user->id,
                SiteRequestStatusEnum::COMPLETED->value
            );
        } finally {
            $this->assertDatabaseHas('site_requests', [
                'id' => $request->id,
                'status' => SiteRequestStatusEnum::APPROVED->value,
            ]);
        }
    }

    public function test_group_status_is_synchronized_when_child_request_status_changes(): void
    {
        Event::fake();

        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $group = SiteRequestGroup::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'title' => 'Concrete group',
            'status' => SiteRequestStatusEnum::PENDING->value,
        ]);
        $request = $this->createMaterialRequest(
            $organization,
            $project,
            $user,
            SiteRequestStatusEnum::PENDING,
            $group->id
        );

        app(SiteRequestService::class)->changeStatus(
            $request,
            $user->id,
            SiteRequestStatusEnum::APPROVED->value
        );

        $this->assertDatabaseHas('site_request_groups', [
            'id' => $group->id,
            'status' => SiteRequestStatusEnum::APPROVED->value,
        ]);
    }

    private function createMaterialRequest(
        Organization $organization,
        Project $project,
        User $user,
        SiteRequestStatusEnum $status,
        ?int $groupId = null
    ): SiteRequest {
        return SiteRequest::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'site_request_group_id' => $groupId,
            'title' => 'Concrete delivery',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => $status->value,
            'priority' => 'medium',
            'material_name' => 'Concrete',
            'material_quantity' => 5,
            'material_unit' => 'm3',
        ]);
    }
}
