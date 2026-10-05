<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Services\Admin\AdminProjectAccessService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class AdminProjectReadScopeTest extends TestCase
{
    public function test_first_project_permission_lookup_creates_missing_context_and_next_context_rechecks_roles(): void
    {
        $fixture = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        DB::table('authorization_contexts')->where('type', AuthorizationContext::TYPE_PROJECT)->where('resource_id', $project->id)->delete();
        Cache::flush();
        $authorization = app(AuthorizationService::class);
        self::assertTrue($authorization->can($fixture->user, 'organization.view', ['organization_id' => $fixture->organization->id]));
        Cache::driver('array')->flush();
        $service = app(AdminProjectAccessService::class);
        $context = $service->getProjectContext($project, $fixture->user);
        self::assertNotNull($context);
        self::assertNotNull($context->permissionResolver);
        self::assertTrue(($context->permissionResolver)('organization.view'));
        self::assertTrue(DB::table('authorization_contexts')->where('type', AuthorizationContext::TYPE_PROJECT)->where('resource_id', $project->id)->exists());
        DB::table('user_role_assignments')->where('user_id', $fixture->user->id)->update(['is_active' => false]);
        Cache::driver('array')->flush();
        $next = $service->getProjectContext($project, $fixture->user);
        self::assertNotNull($next);
        self::assertNotNull($next->permissionResolver);
        self::assertFalse(($next->permissionResolver)('organization.view'));
    }
}
