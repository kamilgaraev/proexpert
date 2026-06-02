<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Http\Resources\Billing\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MarketReadySubscriptionPlansMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_ready_subscription_plans_are_synced_by_migrations(): void
    {
        $plans = DB::table('subscription_plans')
            ->whereIn('slug', ['free', 'start', 'business', 'profi', 'enterprise'])
            ->get()
            ->keyBy('slug');

        self::assertCount(5, $plans);
        self::assertSame(9900, (int) $plans['start']->price);
        self::assertSame(7, (int) $plans['start']->max_users);
        self::assertSame(24900, (int) $plans['business']->price);
        self::assertSame(15, (int) $plans['business']->max_users);
        self::assertSame(39900, (int) $plans['profi']->price);
        self::assertSame(40, (int) $plans['profi']->max_users);
        self::assertSame(99000, (int) $plans['enterprise']->price);
        self::assertSame(100, (int) $plans['enterprise']->max_users);
        self::assertSame(500, (int) $plans['enterprise']->max_contractor_invitations);
    }

    public function test_subscription_plan_resource_exposes_user_limits(): void
    {
        $plan = SubscriptionPlan::query()
            ->where('slug', 'business')
            ->firstOrFail();

        $payload = (new SubscriptionPlanResource($plan))->resolve();

        self::assertSame(15, $payload['max_users']);
        self::assertSame(50, $payload['max_contractor_invitations']);
    }
}
