<?php

declare(strict_types=1);

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

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
