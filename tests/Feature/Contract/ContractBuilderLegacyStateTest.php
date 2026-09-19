<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class ContractBuilderLegacyStateTest extends TestCase
{
    public function test_legacy_state_is_read_only_and_does_not_bypass_organization_or_revoked_access(): void
    {
        $owner = Organization::factory()->create();
        $other = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $contract = Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id,
            'number' => 'LEGACY-STATE', 'date' => '2026-09-20', 'status' => 'draft',
            'total_amount' => 100, 'contract_side_type' => 'general_contract',
        ]);
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->withoutMiddleware();
        $this->actingAs($actor, 'api_admin');
        Event::listen(RouteMatched::class, static function (RouteMatched $event): void {
            $event->request->attributes->set('current_organization_id', $event->request->user()?->current_organization_id);
        });
        $url = '/api/v1/admin/contracts/'.$contract->id.'/builder';
        $before = $contract->fresh()->getRawOriginal();
        $this->getJson($url)->assertOk()->assertJsonPath('data.requires_enrollment', true)
            ->assertJsonPath('data.can_create', false)->assertJsonPath('data.can_adopt', false)
            ->assertJsonPath('data.revision', null);
        self::assertSame($before, $contract->fresh()->getRawOriginal());
        self::assertSame(0, $contract->organizationViews()->count());
        self::assertSame(0, $contract->parties()->count());
        $actor->current_organization_id = $other->id;
        $this->getJson($url)->assertNotFound();
        $actor->current_organization_id = $owner->id;
        $view = $contract->organizationViews()->create([
            'organization_id' => $owner->id, 'visibility' => 'active', 'version' => 1,
        ]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.requires_enrollment', false);
        $view->update(['access_revoked_at' => now()]);
        $this->getJson($url)->assertNotFound();
        $denied = \Mockery::mock(AuthorizationService::class);
        $denied->shouldReceive('can')->andReturn(false);
        $this->app->instance(AuthorizationService::class, $denied);
        $this->getJson($url)->assertForbidden();
    }
}
