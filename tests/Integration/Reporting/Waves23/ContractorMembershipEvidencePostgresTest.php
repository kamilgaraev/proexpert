<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services\ContractorMembershipEvidenceResolver;
use App\Models\Contractor;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;
use Throwable;

final class ContractorMembershipEvidencePostgresTest extends TestCase
{
    use DatabaseTransactions;

    public function test_production_triggers_preserve_causal_remap_and_append_only_history(): void
    {
        $this->requirePostgres();
        $owner = Organization::factory()->create();
        $firstProfileOrganization = Organization::factory()->create();
        $secondProfileOrganization = Organization::factory()->create();
        $categoryId = (int) DB::table('marketplace_work_categories')->value('id');
        $firstProfileId = $this->profile((int) $firstProfileOrganization->id);
        $secondProfileId = $this->profile((int) $secondProfileOrganization->id);
        $this->category($firstProfileId, $categoryId);
        $this->category($secondProfileId, $categoryId);
        $contractor = Contractor::query()->create([
            'organization_id' => (int) $owner->id,
            'source_organization_id' => (int) $firstProfileOrganization->id,
            'name' => 'Causal contractor',
        ]);
        $firstEventAt = CarbonImmutable::parse(DB::table('contractor_scorecard_membership_events')
            ->where('subject_type', 'contractor')
            ->where('subject_id', $contractor->id)
            ->max('observed_at'));
        $contractor->forceFill([
            'source_organization_id' => (int) $secondProfileOrganization->id,
        ])->save();
        $secondEvent = DB::table('contractor_scorecard_membership_events')
            ->where('subject_type', 'contractor')
            ->where('subject_id', $contractor->id)
            ->orderByDesc('id')
            ->firstOrFail();
        $secondEventAt = CarbonImmutable::parse($secondEvent->observed_at);
        $resolved = $this->app->make(ContractorMembershipEvidenceResolver::class)->resolveMany(
            (int) $owner->id,
            [$firstEventAt, $secondEventAt],
        );

        self::assertSame(
            $firstProfileId,
            $resolved[$firstEventAt->toISOString()]->profileByContractor[(int) $contractor->id],
        );
        self::assertSame(
            $secondProfileId,
            $resolved[$secondEventAt->toISOString()]->profileByContractor[(int) $contractor->id],
        );

        try {
            DB::table('contractor_scorecard_membership_events')
                ->where('id', $secondEvent->id)
                ->delete();
            self::fail('Membership evidence must remain append-only.');
        } catch (Throwable $exception) {
            self::assertStringContainsString(
                'contractor_membership_evidence_is_immutable',
                $exception->getMessage(),
            );
        }
    }

    public function test_resolver_fails_closed_before_backfill_coverage(): void
    {
        $this->requirePostgres();
        $coverageStartedAt = CarbonImmutable::parse(DB::table('contractor_scorecard_membership_coverage')
            ->max('coverage_started_at'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contractor_membership_evidence_historical_gap');
        $this->app->make(ContractorMembershipEvidenceResolver::class)->resolve(
            (int) Organization::factory()->create()->id,
            $coverageStartedAt->subMicrosecond(),
        );
    }

    private function profile(int $organizationId): int
    {
        return (int) DB::table('marketplace_contractor_profiles')->insertGetId([
            'organization_id' => $organizationId,
            'status' => 'active',
            'availability_status' => 'available',
            'verification_level' => 'verified',
            'is_visible_in_marketplace' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function category(int $profileId, int $categoryId): void
    {
        DB::table('marketplace_contractor_categories')->insert([
            'profile_id' => $profileId,
            'category_id' => $categoryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires PostgreSQL triggers and JSONB.');
        }
    }
}
