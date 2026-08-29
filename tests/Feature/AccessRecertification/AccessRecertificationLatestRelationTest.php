<?php

declare(strict_types=1);

namespace Tests\Feature\AccessRecertification;

use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationCampaign;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationDecision;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationException;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class AccessRecertificationLatestRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_decision_can_be_eager_loaded_with_uuid_primary_keys(): void
    {
        [$context, $item] = $this->createItem();

        $older = $this->createDecision($context, $item, 'approve', now()->subMinute());
        $newer = $this->createDecision($context, $item, 'revoke', now());

        $loaded = AccessRecertificationItem::query()
            ->with('latestDecision')
            ->findOrFail($item->id);

        $this->assertNotSame($older->id, $loaded->latestDecision?->id);
        $this->assertSame($newer->id, $loaded->latestDecision?->id);
    }

    public function test_latest_exception_can_be_eager_loaded_with_uuid_primary_keys(): void
    {
        [$context, $item] = $this->createItem();

        $older = $this->createException($context, $item, 'requested', now()->subMinute());
        $newer = $this->createException($context, $item, 'approved', now());

        $loaded = AccessRecertificationItem::query()
            ->with('exception')
            ->findOrFail($item->id);

        $this->assertNotSame($older->id, $loaded->exception?->id);
        $this->assertSame($newer->id, $loaded->exception?->id);
    }

    private function createItem(): array
    {
        $context = AdminApiTestContext::create();
        $campaign = AccessRecertificationCampaign::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Проверка UUID-связей',
            'owner_user_id' => $context->user->id,
            'created_by_user_id' => $context->user->id,
        ]);
        $item = AccessRecertificationItem::query()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $context->organization->id,
            'reviewer_user_id' => $context->user->id,
            'subject_user_id' => $context->user->id,
            'role_slug' => 'organization_owner',
            'assignment_snapshot_hash' => hash('sha256', (string) $campaign->id),
        ]);

        return [$context, $item];
    }

    private function createDecision(
        AdminApiTestContext $context,
        AccessRecertificationItem $item,
        string $decision,
        mixed $createdAt,
    ): AccessRecertificationDecision {
        $record = AccessRecertificationDecision::query()->create([
            'campaign_id' => $item->campaign_id,
            'item_id' => $item->id,
            'organization_id' => $context->organization->id,
            'reviewer_user_id' => $context->user->id,
            'decision' => $decision,
            'reason' => 'Регрессионная проверка',
        ]);
        $record->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $record;
    }

    private function createException(
        AdminApiTestContext $context,
        AccessRecertificationItem $item,
        string $status,
        mixed $createdAt,
    ): AccessRecertificationException {
        $record = AccessRecertificationException::query()->create([
            'campaign_id' => $item->campaign_id,
            'item_id' => $item->id,
            'organization_id' => $context->organization->id,
            'status' => $status,
            'requested_by_user_id' => $context->user->id,
            'reason' => 'Регрессионная проверка',
            'valid_until' => now()->addWeek(),
        ]);
        $record->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $record;
    }
}
