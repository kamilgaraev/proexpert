<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSessionCommand;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\QuotaSnapshot;
use App\Exceptions\Billing\CommercialQuotaExceededException;
use App\Models\Organization;
use App\Models\OrganizationResourceAllocation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AiEstimateQuotaTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    public function test_snapshot_always_includes_ten_monthly_generations(): void
    {
        config(['commercial_limits.free.ai_estimates_month' => 99]);
        $session = $this->createSession();

        $snapshot = $this->snapshotForOrganization(app(AiEstimateQuotaService::class), $session);

        $this->assertInstanceOf(QuotaSnapshot::class, $snapshot);
        $this->assertSame(10, $snapshot->included);
        $this->assertSame(0, $snapshot->purchased);
        $this->assertSame(0, $snapshot->used);
        $this->assertSame(10, $snapshot->available);
        $this->assertNull($snapshot->reservationStatus);
    }

    public function test_purchased_extras_extend_the_ten_included_generations(): void
    {
        $session = $this->createSession();
        OrganizationResourceAllocation::query()->create([
            'organization_id' => $session->organization_id,
            'resource_slug' => 'extra_ai_estimates',
            'limit_key' => 'ai_estimates_month',
            'quantity' => 7,
            'source' => 'paid_addon',
            'status' => 'active',
        ]);

        $snapshot = $this->snapshotForOrganization(app(AiEstimateQuotaService::class), $session);

        $this->assertSame(10, $snapshot->included);
        $this->assertSame(7, $snapshot->purchased);
        $this->assertSame(17, $snapshot->available);
    }

    public function test_unlimited_commercial_override_is_preserved(): void
    {
        $first = $this->createSession();
        $organization = Organization::query()->findOrFail($first->organization_id);
        OrganizationResourceAllocation::query()->create([
            'organization_id' => $organization->id,
            'resource_slug' => 'corporate_ai_estimates_unlimited',
            'limit_key' => 'ai_estimates_month',
            'quantity' => null,
            'source' => 'corporate_override',
            'status' => 'active',
        ]);
        $quota = app(AiEstimateQuotaService::class);

        $this->reserveSession($quota, $first);
        for ($index = 1; $index < 11; $index++) {
            $this->reserveSession($quota, $this->createSession($organization));
        }

        $snapshot = $this->snapshotForOrganization($quota, $first);
        $this->assertNull($snapshot->purchased);
        $this->assertSame(0, $snapshot->used);
        $this->assertSame(11, $snapshot->reserved);
        $this->assertNull($snapshot->available);
    }

    public function test_ten_sessions_use_the_included_quota_and_the_eleventh_is_rejected(): void
    {
        $first = $this->createSession();
        $organization = Organization::query()->findOrFail($first->organization_id);
        $quota = app(AiEstimateQuotaService::class);
        $this->reserveSession($quota, $first);

        for ($index = 1; $index < 10; $index++) {
            $session = $this->createSession($organization);
            $this->reserveSession($quota, $session);
        }

        $snapshot = $this->snapshotForOrganization($quota, $first);
        $this->assertSame(0, $snapshot->used);
        $this->assertSame(10, $snapshot->reserved);
        $this->assertSame(0, $snapshot->available);

        $eleventh = $this->createSession($organization);
        $this->expectException(CommercialQuotaExceededException::class);
        $this->reserveSession($quota, $eleventh);
    }

    public function test_repeated_start_uses_the_same_session_reservation(): void
    {
        $session = $this->createSession();
        $quota = app(AiEstimateQuotaService::class);

        $first = $this->reserveSession($quota, $session);
        $second = $this->reserveSession($quota, $session);

        $this->assertSame('reserved', $first->reservationStatus);
        $this->assertSame('reserved', $second->reservationStatus);
        $this->assertSame(0, $second->used);
        $this->assertSame(1, $second->reserved);
    }

    public function test_document_upload_dialogue_and_local_rebuild_keep_one_reservation(): void
    {
        $session = $this->createSession();
        $quota = app(AiEstimateQuotaService::class);
        $this->reserveSession($quota, $session);

        foreach (['document_upload', 'ai_dialogue', 'local_rebuild'] as $lifecycleStep) {
            $session = $session->fresh();
            $this->assertInstanceOf(EstimateGenerationSession::class, $session);
            $payload = $session->input_payload ?? [];
            $session->forceFill(['input_payload' => [...$payload, 'last_lifecycle_step' => $lifecycleStep]])->save();
            $snapshot = $this->reserveSession($quota, $session);

            $this->assertSame(0, $snapshot->used, $lifecycleStep);
            $this->assertSame(1, $snapshot->reserved, $lifecycleStep);
            $this->assertSame('reserved', $snapshot->reservationStatus, $lifecycleStep);
        }
    }

    public function test_technical_failure_without_usable_draft_releases_the_reservation(): void
    {
        $session = $this->createSession();
        $quota = app(AiEstimateQuotaService::class);
        $this->reserveSession($quota, $session);

        $snapshot = $this->releaseTechnicalFailure($quota, $session);

        $this->assertSame('released', $snapshot->reservationStatus);
        $this->assertSame(0, $snapshot->used);
        $this->assertSame(10, $snapshot->available);
    }

    public function test_technical_failure_after_usable_draft_keeps_the_reservation(): void
    {
        $session = $this->createSession();
        $quota = app(AiEstimateQuotaService::class);
        $this->reserveSession($quota, $session);
        EstimateGenerationPackage::query()->create([
            'session_id' => $session->id,
            'input_version' => 'sha256:'.str_repeat('a', 64),
            'key' => 'main',
            'title' => 'Основной комплект',
            'scope_type' => 'building',
            'status' => 'ready_for_review',
        ]);
        $quota->confirmUsableDraft($session);

        $snapshot = $this->releaseTechnicalFailure($quota, $session);

        $this->assertSame('confirmed', $snapshot->reservationStatus);
        $this->assertSame(1, $snapshot->used);
        $this->assertSame(9, $snapshot->available);
    }

    public function test_review_required_canonical_draft_confirms_quota_without_a_published_package(): void
    {
        $session = $this->createSession();
        $quota = app(AiEstimateQuotaService::class);
        $this->reserveSession($quota, $session);
        $session->forceFill([
            'status' => EstimateGenerationStatus::EstimateReviewRequired,
            'draft_payload' => [
                'work_items' => [[
                    'key' => 'review-item',
                    'name' => 'Review item',
                    'quantity' => 1,
                    'unit' => 'item',
                ]],
            ],
        ])->save();

        $quota->confirmUsableDraft($session->fresh());

        self::assertSame('confirmed', $this->reservationStatus($session));
        self::assertSame(1, $this->confirmedReservations($session));
        self::assertSame(0, EstimateGenerationPackage::query()->where('session_id', $session->id)->count());
    }

    public function test_confirmed_generation_is_not_released_after_its_current_package_disappears(): void
    {
        $session = $this->createSession();
        $quota = app(AiEstimateQuotaService::class);
        $this->reserveSession($quota, $session);
        $this->createUsableDraft($session);
        $quota->confirmUsableDraft($session);
        EstimateGenerationPackage::query()->where('session_id', $session->id)->delete();

        $snapshot = $this->releaseTechnicalFailure($quota, $session);

        self::assertSame('confirmed', $snapshot->reservationStatus);
        self::assertSame(1, $snapshot->used);
    }

    public function test_starting_generation_reserves_one_estimate_only_once_for_the_session(): void
    {
        $session = $this->createSession();

        app(AiEstimateQuotaService::class)->reserve($session);
        app(AiEstimateQuotaService::class)->reserve($session);

        $this->assertSame(1, $this->reservedReservations($session));
    }

    public function test_starting_another_session_is_rejected_when_monthly_limit_is_reached(): void
    {
        $session = $this->createSession();
        $quota = app(AiEstimateQuotaService::class);
        $organization = Organization::query()->findOrFail($session->organization_id);
        $quota->reserve($session);

        for ($index = 1; $index < 10; $index++) {
            $quota->reserve($this->createSession($organization));
        }

        $this->expectException(CommercialQuotaExceededException::class);

        $quota->reserve($this->createSession($organization));
    }

    public function test_retrying_failed_generation_reserves_quota_with_the_generation_transition(): void
    {
        Queue::fake();
        $session = $this->createSession();
        $session->forceFill([
            'status' => EstimateGenerationStatus::Failed,
            'resume_status' => EstimateGenerationStatus::Generating,
            'state_version' => 3,
        ])->save();

        $result = app(RetryEstimateGenerationSession::class)->handle(new RetryEstimateGenerationSessionCommand(
            (int) $session->id,
            (int) $session->organization_id,
            (int) $session->project_id,
            3,
        ));

        $this->assertSame(EstimateGenerationStatus::Generating, $result->status);
        $this->assertSame(1, $this->reservedReservations($session));
    }

    public function test_stale_terminal_failure_cannot_release_quota_reconfirmed_by_retry(): void
    {
        Queue::fake();
        $session = $this->createSession();
        $session->forceFill([
            'status' => EstimateGenerationStatus::Generating,
            'processing_stage' => 'generating',
            'state_version' => 3,
        ])->save();
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserve($session->fresh());
        $failure = $this->technicalFailure($session, expectedStateVersion: 3);

        $failed = app(AdvanceEstimateGeneration::class)->failed($session->fresh(), $failure->code);
        $quota->releaseForTerminalTechnicalFailure($failed, $failure, 3);

        $failed = $session->fresh();
        $this->assertSame(EstimateGenerationStatus::Failed, $failed->status);
        $this->assertSame('released', $this->reservationStatus($session));

        app(RetryEstimateGenerationSession::class)->handle(new RetryEstimateGenerationSessionCommand(
            (int) $session->id,
            (int) $session->organization_id,
            (int) $session->project_id,
            (int) $failed->state_version,
        ));
        $quota->releaseForTerminalTechnicalFailure($session->fresh(), $failure, 3);

        $this->assertSame(EstimateGenerationStatus::Generating, $session->fresh()->status);
        $this->assertSame('reserved', $this->reservationStatus($session));
        $this->assertSame(1, $this->reservedReservations($session));
    }

    public function test_terminal_technical_failure_before_result_releases_reservation(): void
    {
        $session = $this->createSession();
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
        $session = $this->createSession();
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserve($session);
        $session->forceFill([
            'status' => EstimateGenerationStatus::Failed,
            'resume_status' => EstimateGenerationStatus::Generating,
        ]);

        $quota->releaseForTerminalTechnicalFailure($session, $this->technicalFailure($session, FailureCategory::UserActionRequired));

        $this->assertSame('reserved', $this->reservationStatus($session));
    }

    public function test_terminal_failure_after_result_keeps_confirmed_reservation(): void
    {
        $session = $this->createSession();
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserve($session);
        $this->createUsableDraft($session);
        $quota->confirmUsableDraft($session);
        $session->forceFill([
            'status' => EstimateGenerationStatus::Failed,
            'resume_status' => EstimateGenerationStatus::Applying,
        ]);

        $quota->releaseForTerminalTechnicalFailure($session, $this->technicalFailure($session));

        $this->assertSame('confirmed', $this->reservationStatus($session));
    }

    public function test_manual_edits_and_automatic_retries_do_not_consume_another_estimate(): void
    {
        $session = $this->createSession();
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserve($session);

        $session->forceFill(['input_payload' => ['description' => 'Уточнённое описание']])->save();
        $quota->reserve($session->fresh());

        $session->forceFill(['input_payload' => ['generation_attempt_id' => (string) str()->uuid()]])->save();
        $quota->reserve($session->fresh());

        $this->assertSame(1, $this->reservedReservations($session));
    }

    public function test_commercial_usage_counts_only_current_month_confirmed_reservations(): void
    {
        $session = $this->createSession();
        $organization = Organization::query()->findOrFail($session->organization_id);
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserve($session);
        DB::table('estimate_generation_ai_estimate_quota_reservations')
            ->where('organization_id', $session->organization_id)
            ->where('session_id', $session->id)
            ->update(['status' => 'released', 'released_at' => now()]);

        $currentSession = $this->createSession($organization);
        $previousMonthSession = $this->createSession($organization);
        $quota->reserve($currentSession);
        $this->createUsableDraft($currentSession);
        $quota->confirmUsableDraft($currentSession);
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

    public function test_detail_and_list_keep_the_session_reservation_status_after_month_boundary(): void
    {
        $session = $this->createSession();
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserve($session);
        DB::table('estimate_generation_ai_estimate_quota_reservations')
            ->where('organization_id', $session->organization_id)
            ->where('session_id', $session->id)
            ->update([
                'monthly_period' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'confirmed_at' => now()->subMonthNoOverflow(),
            ]);

        $quota->reserve($session->fresh());
        $listQuota = $quota->snapshots([$session->fresh()])[(int) $session->id];
        $detailQuota = app(BuildSessionSnapshot::class)->handle(
            session: $session->fresh(),
            permissions: [],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        )->aiEstimateQuota;

        $this->assertSame('reserved', $listQuota['reservation_status']);
        $this->assertSame('reserved', $detailQuota['reservation_status']);
        $this->assertSame(0, $listQuota['used']);
        $this->assertSame(0, $detailQuota['used']);
        $this->assertNull($quota->snapshot((string) $session->organization_id)->reservationStatus);
        $this->assertSame(1, DB::table('estimate_generation_ai_estimate_quota_reservations')
            ->where('organization_id', $session->organization_id)
            ->where('session_id', $session->id)
            ->count());
    }

    public function test_detail_snapshot_does_not_mix_status_and_usage_across_a_concurrent_release(): void
    {
        $session = $this->createSession();
        $quota = app(AiEstimateQuotaService::class);
        $quota->reserve($session);
        $listQuota = $quota->snapshots([$session->fresh()])[(int) $session->id];
        $reservationReadObserved = false;

        DB::listen(function (QueryExecuted $query) use ($session, &$reservationReadObserved): void {
            if (
                $reservationReadObserved
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'estimate_generation_ai_estimate_quota_reservations')
            ) {
                return;
            }

            $reservationReadObserved = true;
            DB::table('estimate_generation_ai_estimate_quota_reservations')
                ->where('organization_id', $session->organization_id)
                ->where('session_id', $session->id)
                ->update([
                    'status' => 'released',
                    'released_at' => now(),
                ]);
        });

        $detailQuota = $quota->sessionSnapshot(
            (string) $session->organization_id,
            (string) $session->id,
        )->toArray();

        $this->assertTrue($reservationReadObserved);
        $this->assertSame('reserved', $listQuota['reservation_status']);
        $this->assertSame(0, $listQuota['used']);
        $this->assertSame(1, $listQuota['reserved']);
        $this->assertSame($listQuota['reservation_status'], $detailQuota['reservation_status']);
        $this->assertSame($listQuota['used'], $detailQuota['used']);
        $this->assertSame('released', $this->reservationStatus($session));
    }

    private function createSession(?Organization $organization = null): EstimateGenerationSession
    {
        $organization ??= Organization::withoutEvents(fn (): Organization => Organization::query()->create([
            'name' => 'Организация квоты',
            'is_active' => true,
        ]));
        $user = User::withoutEvents(fn (): User => User::query()->create([
            'name' => 'Сметчик',
            'email' => str()->uuid().'@example.test',
            'password' => 'test-password',
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]));
        $project = Project::withoutEvents(fn (): Project => Project::query()->create([
            'name' => 'Проект квоты',
            'organization_id' => $organization->id,
            'status' => 'active',
        ]));

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

    private function createSchema(): void
    {
        foreach ([
            'estimate_generation_ai_estimate_quota_reservations',
            'estimate_generation_packages',
            'estimate_generation_documents',
            'estimate_generation_sessions',
            'organization_resource_allocations',
            'organization_package_subscriptions',
            'projects',
            'users',
            'organizations',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('parent_organization_id')->nullable();
            $table->unsignedBigInteger('storage_used_mb')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('current_organization_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('estimate_generation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status');
            $table->string('processing_stage');
            $table->unsignedTinyInteger('processing_progress')->default(0);
            $table->json('input_payload');
            $table->json('analysis_payload')->nullable();
            $table->json('draft_payload')->nullable();
            $table->json('problem_flags')->nullable();
            $table->unsignedBigInteger('applied_estimate_id')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('failure_code')->nullable();
            $table->unsignedBigInteger('state_version')->default(0);
            $table->string('resume_status')->nullable();
            $table->timestamps();
            $table->unique(['id', 'organization_id']);
        });
        Schema::create('estimate_generation_packages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->string('input_version')->nullable();
            $table->string('key');
            $table->string('title');
            $table->string('scope_type');
            $table->string('status')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('estimate_generation_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->string('filename')->nullable();
            $table->string('status')->default('uploaded');
            $table->timestamps();
        });
        Schema::create('estimate_generation_ai_estimate_quota_reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('session_id');
            $table->date('monthly_period');
            $table->string('status');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->unique(['organization_id', 'session_id']);
        });
        Schema::create('organization_package_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('package_slug');
            $table->string('status');
            $table->timestamp('current_period_end_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });
        Schema::create('organization_resource_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('commercial_account_id')->nullable();
            $table->string('resource_slug');
            $table->string('limit_key');
            $table->decimal('quantity', 20, 2)->nullable();
            $table->string('source');
            $table->string('status');
            $table->timestamp('period_start_at')->nullable();
            $table->timestamp('period_end_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function technicalFailure(
        EstimateGenerationSession $session,
        FailureCategory $category = FailureCategory::Terminal,
        int $expectedStateVersion = 0,
    ): FailureData {
        return new FailureData(new FailureContext(
            organizationId: (int) $session->organization_id,
            projectId: (int) $session->project_id,
            sessionId: (int) $session->id,
            stage: ProcessingStage::BuildDraft,
            operation: 'run_stage',
            attempt: 1,
            correlationId: (string) str()->uuid(),
            eventId: (string) str()->uuid(),
            expectedSessionStateVersion: $expectedStateVersion,
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

    private function reservedReservations(EstimateGenerationSession $session): int
    {
        return DB::table('estimate_generation_ai_estimate_quota_reservations')
            ->where('organization_id', $session->organization_id)
            ->where('session_id', $session->id)
            ->where('status', 'reserved')
            ->count();
    }

    private function createUsableDraft(EstimateGenerationSession $session): void
    {
        EstimateGenerationPackage::query()->create([
            'session_id' => $session->id,
            'input_version' => 'sha256:'.hash('sha256', (string) $session->id),
            'key' => 'main',
            'title' => 'Основной комплект',
            'scope_type' => 'building',
            'status' => 'ready_for_review',
        ]);
    }

    private function reservationStatus(EstimateGenerationSession $session): ?string
    {
        return DB::table('estimate_generation_ai_estimate_quota_reservations')
            ->where('organization_id', $session->organization_id)
            ->where('session_id', $session->id)
            ->value('status');
    }

    private function snapshotForOrganization(
        AiEstimateQuotaService $quota,
        EstimateGenerationSession $session,
    ): QuotaSnapshot {
        try {
            $snapshot = $quota->snapshot((string) $session->organization_id);
        } catch (\TypeError) {
            $this->fail('snapshot() должен принимать идентификатор организации.');
        }

        $this->assertInstanceOf(QuotaSnapshot::class, $snapshot);

        return $snapshot;
    }

    private function reserveSession(
        AiEstimateQuotaService $quota,
        EstimateGenerationSession $session,
    ): QuotaSnapshot {
        $this->assertTrue(
            is_callable([$quota, 'reserveSession']),
            'reserveSession() должен быть публичным контрактом квоты.',
        );

        return $quota->reserveSession(
            (string) $session->organization_id,
            (string) $session->id,
        );
    }

    private function releaseTechnicalFailure(
        AiEstimateQuotaService $quota,
        EstimateGenerationSession $session,
    ): QuotaSnapshot {
        $this->assertTrue(
            is_callable([$quota, 'releaseTechnicalFailure']),
            'releaseTechnicalFailure() должен быть публичным контрактом квоты.',
        );

        return $quota->releaseTechnicalFailure(
            (string) $session->organization_id,
            (string) $session->id,
        );
    }
}
