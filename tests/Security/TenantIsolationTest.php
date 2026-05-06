<?php

declare(strict_types=1);

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Controllers\Api\V1\ContractorVerificationController;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateLibrary;
use App\Models\EstimateLibraryItem;
use App\Models\EstimateLibraryItemPosition;
use App\Models\EstimateSection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

it('user from organization A cannot access projects of organization B via API', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $userA = User::factory()->create(['current_organization_id' => $orgA->id]);
    $userB = User::factory()->create(['current_organization_id' => $orgB->id]);

    $orgA->users()->attach($userA->id, ['is_owner' => true, 'is_active' => true]);
    $orgB->users()->attach($userB->id, ['is_owner' => true, 'is_active' => true]);

    $projectB = \App\Models\Project::factory()->create([
        'organization_id' => $orgB->id,
    ]);

    $response = $this->actingAs($userA, 'api')
        ->getJson("/api/v1/lk/projects/{$projectB->id}");

    expect($response->status())->toBeIn([403, 404]);
});

it('user from organization A cannot see projects of organization B in listing', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $userA = User::factory()->create(['current_organization_id' => $orgA->id]);

    $orgA->users()->attach($userA->id, ['is_owner' => true, 'is_active' => true]);

    $projectA = \App\Models\Project::factory()->create(['organization_id' => $orgA->id]);
    $projectB = \App\Models\Project::factory()->create(['organization_id' => $orgB->id]);

    $response = $this->actingAs($userA, 'api')
        ->getJson('/api/v1/lk/projects');

    $response->assertOk();

    $responseData = $response->json();
    $projectIds = collect($responseData['data'] ?? $responseData)->pluck('id')->toArray();

    expect($projectIds)->toContain($projectA->id)
        ->and($projectIds)->not->toContain($projectB->id);
});

it('organization A user cannot create estimate in organization B project', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $userA = User::factory()->create(['current_organization_id' => $orgA->id]);
    $orgA->users()->attach($userA->id, ['is_owner' => true, 'is_active' => true]);

    $projectB = \App\Models\Project::factory()->create(['organization_id' => $orgB->id]);

    $response = $this->actingAs($userA, 'api')
        ->postJson("/api/v1/lk/projects/{$projectB->id}/estimates", [
            'name' => 'Смета взлома',
        ]);

    expect($response->status())->toBeIn([403, 404]);
});

it('user with role in organization A has no authorization context in organization B', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $userA = User::factory()->create(['current_organization_id' => $orgA->id]);

    $contextA = AuthorizationContext::firstOrCreate(
        ['type' => AuthorizationContext::TYPE_ORGANIZATION, 'resource_id' => $orgA->id],
        ['parent_context_id' => null]
    );

    UserRoleAssignment::create([
        'user_id' => $userA->id,
        'context_id' => $contextA->id,
        'role_slug' => 'organization_owner',
        'role_type' => UserRoleAssignment::TYPE_SYSTEM,
        'is_active' => true,
    ]);

    $authService = app(AuthorizationService::class);

    $canInOrgA = $authService->can($userA, 'estimates.view', [
        'organization_id' => $orgA->id,
    ]);

    $canInOrgB = $authService->can($userA, 'estimates.view', [
        'organization_id' => $orgB->id,
    ]);

    expect($canInOrgA)->toBeTrue()
        ->and($canInOrgB)->toBeFalse();
});

it('admin user cannot update estimate library from another organization', function () {
    $this->withoutMiddleware();

    [$orgA, $userA] = createOrganizationUser();
    [$orgB, $userB] = createOrganizationUser();

    $foreignLibrary = EstimateLibrary::create([
        'organization_id' => $orgB->id,
        'created_by_user_id' => $userB->id,
        'name' => 'Foreign secured library',
        'access_level' => 'private',
        'tags' => [],
        'is_active' => true,
    ]);

    $response = $this->actingAs($userA, 'api_admin')
        ->putJson("/api/v1/admin/estimates/libraries/{$foreignLibrary->id}", [
            'name' => 'Cross tenant overwrite',
        ]);

    expect($response->status())->toBeIn([403, 404]);
    expect($foreignLibrary->fresh()->name)->toBe('Foreign secured library');
});

