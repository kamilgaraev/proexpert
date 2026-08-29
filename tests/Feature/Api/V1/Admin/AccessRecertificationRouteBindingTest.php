<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationCampaign;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationItem;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class AccessRecertificationRouteBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_uuid_item_routes_reach_reassign_and_decision_actions(): void
    {
        $this->withoutMiddleware(AuthorizeMiddleware::class);

        $context = AdminApiTestContext::create();
        $subject = $this->createOrganizationParticipant($context);
        $initialReviewer = $this->createOrganizationParticipant($context);
        $campaign = AccessRecertificationCampaign::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Проверка маршрутов UUID',
            'owner_user_id' => $initialReviewer->id,
            'created_by_user_id' => $context->user->id,
            'status' => 'active',
        ]);
        $item = AccessRecertificationItem::query()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $context->organization->id,
            'reviewer_user_id' => $initialReviewer->id,
            'subject_user_id' => $subject->id,
            'role_slug' => 'accountant',
            'assignment_snapshot_hash' => hash('sha256', (string) $campaign->id),
            'status' => 'pending',
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/access-recertification/items/{$item->id}/reassign", [
                'reviewer_user_id' => $subject->id,
                'reason' => 'Независимая проверка маршрута',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('access_recertification_items', [
            'id' => $item->id,
            'reviewer_user_id' => $initialReviewer->id,
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/access-recertification/items/{$item->id}/decisions", [
                'decision' => 'approve',
                'reason' => 'Доступ соответствует обязанностям сотрудника',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('access_recertification_items', [
            'id' => $item->id,
            'status' => 'pending',
        ]);
    }

    private function createOrganizationParticipant(AdminApiTestContext $context): User
    {
        $user = User::factory()->create([
            'current_organization_id' => $context->organization->id,
        ]);
        $context->organization->users()->attach($user->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        return $user;
    }
}
