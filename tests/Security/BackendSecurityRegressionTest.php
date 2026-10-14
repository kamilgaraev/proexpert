<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeInvitation;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProjectAssignment;
use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeModerationAccess;
use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\BusinessModules\Features\Notifications\Http\Controllers\NotificationTrackingController;
use App\BusinessModules\Features\Notifications\Services\AnalyticsService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\AuthSessionStatus;
use App\Http\Middleware\JwtMiddleware;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\JwtTokenIssuer;
use App\Services\Project\ProjectService;
use App\Services\User\AdminUserRolePolicy;
use App\Services\User\UserService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class BackendSecurityRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_administrator_cannot_change_an_owner_or_shared_global_identity(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_admin');
        $owner = User::factory()->create();
        $context->organization->users()->attach($owner->id, ['is_active' => true, 'is_owner' => true]);
        UserRoleAssignment::assignRole($owner, 'organization_owner', AuthorizationContext::getOrganizationContext($context->organization->id));
        try {
            app(AdminUserRolePolicy::class)->assertCanManageUser($context->user, $owner, $context->organization->id, true);
            self::fail('Owner identity must remain protected.');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(403, $exception->getCode());
        }
        $member = User::factory()->create();
        $context->organization->users()->attach($member->id, ['is_active' => true]);
        $other = Organization::factory()->create();
        $other->users()->attach($member->id, ['is_active' => true]);
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        app(AdminUserRolePolicy::class)->assertCanManageUser($context->user, $member, $context->organization->id, true);
    }

    public function test_blocking_an_organization_member_preserves_other_memberships_and_global_login(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $other = Organization::factory()->create();
        $member = User::factory()->create();
        $context->organization->users()->attach($member->id, ['is_active' => true]);
        $other->users()->attach($member->id, ['is_active' => true]);
        $request = Request::create('/users');
        $request->attributes->set('current_organization_id', $context->organization->id);
        $request->setUserResolver(fn () => $context->user);
        self::assertTrue(app(UserService::class)->blockOrganizationUser($member->id, $request));
        self::assertTrue($member->fresh()->is_active);
        self::assertFalse((bool) $member->organizations()->whereKey($context->organization->id)->first()->pivot->is_active);
        self::assertTrue((bool) $member->organizations()->whereKey($other->id)->first()->pivot->is_active);
        self::assertTrue(app(UserService::class)->unblockOrganizationUser($member->id, $request));
    }

    public function test_project_listing_obeys_empty_and_populated_assignments_and_tenant_scope(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'assigned_projects']);
        $allowed = Project::factory()->create(['organization_id' => $context->organization->id]);
        Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreign = Project::factory()->create();
        $request = Request::create('/projects');
        $request->attributes->set('current_organization_id', $context->organization->id);
        $request->setUserResolver(fn () => $context->user);
        self::assertSame(0, app(ProjectService::class)->getProjectsForCurrentOrg($request)->total());
        self::assertSame([], app(\App\Services\Customer\CustomerPortalService::class)->getProjects($context->organization->id, $context->user)['projects']);
        $allowed->users()->attach($context->user->id, ['is_active' => true, 'role' => 'member']);
        $foreign->users()->attach($context->user->id, ['is_active' => true, 'role' => 'member']);
        self::assertSame([$allowed->id], app(ProjectService::class)->getProjectsForCurrentOrg($request)->pluck('id')->all());
        self::assertSame([$allowed->id], array_column(app(\App\Services\Customer\CustomerPortalService::class)->getProjects($context->organization->id, $context->user)['projects'], 'id'));
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        app(ProjectService::class)->getProjectStatistics($foreign->id, $request);
    }

    public function test_revoked_mobile_token_cannot_read_or_write_even_when_sessions_are_disabled_later(): void
    {
        config()->set('auth_tokens.sessions.enabled', true);
        $context = AdminApiTestContext::create();
        $session = UserAuthSession::query()->where('user_id', $context->user->id)->firstOrFail();
        $token = app(JwtTokenIssuer::class)->issue($context->user, [
            'guard' => 'api_mobile', 'organization_id' => $context->organization->id, 'session_uuid' => $session->session_uuid,
        ]);
        Route::match(['GET', 'POST'], '/api/v1/mobile/security-regression-probe', fn () => response()->json(['reached' => true]))
            ->middleware(JwtMiddleware::class.':api_mobile');
        $this->withToken($token)->getJson('/api/v1/mobile/security-regression-probe')->assertOk();
        $session->update(['status' => AuthSessionStatus::Revoked, 'revoked_at' => now()]);
        config()->set('auth_tokens.sessions.enabled', false);
        $this->withToken($token)->getJson('/api/v1/mobile/security-regression-probe')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/mobile/security-regression-probe')->assertUnauthorized();
    }

    public function test_expired_mobile_token_can_refresh_only_while_its_session_remains_active(): void
    {
        config()->set('auth_tokens.sessions.enabled', true);
        $this->travelTo(now()->subMinutes((int) config('jwt.ttl', 60) + 5));
        $context = AdminApiTestContext::create();
        $session = UserAuthSession::query()->where('user_id', $context->user->id)->firstOrFail();
        $token = app(JwtTokenIssuer::class)->issue($context->user, [
            'guard' => 'api_mobile', 'organization_id' => $context->organization->id, 'session_uuid' => $session->session_uuid,
        ]);
        $this->travelBack();
        Route::post('/api/v1/mobile/security-regression/auth/refresh', fn () => response()->json(['reached' => true]))
            ->middleware(JwtMiddleware::class.':api_mobile');
        $this->withToken($token)->postJson('/api/v1/mobile/security-regression/auth/refresh')->assertOk();
        $session->update(['status' => AuthSessionStatus::Revoked, 'revoked_at' => now()]);
        $this->withToken($token)->postJson('/api/v1/mobile/security-regression/auth/refresh')->assertUnauthorized();
    }

    public function test_active_project_choices_exclude_completed_projects(): void
    {
        $context = AdminApiTestContext::create();
        $active = Project::factory()->create(['organization_id' => $context->organization->id, 'status' => 'active']);
        Project::factory()->create(['organization_id' => $context->organization->id, 'status' => 'completed']);
        $request = Request::create('/projects');
        $request->attributes->set('current_organization_id', $context->organization->id);
        $request->setUserResolver(fn () => $context->user);
        self::assertSame([$active->id], app(ProjectService::class)->getActiveProjectsForCurrentOrg($request)->pluck('id')->all());
    }

    public function test_cancelled_and_expired_brigade_invitations_cannot_create_assignments(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create();
        $brigade = BrigadeProfile::query()->create([
            'owner_user_id' => $owner->id, 'name' => 'Test brigade', 'slug' => 'test-brigade',
            'contact_person' => $owner->name, 'contact_email' => $owner->email, 'contact_phone' => '+79990000000',
            'team_size' => 1, 'verification_status' => BrigadeStatuses::PROFILE_APPROVED,
        ]);
        foreach ([[BrigadeStatuses::INVITATION_CANCELLED, null], [BrigadeStatuses::INVITATION_PENDING, now()->subMinute()]] as [$status, $expires]) {
            $invitation = BrigadeInvitation::query()->create([
                'brigade_id' => $brigade->id, 'project_id' => $project->id,
                'contractor_organization_id' => $project->organization_id, 'status' => $status, 'expires_at' => $expires,
            ]);
            try {
                app(BrigadeWorkflowService::class)->respondToInvitation($owner, $invitation->id, BrigadeStatuses::INVITATION_ACCEPTED);
                self::fail('A terminal invitation cannot be accepted.');
            } catch (ValidationException) {
                self::assertSame($status, $invitation->fresh()->status);
            }
        }
        self::assertSame(0, BrigadeProjectAssignment::query()->count());
        $valid = BrigadeInvitation::query()->create([
            'brigade_id' => $brigade->id, 'project_id' => $project->id,
            'contractor_organization_id' => $project->organization_id, 'status' => BrigadeStatuses::INVITATION_PENDING,
            'expires_at' => now()->addDay(),
        ]);
        app(BrigadeWorkflowService::class)->respondToInvitation($owner, $valid->id, BrigadeStatuses::INVITATION_ACCEPTED);
        self::assertSame(1, BrigadeProjectAssignment::query()->count());
    }

    public function test_global_moderation_requires_the_nondelegable_system_moderator_role(): void
    {
        $entitlements = Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect([new \App\Models\Module(['slug' => 'brigades'])]));
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        try {
            app(BrigadeModerationAccess::class)->assert($context->user, 'brigades.catalog.moderate');
            self::fail('Organization owner cannot moderate the global catalog.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
        UserRoleAssignment::assignRole($context->user, 'brigade_catalog_moderator', AuthorizationContext::getOrganizationContext($context->organization->id));
        app(BrigadeModerationAccess::class)->assert($context->user, 'brigades.catalog.moderate');
        app(BrigadeModerationAccess::class)->assert($context->user, 'brigades.documents.verify');
    }

    public function test_catalog_viewers_can_read_brigades_but_cannot_moderate_them(): void
    {
        $entitlements = Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect([new \App\Models\Module(['slug' => 'brigades'])]));
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        $context = AdminApiTestContext::create(roleSlug: 'admin_viewer');
        $brigade = BrigadeProfile::query()->create([
            'owner_user_id' => $context->user->id, 'name' => 'Readable brigade', 'slug' => 'readable-brigade',
            'contact_person' => $context->user->name, 'contact_email' => $context->user->email,
            'contact_phone' => '+79990000000', 'team_size' => 1,
            'verification_status' => BrigadeStatuses::PROFILE_APPROVED,
        ]);

        $this->getJson('/api/v1/admin/brigades/catalog', $context->authHeaders())->assertOk();
        $this->getJson('/api/v1/admin/brigades/catalog/'.$brigade->id, $context->authHeaders())->assertOk();
        $this->patchJson('/api/v1/admin/brigades/catalog/'.$brigade->id.'/status', [
            'verification_status' => BrigadeStatuses::PROFILE_PENDING_REVIEW,
        ], $context->authHeaders())->assertForbidden();
        self::assertSame(BrigadeStatuses::PROFILE_APPROVED, $brigade->fresh()->verification_status);

        $this->expectException(AuthorizationException::class);
        app(BrigadeModerationAccess::class)->assert($context->user, 'brigades.documents.verify');
    }

    public function test_tracking_links_preserve_signed_destination_and_reject_tampering(): void
    {
        $analytics = Mockery::mock(AnalyticsService::class);
        $url = 'https://example.test/path?q=a%20b&next=%2Fhome';
        $analytics->shouldReceive('trackClick')->once()->with('test-tracking', $url)->andReturn(true);
        $this->app->instance(AnalyticsService::class, $analytics);
        Route::get('/security-track/{tracking_id}', [NotificationTrackingController::class, 'trackClick'])->name('security.track');
        Route::getRoutes()->refreshNameLookups();
        $signed = URL::signedRoute('security.track', ['tracking_id' => 'test-tracking', 'url' => $url]);
        $this->get($signed)->assertRedirect($url);
        $this->get(str_replace('example.test', 'attacker.test', $signed))->assertForbidden();
        $this->get('/security-track/missing?url=https%3A%2F%2Fattacker.test')->assertForbidden();
    }

    public function test_project_detail_loads_membership_in_a_batch_and_preserves_member_activity(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'organization_user')) {
                $queries[] = $query->sql;
            }
        });
        $members = [];
        foreach (range(1, 6) as $index) {
            $member = User::factory()->create();
            $context->organization->users()->attach($member->id, ['is_active' => $index !== 6]);
            $project->users()->attach($member->id, ['is_active' => true, 'role' => 'member']);
            $members[] = $member;
            if ($index === 1) {
                $queries = [];
                $this->getJson('/api/v1/admin/projects/'.$project->id, $context->authHeaders())->assertOk();
                $oneMemberQueries = count($queries);
            }
        }
        $queries = [];
        $response = $this->getJson('/api/v1/admin/projects/'.$project->id, $context->authHeaders())->assertOk();
        self::assertLessThanOrEqual($oneMemberQueries + 1, count($queries));
        $team = collect($response->json('data.assigned_users'))->keyBy('id');
        self::assertCount(6, $team);
        self::assertTrue($team[$members[0]->id]['is_active']);
        self::assertFalse($team[$members[5]->id]['is_active']);
    }
}
