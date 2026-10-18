<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\SiteRequests\Models\SiteRequestTemplate;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class SiteRequestTemplateMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_cost_validation_returns_mobile_field_errors_and_keeps_zero_valid(): void
    {
        Event::fake();
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $project->users()->attach($context->user->id, ['is_active' => true]);
        $this->allowAccess();
        $template = SiteRequestTemplate::create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'name' => 'Training mobile template',
            'request_type' => 'other',
            'template_data' => [
                'title' => 'Training mobile request',
                'request_type' => 'other',
                'metadata' => ['estimated_cost' => 'invalid'],
            ],
            'is_active' => true,
        ]);
        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson("/api/v1/mobile/site-requests/from-template/{$template->id}", ['project_id' => $project->id])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'http_422')
            ->assertJsonStructure(['errors' => ['estimated_cost']]);
        $this->assertDatabaseCount('site_requests', 0);
        $this->assertSame(0, $template->fresh()->usage_count);

        $template->update(['template_data' => [
            'title' => 'Training mobile request',
            'request_type' => 'other',
            'metadata' => ['estimated_cost' => 0],
        ]]);
        $this->postJson("/api/v1/mobile/site-requests/from-template/{$template->id}", ['project_id' => $project->id])
            ->assertCreated()->assertJsonPath('data.estimated_cost', 0);
        $this->assertDatabaseCount('site_requests', 1);
        $this->assertSame(1, $template->fresh()->usage_count);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))->get();
                }
            );
            $mock->shouldReceive('getUserPermissionsStructured')->andReturn([
                'system' => [], 'modules' => ['site-requests' => ['site_requests.view', 'site_requests.create']],
            ]);
        });
    }
}
