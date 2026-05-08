<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

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
        self::assertSame(19900, (int) $plans['business']->price);
        self::assertSame(10, (int) $plans['business']->max_users);
        self::assertSame(29900, (int) $plans['profi']->price);
        self::assertSame(30, (int) $plans['profi']->max_users);
        self::assertSame(99000, (int) $plans['enterprise']->price);
        self::assertSame(100, (int) $plans['enterprise']->max_users);
        self::assertSame(500, (int) $plans['enterprise']->max_contractor_invitations);
    }
}
