<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\Services\Customer\Reporting\Sla\Enums\CustomerActorSide;
use App\Services\Customer\Reporting\Sla\Services\CustomerActorSideResolver;
use App\Services\Customer\Reporting\Sla\Services\HistoricalCustomerActorSideResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

final class CustomerSlaPostgresTest extends TestCase
{
    public function test_deleted_memberships_are_resolved_directly_from_history_at_event_time(): void
    {
        $this->requirePostgres();
        $occurredAt = CarbonImmutable::parse('2026-04-15T12:00:00Z');
        $ownerOrganizationId = random_int(510_000_000, 519_999_999);
        $customerOrganizationId = random_int(520_000_000, 529_999_999);
        $actorId = random_int(530_000_000, 539_999_999);
        $projectId = random_int(540_000_000, 549_999_999);
        $membershipId = random_int(550_000_000, 559_999_999);
        DB::table('customer_membership_history')->insert([
            [
                'membership_kind' => 'organization_user',
                'membership_id' => $membershipId,
                'organization_id' => $ownerOrganizationId,
                'user_id' => $actorId,
                'project_id' => null,
                'role' => null,
                'is_active' => true,
                'valid_from' => '2026-01-01T00:00:00Z',
                'valid_to' => '2026-05-01T00:00:00Z',
                'evidence_hash' => hash('sha256', "actor:{$membershipId}"),
            ],
            [
                'membership_kind' => 'project_organization',
                'membership_id' => $membershipId + 1,
                'organization_id' => $customerOrganizationId,
                'user_id' => null,
                'project_id' => $projectId,
                'role' => 'customer',
                'is_active' => true,
                'valid_from' => '2026-01-01T00:00:00Z',
                'valid_to' => '2026-05-01T00:00:00Z',
                'evidence_hash' => hash('sha256', "project:{$membershipId}"),
            ],
        ]);

        $resolver = new HistoricalCustomerActorSideResolver(new CustomerActorSideResolver);

        self::assertSame(
            $customerOrganizationId,
            $resolver->customerOrganizationId($projectId, $occurredAt),
        );
        self::assertSame(
            CustomerActorSide::DELIVERY_TEAM,
            $resolver->resolve($ownerOrganizationId, $customerOrganizationId, $actorId, $occurredAt),
        );
    }

    public function test_membership_history_is_append_only_in_postgres(): void
    {
        $this->requirePostgres();
        $hash = hash('sha256', bin2hex(random_bytes(16)));
        DB::table('customer_membership_history')->insert([
            'membership_kind' => 'organization_user',
            'membership_id' => random_int(600_000_000, 609_999_999),
            'organization_id' => random_int(610_000_000, 619_999_999),
            'user_id' => random_int(620_000_000, 629_999_999),
            'is_active' => true,
            'valid_from' => '2026-01-01T00:00:00Z',
            'valid_to' => '2026-02-01T00:00:00Z',
            'evidence_hash' => $hash,
        ]);

        try {
            DB::table('customer_membership_history')->where('evidence_hash', $hash)->update(['is_active' => false]);
            self::fail('Append-only trigger must reject UPDATE.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('reporting_fact_is_immutable', $exception->getMessage());
        }
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires PostgreSQL.');
        }
    }
}
