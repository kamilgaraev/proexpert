<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\Enums\Billing\PackageAccessSource;
use App\Enums\Billing\PackageSubscriptionStatus;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class RagIndexingCoordinatorEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_index_selects_only_organizations_with_an_assistant_package(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $this->subscribe($fixture->foreignOrganization, 'machinery');

        $result = $this->coordinator()->queueAllActiveOrganizations();

        $this->assertSame([(int) $fixture->organization->id], $result['organization_ids']);
    }

    public function test_bulk_index_preserves_active_trial_and_grace_access(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $trial = Organization::factory()->verified()->create();
        $grace = Organization::factory()->verified()->create();
        $this->subscribe($trial, 'working-entry', PackageSubscriptionStatus::Trialing, PackageAccessSource::Trial);
        $this->subscribe($grace, 'working-entry', PackageSubscriptionStatus::Grace, PackageAccessSource::PaidPackage);

        $result = $this->coordinator()->queueAllActiveOrganizations();

        $this->assertEqualsCanonicalizing(
            [(int) $fixture->organization->id, (int) $trial->id, (int) $grace->id],
            $result['organization_ids'],
        );
    }

    public function test_bulk_index_respects_organization_activity_and_limits_after_entitlement_filter(): void
    {
        Organization::factory()->verified()->create();
        $fixture = AssistantRealAuthorizationFixture::create();
        $inactive = Organization::factory()->verified()->create(['is_active' => false]);
        $this->subscribe($inactive, 'working-entry');
        $coordinator = $this->coordinator();

        $limited = $coordinator->queueAllActiveOrganizations(limit: 1);
        $includingInactive = $coordinator->queueAllActiveOrganizations(includeInactive: true);

        $this->assertSame([(int) $fixture->organization->id], $limited['organization_ids']);
        $this->assertEqualsCanonicalizing(
            [(int) $fixture->organization->id, (int) $inactive->id],
            $includingInactive['organization_ids'],
        );
    }

    public function test_bulk_index_requires_the_assistant_module_to_be_active(): void
    {
        AssistantRealAuthorizationFixture::create();
        Module::query()->where('slug', 'ai-assistant')->update(['is_active' => false]);

        $result = $this->coordinator()->queueAllActiveOrganizations();

        $this->assertSame([], $result['organization_ids']);
    }

    private function coordinator(): RagIndexingCoordinator
    {
        Queue::fake();

        return new RagIndexingCoordinator($this->mock(RagIndexer::class));
    }

    private function subscribe(
        Organization $organization,
        string $packageSlug,
        PackageSubscriptionStatus $status = PackageSubscriptionStatus::Active,
        PackageAccessSource $accessSource = PackageAccessSource::PaidPackage,
    ): void {
        $isTrial = $status === PackageSubscriptionStatus::Trialing;
        $isGrace = $status === PackageSubscriptionStatus::Grace;
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $organization->id,
            'status' => $isGrace ? 'grace' : 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'current_period_start_at' => $isTrial ? null : now()->subDays(30),
            'current_period_end_at' => $isGrace ? now()->subHour() : now()->addDays(30),
            'auto_renew_enabled' => false,
            'grace_ends_at' => $isGrace ? now()->addDay() : null,
        ]);

        OrganizationPackageSubscription::query()->create([
            'organization_id' => $organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => $packageSlug,
            'status' => $status,
            'access_source' => $accessSource,
            'price_paid' => 39900,
            'current_period_start_at' => $isTrial ? null : now()->subDays(30),
            'current_period_end_at' => $isTrial ? null : ($isGrace ? now()->subHour() : now()->addDays(30)),
            'trial_started_at' => $isTrial ? now()->subHour() : null,
            'trial_ends_at' => $isTrial ? now()->addDay() : null,
        ]);
    }
}
