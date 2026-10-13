<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class LegacyCancelledQuotaPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_cancel_returns_only_the_exact_unused_session_reservation_and_is_idempotent(): void
    {
        config(['estimate-generation.credits.enabled' => false]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = \Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $entitlements = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->allows('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        $sessions = [];
        foreach (['reserved', 'confirmed'] as $status) {
            $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id,
                'project_id' => $project->id, 'user_id' => $actor->id, 'status' => 'generating', 'state_version' => 0, 'input_payload' => []]);
            DB::table('estimate_generation_ai_estimate_quota_reservations')->insert(['organization_id' => $organization->id,
                'session_id' => $session->id, 'monthly_period' => now()->startOfMonth()->toDateString(), 'status' => $status,
                'reserved_at' => now(), 'confirmed_at' => $status === 'confirmed' ? now() : null, 'released_at' => null]);
            $sessions[$status] = $session;
        }
        $cancelled = app(TransitionEstimateGenerationSession::class)->handle($sessions['reserved'], 0, EstimateGenerationEvent::Cancelled, $actor);
        self::assertSame('cancelled', $cancelled->status->value);
        self::assertSame('released', DB::table('estimate_generation_ai_estimate_quota_reservations')->where('session_id', $cancelled->id)->value('status'));
        app(AiEstimateQuotaService::class)->releaseCancelledReservation($cancelled);
        self::assertSame('released', DB::table('estimate_generation_ai_estimate_quota_reservations')->where('session_id', $cancelled->id)->value('status'));
        self::assertSame('confirmed', DB::table('estimate_generation_ai_estimate_quota_reservations')->where('session_id', $sessions['confirmed']->id)->value('status'));
        $used = app(TransitionEstimateGenerationSession::class)->handle($sessions['confirmed'], 0, EstimateGenerationEvent::Cancelled, $actor);
        self::assertSame('confirmed', DB::table('estimate_generation_ai_estimate_quota_reservations')->where('session_id', $used->id)->value('status'));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('organization_id', $organization->id)->count());
    }
}