it('admin user cannot apply a library item to another organization estimate', function () {
    $this->withoutMiddleware();

    [$orgA, $userA] = createOrganizationUser();
    [$orgB, $userB] = createOrganizationUser();

    $library = EstimateLibrary::create([
        'organization_id' => $orgA->id,
        'created_by_user_id' => $userA->id,
        'name' => 'Safe local library',
        'access_level' => 'private',
        'tags' => [],
        'is_active' => true,
    ]);

    $libraryItem = EstimateLibraryItem::create([
        'library_id' => $library->id,
        'name' => 'Protected item',
        'parameters' => [],
        'positions_count' => 1,
    ]);

    EstimateLibraryItemPosition::create([
        'library_item_id' => $libraryItem->id,
        'name' => 'Protected position',
        'sort_order' => 0,
        'default_quantity' => 1,
    ]);

    $foreignProject = Project::factory()->create(['organization_id' => $orgB->id]);
    $foreignEstimate = createEstimate($orgB->id, $foreignProject->id, 'B-001');

    $response = $this->actingAs($userA, 'api_admin')
        ->postJson("/api/v1/admin/estimates/libraries/items/{$libraryItem->id}/apply", [
            'estimate_id' => $foreignEstimate->id,
            'parameters' => [],
        ]);

    expect($response->status())->toBeIn([403, 404, 422]);
    expect(EstimateItem::where('estimate_id', $foreignEstimate->id)->count())->toBe(0);
    expect($libraryItem->fresh()->usage_count)->toBe(0);
});

it('admin user cannot apply a library item with section from another estimate', function () {
    $this->withoutMiddleware();

    [$orgA, $userA] = createOrganizationUser();

    $project = Project::factory()->create(['organization_id' => $orgA->id]);
    $targetEstimate = createEstimate($orgA->id, $project->id, 'A-001');
    $anotherEstimate = createEstimate($orgA->id, $project->id, 'A-002');
    $foreignSection = createEstimateSection($anotherEstimate->id);

    $library = EstimateLibrary::create([
        'organization_id' => $orgA->id,
        'created_by_user_id' => $userA->id,
        'name' => 'Section scoped library',
        'access_level' => 'private',
        'tags' => [],
        'is_active' => true,
    ]);

    $libraryItem = EstimateLibraryItem::create([
        'library_id' => $library->id,
        'name' => 'Section scoped item',
        'parameters' => [],
        'positions_count' => 1,
    ]);

    EstimateLibraryItemPosition::create([
        'library_item_id' => $libraryItem->id,
        'name' => 'Section scoped position',
        'sort_order' => 0,
        'default_quantity' => 1,
    ]);

    $response = $this->actingAs($userA, 'api_admin')
        ->postJson("/api/v1/admin/estimates/libraries/items/{$libraryItem->id}/apply", [
            'estimate_id' => $targetEstimate->id,
            'section_id' => $foreignSection->id,
            'parameters' => [],
        ]);

    expect($response->status())->toBeIn([403, 404, 422]);
    expect(EstimateItem::where('estimate_id', $targetEstimate->id)->count())->toBe(0);
});

it('contractor verification stores rejection reason without interpolating raw sql', function () {
    [$organization] = createOrganizationUser();
    Project::factory()->create(['organization_id' => $organization->id]);

    $reason = '"); select pg_sleep(1); --';
    $controller = app(ContractorVerificationController::class);
    $method = new ReflectionMethod($controller, 'blockOrganizationAccess');
    $method->setAccessible(true);

    $method->invoke($controller, $organization, $reason);

    $pivot = DB::table('project_organization')
        ->where('organization_id', $organization->id)
        ->first();

    $metadata = json_decode((string) $pivot->metadata, true);

    expect((bool) $pivot->is_active)->toBeFalse();
    expect($metadata['suspended_reason'] ?? null)->toBe($reason);
});

it('public auth entry points use strict auth throttle', function (string $method, string $uri) {
    $route = Route::getRoutes()->match(HttpRequest::create($uri, $method));

    expect($route->gatherMiddleware())->toContain('throttle:auth');
})->with([
    ['POST', '/api/v1/landing/auth/register'],
    ['POST', '/api/v1/landing/auth/login'],
    ['POST', '/api/v1/landing/auth/password/email'],
    ['POST', '/api/v1/landing/auth/password/reset'],
    ['POST', '/api/v1/mobile/auth/login'],
    ['POST', '/api/v1/admin/auth/login'],
]);

function createOrganizationUser(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['current_organization_id' => $organization->id]);

    $organization->users()->attach($user->id, [
        'is_owner' => true,
        'is_active' => true,
    ]);

    return [$organization, $user];
}

function createEstimate(int $organizationId, int $projectId, string $number): Estimate
{
    return Estimate::create([
        'organization_id' => $organizationId,
        'project_id' => $projectId,
        'number' => $number,
        'name' => "Estimate {$number}",
        'type' => 'local',
        'status' => 'draft',
        'estimate_date' => now()->toDateString(),
        'calculation_method' => 'resource',
    ]);
}

function createEstimateSection(int $estimateId): EstimateSection
{
    return EstimateSection::create([
        'estimate_id' => $estimateId,
        'section_number' => '1',
        'name' => 'Section 1',
        'sort_order' => 1,
    ]);
}
