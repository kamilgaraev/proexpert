<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;
use App\Exceptions\Billing\CommercialQuotaExceededException;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AiEstimateQuotaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['commercial_limits.free.ai_estimates_month' => 2]);
    }

    public function test_starting_generation_reserves_one_estimate_only_once_for_the_session(): void
    {
        $session = $this->session();

        app(AiEstimateQuotaService::class)->reserve($session);
        app(AiEstimateQuotaService::class)->reserve($session);

        $this->assertSame(1, $this->confirmedReservations($session));
    }

    public function test_starting_another_session_is_rejected_when_monthly_limit_is_reached(): void
    {
        config(['commercial_limits.free.ai_estimates_month' => 1]);
        $session = $this->session();
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserve($session);

        $this->expectException(CommercialQuotaExceededException::class);

        $quota->reserve($this->session(Organization::query()->findOrFail($session->organization_id)));
    }

    public function test_terminal_technical_failure_before_result_releases_reservation(): void
    {
        $session = $this->session();
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserve($session);
        $session->forceFill([
            'status' => EstimateGenerationStatus::Failed,
            'resume_status' => EstimateGenerationStatus::Generating,
        ]);

        $quota->releaseForTerminalTechnicalFailure($session, $this->technicalFailure($session));

        $this->assertSame('released', $this->reservationStatus($session));
        $this->assertSame(0, $this->confirmedReservations($session));
    }

    public function test_non_terminal_failure_keeps_confirmed_reservation(): void
    {
        $session = $this->session();
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserve($session);
        $session->forceFill([
            'status' => EstimateGenerationStatus::Failed,
            'resume_status' => EstimateGenerationStatus::Generating,
        ]);

        $quota->releaseForTerminalTechnicalFailure($session, $this->technicalFailure($session, FailureCategory::UserActionRequired));

        $this->assertSame('confirmed', $this->reservationStatus($session));
    }

    public function test_terminal_failure_after_result_keeps_confirmed_reservation(): void
    {
        $session = $this->session();
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserve($session);
        $session->forceFill([
            'status' => EstimateGenerationStatus::Failed,
            'resume_status' => EstimateGenerationStatus::Applying,
        ]);

        $quota->releaseForTerminalTechnicalFailure($session, $this->technicalFailure($session));

        $this->assertSame('confirmed', $this->reservationStatus($session));
    }

    public function test_manual_edits_and_automatic_retries_do_not_consume_another_estimate(): void
    {
        $session = $this->session();
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserve($session);

        $session->forceFill(['input_payload' => ['description' => 'Уточнённое описание']])->save();
        $quota->reserve($session->fresh());

        $session->forceFill(['input_payload' => ['generation_attempt_id' => (string) str()->uuid()]])->save();
        $quota->reserve($session->fresh());

        $this->assertSame(1, $this->confirmedReservations($session));
    }

    public function test_commercial_usage_counts_only_current_month_confirmed_reservations(): void
    {
        $session = $this->session();
        $organization = Organization::query()->findOrFail($session->organization_id);
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserve($session);
        DB::table('estimate_generation_ai_estimate_quota_reservations')
            ->where('organization_id', $session->organization_id)
            ->where('session_id', $session->id)
            ->update(['status' => 'released', 'released_at' => now()]);

        $currentSession = $this->session($organization);
        $previousMonthSession = $this->session($organization);
        $quota->reserve($currentSession);
        DB::table('estimate_generation_ai_estimate_quota_reservations')->insert([
            'organization_id' => $session->organization_id,
            'session_id' => $previousMonthSession->id,
            'monthly_period' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'status' => 'confirmed',
            'confirmed_at' => now()->subMonthNoOverflow(),
            'released_at' => null,
        ]);

        $usage = app(\App\Services\Billing\CommercialQuotaService::class)->getUsage($organization);

        $this->assertSame(1, $usage['ai_estimates_month']);
    }

    private function session(?Organization $organization = null): EstimateGenerationSession
    {
        $organization ??= Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'ready_to_generate',
            'processing_stage' => 'ready_to_generate',
            'processing_progress' => 35,
            'input_payload' => ['description' => 'Смета'],
            'problem_flags' => [],
        ]);
    }

    private function technicalFailure(
        EstimateGenerationSession $session,
        FailureCategory $category = FailureCategory::Terminal,
    ): FailureData
    {
        return new FailureData(new FailureContext(
            organizationId: (int) $session->organization_id,
            projectId: (int) $session->project_id,
            sessionId: (int) $session->id,
            stage: ProcessingStage::BuildDraft,
            operation: 'run_stage',
            attempt: 1,
            correlationId: (string) str()->uuid(),
            eventId: (string) str()->uuid(),
            expectedSessionStateVersion: 0,
            expectedSessionStatus: 'generating',
        ), $category, 'unexpected_internal_failure', []);
    }

    private function confirmedReservations(EstimateGenerationSession $session): int
    {
        return DB::table('estimate_generation_ai_estimate_quota_reservations')
            ->where('organization_id', $session->organization_id)
            ->where('session_id', $session->id)
            ->where('status', 'confirmed')
            ->count();
    }

    private function reservationStatus(EstimateGenerationSession $session): ?string
    {
        return DB::table('estimate_generation_ai_estimate_quota_reservations')
            ->where('organization_id', $session->organization_id)
            ->where('session_id', $session->id)
            ->value('status');
    }
}
