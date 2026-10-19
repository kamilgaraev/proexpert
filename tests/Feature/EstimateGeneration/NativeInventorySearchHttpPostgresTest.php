<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationManualFactsController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationRoomIdentityController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class NativeInventorySearchHttpPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_empty_and_whitespace_search_preserve_the_unfiltered_inventory_through_global_http_middleware(): void
    {
        [$actor, $session, $prefix, $outboundBeforeReads] = $this->fixture();
        $this->withToken(auth('api_admin')->login($actor));
        foreach (['rooms' => 'sources', 'geometry' => 'entities'] as $endpoint => $rowsKey) {
            $baseline = $this->getJson($prefix.'/'.$endpoint.'?include_manual=1')->assertOk()->json('data');
            self::assertNotEmpty($baseline[$rowsKey]);
            foreach (['', '   '] as $search) {
                $this->getJson($prefix.'/'.$endpoint.'?include_manual=1&search='.rawurlencode($search))
                    ->assertOk()->assertJsonPath('data', $baseline);
            }
            $filtered = $this->getJson($prefix.'/'.$endpoint.'?include_manual=1&search=no-such-source-xyz')->assertOk()->json('data');
            self::assertSame([], $filtered[$rowsKey]);
            self::assertSame(0, $filtered['pagination']['total']);
            $this->getJson($prefix.'/'.$endpoint.'?search='.str_repeat('a', 101))->assertUnprocessable();
            $this->getJson($prefix.'/'.$endpoint.'?search%5B%5D=invalid')->assertUnprocessable();
        }
        self::assertSame(1, (int) $session->fresh()->state_version);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertCount($outboundBeforeReads, Http::recorded());
    }

    public function test_an_empty_search_does_not_bypass_the_service_tenant_scope(): void
    {
        [$actor, $session, $prefix, $outboundBeforeReads] = $this->fixture();
        $actor->update(['current_organization_id' => Organization::factory()->create()->id]);
        $this->withToken(auth('api_admin')->login($actor));
        foreach (['rooms', 'geometry'] as $endpoint) {
            $this->getJson($prefix.'/'.$endpoint.'?search=')->assertNotFound();
        }
        self::assertSame(1, (int) $session->fresh()->state_version);
        self::assertCount($outboundBeforeReads, Http::recorded());
    }

    private function fixture(): array
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['estimate-generation.universal_enabled' => true, 'jwt.secret' => str_repeat('test-key', 8), 'jwt.blacklist_enabled' => false]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => ['evaluation_mode' => 'universal']]);
        app(SaveManualProjectFacts::class)->handle($actor, (int) $project->id, (int) $session->id, 0, (string) Str::uuid(),
            ['entities' => [['key' => 'kitchen', 'type' => 'room', 'name' => 'Кухня', 'floor' => '1'],
                ['key' => 'bedroom', 'type' => 'room', 'name' => 'Спальня', 'floor' => '1']]]);
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session->fresh());
        $route = '/_contract/native-inventory/projects/{project}/sessions/{session}';
        Route::get($route.'/rooms', [EstimateGenerationRoomIdentityController::class, 'index'])->middleware(['auth:api_admin', SubstituteBindings::class]);
        Route::get($route.'/geometry', [EstimateGenerationManualFactsController::class, 'inventory'])->middleware(['auth:api_admin', SubstituteBindings::class]);

        return [$actor, $session, str_replace(['{project}', '{session}'], [(string) $project->id, (string) $session->id], $route), count(Http::recorded())];
    }
}
