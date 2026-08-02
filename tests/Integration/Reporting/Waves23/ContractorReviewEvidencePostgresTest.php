<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

final class ContractorReviewEvidencePostgresTest extends TestCase
{
    use DatabaseTransactions;

    public function test_production_review_trigger_captures_versions_and_evidence_is_append_only(): void
    {
        $this->requirePostgres();
        $reviewer = Organization::factory()->create();
        $contractor = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $reviewer->id]);
        $user = User::factory()->create();
        $profileId = (int) DB::table('marketplace_contractor_profiles')->insertGetId([
            'organization_id' => (int) $contractor->id,
            'status' => 'active',
            'availability_status' => 'available',
            'verification_level' => 'verified',
            'is_visible_in_marketplace' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('marketplace_work_categories')->value('id');
        DB::table('marketplace_contractor_categories')->insert([
            'profile_id' => $profileId,
            'category_id' => $categoryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $offerId = (int) DB::table('marketplace_hiring_offers')->insertGetId([
            'project_id' => (int) $project->id,
            'hiring_organization_id' => (int) $reviewer->id,
            'contractor_organization_id' => (int) $contractor->id,
            'contractor_profile_id' => $profileId,
            'created_by_user_id' => (int) $user->id,
            'status' => 'accepted',
            'role' => 'contractor',
            'title' => 'Evidence trigger',
            'currency' => 'RUB',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reviewId = (int) DB::table('marketplace_hiring_offer_reviews')->insertGetId([
            'offer_id' => $offerId,
            'project_id' => (int) $project->id,
            'reviewer_organization_id' => (int) $reviewer->id,
            'contractor_organization_id' => (int) $contractor->id,
            'contractor_profile_id' => $profileId,
            'category_id' => $categoryId,
            'created_by_user_id' => (int) $user->id,
            'quality_score' => '4.50',
            'deadline_score' => '4.00',
            'communication_score' => '4.25',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('marketplace_hiring_offer_reviews')
            ->where('id', $reviewId)
            ->update(['quality_score' => '3.50', 'updated_at' => now()]);
        $events = DB::table('contractor_scorecard_review_events')
            ->where('review_id', $reviewId)
            ->orderBy('id')
            ->get();

        self::assertCount(2, $events);
        $firstPayload = json_decode((string) $events[0]->payload, true, 512, JSON_THROW_ON_ERROR);
        $secondPayload = json_decode((string) $events[1]->payload, true, 512, JSON_THROW_ON_ERROR);

        self::assertNotSame($events[0]->evidence_hash, $events[1]->evidence_hash);
        self::assertSame('4.50', number_format((float) $firstPayload['quality_score'], 2, '.', ''));
        self::assertSame('3.50', number_format((float) $secondPayload['quality_score'], 2, '.', ''));

        try {
            DB::table('contractor_scorecard_review_events')
                ->where('id', $events[0]->id)
                ->delete();
            self::fail('Review evidence must remain append-only.');
        } catch (Throwable $exception) {
            self::assertStringContainsString(
                'contractor_review_evidence_is_immutable',
                $exception->getMessage(),
            );
        }
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires PostgreSQL triggers and JSONB.');
        }
    }
}
