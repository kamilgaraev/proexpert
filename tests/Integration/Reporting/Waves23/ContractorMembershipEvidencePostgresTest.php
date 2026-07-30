<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services\ContractorMembershipEvidenceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ContractorMembershipEvidencePostgresTest extends TestCase
{
    use DatabaseTransactions;

    public function test_membership_projection_is_reconstructed_from_append_only_evidence_at_as_of(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires PostgreSQL JSONB evidence queries.');
        }
        $organizationId = random_int(810_000_000, 819_999_999);
        $contractorId = random_int(820_000_000, 829_999_999);
        $profileId = random_int(830_000_000, 839_999_999);
        $categoryId = random_int(840_000_000, 849_999_999);
        $profileOrganizationId = random_int(850_000_000, 859_999_999);
        $observedAt = CarbonImmutable::now('UTC')->addSecond();
        foreach ([
            ['contractor', $contractorId, $organizationId, [
                'id' => $contractorId,
                'organization_id' => $organizationId,
                'source_organization_id' => $profileOrganizationId,
            ]],
            ['profile', $profileId, $profileOrganizationId, [
                'id' => $profileId,
                'organization_id' => $profileOrganizationId,
            ]],
            ['profile_category', random_int(860_000_000, 869_999_999), null, [
                'profile_id' => $profileId,
                'category_id' => $categoryId,
            ]],
        ] as $index => [$subjectType, $subjectId, $ownerId, $payload]) {
            DB::table('contractor_scorecard_membership_events')->insert([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'organization_id' => $ownerId,
                'observed_at' => $observedAt,
                'is_deleted' => false,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'evidence_hash' => hash('sha256', "membership:{$organizationId}:{$index}"),
            ]);
        }

        $evidence = $this->app->make(ContractorMembershipEvidenceResolver::class)
            ->resolve($organizationId, $observedAt->addSecond());

        self::assertSame($profileId, $evidence->profileByContractor[$contractorId]);
        self::assertArrayHasKey($categoryId, $evidence->categoriesByProfile[$profileId]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $evidence->sourceHash);
    }
}
