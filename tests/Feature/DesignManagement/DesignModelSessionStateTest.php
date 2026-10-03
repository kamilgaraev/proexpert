<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Events\DesignModelSessionTransientEvent;
use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignModelSessionTransientEventRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignModelSessionViewStateRequest;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignModelSessionResource;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSession;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSessionEventOrder;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSet;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSetRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionStateService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelSetService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use DomainException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

final class DesignModelSessionStateTest extends TestCase
{
    private bool $moduleEnabled = true;

    private bool $permissionAllowed = true;

    private User $actor;

    private DesignModelSession $session;

    private DesignArtifactVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['design_management.session_cache_store' => 'array']);
        Cache::store('array')->flush();
        Event::fake([DesignModelSessionTransientEvent::class]);
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnUsing(fn (): bool => $this->moduleEnabled);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(fn (): bool => $this->permissionAllowed);
        $organization = Organization::factory()->create();
        $this->actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($this->actor->id, ['is_owner' => true, 'is_active' => true, 'project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $scope = ['organization_id' => $organization->id, 'project_id' => $project->id];
        $package = DesignPackage::query()->create([...$scope, 'title' => 'Комплект']);
        $artifact = DesignArtifact::query()->create([...$scope, 'package_id' => $package->id, 'title' => 'Модель']);
        $this->version = DesignArtifactVersion::query()->create([
            ...$scope, 'artifact_id' => $artifact->id, 'title' => 'Модель', 'version_number' => '1',
            'source_file_path' => "org-{$organization->id}/model.ifc", 'source_original_name' => 'model.ifc',
            'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 10,
        ]);
        $set = DesignModelSet::query()->create([...$scope, 'title' => 'Набор', 'revision' => 1]);
        $revision = DesignModelSetRevision::query()->create(['model_set_id' => $set->id, 'revision' => 1, 'version_ids' => [$this->version->id], 'transforms' => []]);
        $this->session = DesignModelSession::query()->create([...$scope, 'title' => 'Просмотр', 'model_set_id' => $set->id, 'model_set_revision_id' => $revision->id]);
    }

    public function test_server_envelope_monotonic_sequences_and_distinct_connections(): void
    {
        $first = $this->relay('camera', 10, $this->camera(), 'desktop');
        self::assertSame(2, $first['schema_version']);
        self::assertSame($this->actor->id, $first['sender']['id']);
        self::assertSame($this->actor->name, $first['sender']['name']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $first['sender']['color']);
        self::assertSame($this->session->id, $first['session_id']);
        self::assertSame($this->session->model_set_revision_id, $first['model_set_revision_id']);
        self::assertSame(now()->toIso8601String(), $first['occurred_at']);
        self::assertNull($this->relay('camera', 9, $this->camera(), 'desktop'));
        self::assertNull($this->relay('camera', 10, $this->camera(), 'desktop'));
        self::assertNotNull($this->relay('select', 1, ['model_version_id' => $this->version->id, 'element_id' => 12], 'desktop'));
        self::assertNotNull($this->relay('camera', 1, $this->camera(), 'mobile'));
        self::assertCount(2, $this->participants());
        Event::assertDispatchedTimes(DesignModelSessionTransientEvent::class, 3);
    }

    public function test_leave_order_survives_live_expiry_and_preserves_other_clients(): void
    {
        self::assertNotNull($this->relay('heartbeat', 4, null, 'desktop'));
        self::assertNotNull($this->relay('select', 2, ['model_version_id' => $this->version->id, 'element_id' => 12], 'desktop'));
        self::assertNotNull($this->relay('leave', 8, null, 'desktop'));
        self::assertSame([], $this->participants());
        $this->travel(121)->seconds();
        self::assertNull($this->relay('cursor', 6, ['x' => 1, 'y' => 2, 'z' => 3], 'desktop'));
        self::assertNull($this->relay('heartbeat', 7, null, 'desktop'));
        self::assertSame([], $this->participants());

        self::assertNotNull($this->relay('cursor', 9, ['x' => 1, 'y' => 2, 'z' => 3], 'desktop'));
        self::assertNotNull($this->relay('cursor', 6, ['x' => 3, 'y' => 2, 'z' => 1], 'mobile'));
        self::assertNull($this->relay('leave', 8, null, 'desktop'));
        $participants = collect($this->participants())->keyBy('client_id');
        self::assertCount(2, $participants);
        self::assertSame(9, $participants['desktop']['max_sequence']);
        self::assertSame(6, $participants['mobile']['max_sequence']);

        $order = DesignModelSessionEventOrder::query()->where('session_id', $this->session->id)
            ->where('client_id', 'desktop')->firstOrFail();
        self::assertSame((int) $this->session->organization_id, (int) $order->organization_id);
        self::assertSame((int) $this->session->model_set_revision_id, (int) $order->model_set_revision_id);
        self::assertSame((int) $this->actor->id, (int) $order->user_id);
        self::assertSame(9, $order->max_sequence);
        self::assertSame(8, $order->leave_sequence);
        foreach (['heartbeat' => 4, 'select' => 2, 'leave' => 8, 'cursor' => 9] as $type => $sequence) {
            self::assertSame($sequence, $order->sequences[$type]);
        }

        self::assertNotNull($this->relay('leave', 10, null, 'desktop'));
        self::assertSame(['mobile'], array_column($this->participants(), 'client_id'));
        $this->session->delete();
        self::assertSame(0, DesignModelSessionEventOrder::query()->where('session_id', $this->session->id)->count());
    }

    public function test_ordering_ownership_survives_live_state_expiry(): void
    {
        self::assertNotNull($this->relay('heartbeat', 1, null, 'desktop'));
        $this->travel(46)->seconds();
        self::assertSame([], $this->participants());
        $other = User::factory()->create(['current_organization_id' => $this->session->organization_id]);
        Organization::query()->findOrFail($this->session->organization_id)->users()->attach($other->id, [
            'is_owner' => true, 'is_active' => true, 'project_access_mode' => 'all_projects',
        ]);
        $service = app(DesignModelSessionStateService::class);
        $this->assertInvalid(fn () => $service->relay((int) $this->session->organization_id, $other, $this->session->id, [
            'schema_version' => '2', 'type' => 'heartbeat', 'sequence' => 2, 'client_id' => 'desktop', 'payload' => null,
        ]));
        $this->assertInvalid(fn () => $service->relay((int) $this->session->organization_id, $other, $this->session->id, [
            'type' => 'heartbeat', 'client_id' => 'desktop', 'sequence' => 2, 'payload' => null,
        ]));
    }

    public function test_cache_failure_rolls_back_ordering_and_allows_same_sequence_retry(): void
    {
        $store = new class extends ArrayStore
        {
            public bool $failNextClientPut = true;

            public function put($key, $value, $seconds)
            {
                if ($this->failNextClientPut && str_contains($key, ':client:')) {
                    $this->failNextClientPut = false;

                    throw new RuntimeException('simulated_cache_failure');
                }

                return parent::put($key, $value, $seconds);
            }
        };
        Cache::extend('fail-first-client-put', fn (): Repository => new Repository($store));
        config(['cache.stores.fail-first-client-put' => ['driver' => 'fail-first-client-put'],
            'design_management.session_cache_store' => 'fail-first-client-put']);

        try {
            $this->relay('heartbeat', 1, null);
            self::fail('Expected cache failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated_cache_failure', $exception->getMessage());
        }
        self::assertSame(0, DesignModelSessionEventOrder::query()->where('session_id', $this->session->id)->count());
        self::assertNotNull($this->relay('heartbeat', 1, null));
        self::assertNotNull($this->relay('cursor', 1, ['x' => 1, 'y' => 2, 'z' => 3]));
        self::assertSame(1, DesignModelSessionEventOrder::query()->where('session_id', $this->session->id)->count());
    }

    public function test_real_legacy_wire_receives_positive_durable_sequences(): void
    {
        $service = app(DesignModelSessionStateService::class);
        $relay = function (string $type, ?array $payload) use ($service): ?array {
            $data = ['type' => $type, 'payload' => $payload];
            $request = new StoreDesignModelSessionTransientEventRequest;
            $request->replace($data);
            $validator = Validator::make($data, $request->rules());
            $request->withValidator($validator);
            self::assertTrue($validator->passes(), $validator->errors()->toJson());

            return $service->relay((int) $this->session->organization_id, $this->actor, $this->session->id,
                $validator->validated());
        };
        $clientId = 'legacy-'.$this->actor->id;
        $camera = $relay('camera', ['position' => [1, 2, 3], 'target' => [0, 0, 0]]);
        self::assertSame(1, $camera['sequence']);
        self::assertSame(2, $camera['schema_version']);
        self::assertSame($clientId, $camera['client_id']);
        $select = $relay('select', ['model_version_id' => $this->version->id, 'element_id' => 'legacy-guid']);
        self::assertSame(2, $select['sequence']);
        self::assertSame('legacy-guid', $select['payload']['element_id']);
        self::assertSame(3, $relay('leave', null)['sequence']);
        self::assertSame([], $this->participants());
        $this->travel(121)->seconds();
        self::assertSame(4, $relay('heartbeat', null)['sequence']);
        self::assertSame(5, $relay('camera', ['position' => [1, 2, 3], 'target' => [0, 0, 0]])['sequence']);
        self::assertSame([$clientId], array_column($this->participants(), 'client_id'));
        $order = DesignModelSessionEventOrder::query()->where('session_id', $this->session->id)->firstOrFail();
        self::assertSame((int) $this->actor->id, (int) $order->user_id);
        self::assertSame(5, $order->max_sequence);
        self::assertSame(3, $order->leave_sequence);
    }

    public function test_string_version_two_uses_durable_leave_barrier_after_live_expiry(): void
    {
        $service = app(DesignModelSessionStateService::class);
        self::assertNotNull($this->relay('leave', 8, null));
        $this->travel(121)->seconds();
        $data = [
            'schema_version' => '2', 'type' => 'cursor', 'sequence' => 6, 'client_id' => 'desktop',
            'payload' => ['x' => 1, 'y' => 2, 'z' => 3],
        ];
        $request = new StoreDesignModelSessionTransientEventRequest;
        $request->replace($data);
        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);
        self::assertTrue($validator->passes(), $validator->errors()->toJson());
        self::assertSame('2', $validator->validated()['schema_version']);
        self::assertNull($service->relay((int) $this->session->organization_id, $this->actor, $this->session->id, $validator->validated()));
        self::assertSame([], $this->participants());
        self::assertSame(8, DesignModelSessionEventOrder::query()->where('session_id', $this->session->id)->firstOrFail()->max_sequence);
    }

    public function test_version_two_cannot_claim_legacy_namespace_in_event_or_view_state(): void
    {
        $clientId = 'legacy-'.$this->actor->id;
        $this->assertInvalid(fn () => $this->relay('heartbeat', 1, null, $clientId));
        $service = app(DesignModelSessionStateService::class);
        $this->assertInvalid(fn () => $service->storeViewState((int) $this->session->organization_id,
            $this->actor, $this->session->id, ['client_id' => $clientId, 'sequence' => 1, 'view_state' => $this->viewState()]));
    }

    public function test_snapshot_heartbeat_expiry_leave_and_cursor_clear(): void
    {
        $this->relay('cursor', 1, ['x' => 1, 'y' => 2, 'z' => 3]);
        $this->relay('cursor', 2, null);
        self::assertNull($this->participants()[0]['latest_events']['cursor']['payload']);
        $this->travel(30)->seconds();
        $this->relay('heartbeat', 1, null);
        $this->travel(30)->seconds();
        self::assertCount(1, $this->participants());
        $this->travel(46)->seconds();
        self::assertSame([], $this->participants());
        $this->relay('heartbeat', 2, null);
        $this->relay('leave', 3, null);
        self::assertSame([], $this->participants());
    }

    public function test_full_view_state_is_pinned_retrievable_and_monotonic(): void
    {
        $service = app(DesignModelSessionStateService::class);
        $data = ['client_id' => 'desktop', 'sequence' => 5, 'view_state' => $this->viewState()];
        $saved = $service->storeViewState((int) $this->session->organization_id, $this->actor, $this->session->id, $data);
        self::assertSame(1, $saved['revision']);
        self::assertSame($data['view_state'], $saved['view_state']);
        self::assertSame($saved, $service->viewState((int) $this->session->organization_id, $this->actor, $this->session->id, 'desktop', 1));
        $selected = $this->relay('select', 1, ['model_version_id' => $this->version->id, 'element_id' => '15']);
        self::assertSame(15, $selected['payload']['element_id']);
        self::assertNull($service->viewState((int) $this->session->organization_id, $this->actor, $this->session->id, 'desktop', 2));
        self::assertNull($service->storeViewState((int) $this->session->organization_id, $this->actor, $this->session->id, $data));
        Event::assertDispatched(DesignModelSessionTransientEvent::class, fn ($event): bool => $event->broadcastWith()['payload'] === ['revision' => 1]);
        $data['sequence'] = 6;
        $data['view_state']['camera']['zoom'] = 2;
        $newer = $service->storeViewState((int) $this->session->organization_id, $this->actor, $this->session->id, $data);
        self::assertSame(2, $newer['revision']);
        self::assertSame($saved, $service->viewState((int) $this->session->organization_id, $this->actor, $this->session->id, 'desktop', 1));
        $this->travel(30)->seconds();
        $this->relay('heartbeat', 1, null, 'desktop');
        $this->travel(30)->seconds();
        self::assertNotNull($service->viewState((int) $this->session->organization_id, $this->actor, $this->session->id, 'desktop', 2));
        $this->relay('leave', 7, null, 'desktop');
        self::assertNull($service->viewState((int) $this->session->organization_id, $this->actor, $this->session->id, 'desktop'));
    }

    public function test_bootstrap_contains_restorable_participants_and_public_realtime_configuration(): void
    {
        $this->relay('camera', 1, $this->camera());
        config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'public-key', 'reverb.apps.apps.0.secret' => 'do-not-publish']);
        $session = app(DesignModelSetService::class)->sessionBootstrap((int) $this->session->organization_id, $this->actor, $this->session->id);
        $data = (new DesignModelSessionResource($session))->resolve();
        self::assertCount(1, $data['participants']);
        self::assertSame(45, $data['realtime']['participant_ttl_seconds']);
        self::assertSame(15, $data['realtime']['heartbeat_interval_seconds']);
        self::assertSame('public-key', $data['realtime']['key']);
        self::assertArrayNotHasKey('secret', $data['realtime']);
    }

    public function test_core_numeric_element_ids_and_digitstring_version_ids_round_trip(): void
    {
        $data = ['client_id' => 'desktop', 'sequence' => 1, 'view_state' => $this->viewState()];
        $data['view_state']['models'][0]['version_id'] = (string) $this->version->id;
        $data['view_state']['models'][0]['hidden_element_ids'] = [12, '13'];
        $data['view_state']['models'][0]['isolated_element_ids'] = [14];
        $data['view_state']['selection'] = [['version_id' => (string) $this->version->id, 'element_id' => 15]];
        self::assertTrue($this->validViewState($data));
        $service = app(DesignModelSessionStateService::class);
        $saved = $service->storeViewState((int) $this->session->organization_id, $this->actor, $this->session->id, $data);
        self::assertSame((string) $this->version->id, $saved['view_state']['models'][0]['version_id']);
        self::assertSame((string) $this->session->model_set_revision_id, $saved['view_state']['model_set_revision_id']);
        self::assertSame([12, 13], $saved['view_state']['models'][0]['hidden_element_ids']);
        self::assertSame([14], $saved['view_state']['models'][0]['isolated_element_ids']);
        self::assertSame([['version_id' => (string) $this->version->id, 'element_id' => 15]], $saved['view_state']['selection']);
        self::assertSame($saved, $service->viewState((int) $this->session->organization_id, $this->actor, $this->session->id, 'desktop', 1));
        $data['view_state']['selection'][0]['element_id'] = 'wall';
        self::assertFalse($this->validViewState($data));
        $data['view_state']['selection'][0]['element_id'] = 0;
        self::assertFalse($this->validViewState($data));
    }

    public function test_each_event_rechecks_current_module_permission_and_pinned_version_scope(): void
    {
        $this->relay('heartbeat', 1, null);
        $this->moduleEnabled = false;
        $this->assertDenied(fn () => $this->relay('heartbeat', 2, null));
        $this->moduleEnabled = true;
        $this->permissionAllowed = false;
        $this->assertDenied(fn () => $this->participants());
        $this->permissionAllowed = true;
        $this->version->update(['file_format' => 'pdf']);
        $this->assertDenied(fn () => $this->relay('heartbeat', 3, null));
    }

    public function test_other_organization_and_revision_cannot_use_the_same_session(): void
    {
        $this->assertDenied(fn () => app(DesignModelSessionStateService::class)->relay((int) $this->session->organization_id + 1, $this->actor, $this->session->id, ['type' => 'heartbeat', 'payload' => null]));
        $state = $this->viewState();
        $state['model_set_revision_id']++;
        $this->expectException(ValidationException::class);
        app(DesignModelSessionStateService::class)->storeViewState((int) $this->session->organization_id, $this->actor, $this->session->id, ['client_id' => 'desktop', 'sequence' => 1, 'view_state' => $state]);
    }

    public function test_legacy_camera_and_nullable_clears_validate_but_malformed_vectors_do_not(): void
    {
        self::assertTrue($this->validEvent(['type' => 'camera', 'payload' => ['position' => [1, 2, 3], 'target' => [0, 0, 0]]]));
        self::assertTrue($this->validEvent(['schema_version' => 2, 'client_id' => 'desktop', 'sequence' => 1, 'type' => 'cursor', 'payload' => null]));
        self::assertTrue($this->validEvent(['schema_version' => '2', 'client_id' => 'desktop', 'sequence' => 1, 'type' => 'cursor', 'payload' => null]));
        self::assertTrue($this->validEvent(['type' => 'select', 'payload' => ['model_version_id' => null, 'element_id' => null]]));
        self::assertTrue($this->validEvent(['type' => 'select', 'payload' => ['model_version_id' => $this->version->id, 'element_id' => 'wall']]));
        self::assertTrue($this->validEvent(['schema_version' => 2, 'client_id' => 'desktop', 'sequence' => 1, 'type' => 'select', 'payload' => ['model_version_id' => $this->version->id, 'element_id' => 12]]));
        self::assertTrue($this->validEvent(['schema_version' => 2, 'client_id' => 'desktop', 'sequence' => 1, 'type' => 'select', 'payload' => ['model_version_id' => $this->version->id, 'element_id' => '12']]));
        self::assertFalse($this->validEvent(['schema_version' => 2, 'client_id' => 'desktop', 'sequence' => 1, 'type' => 'select', 'payload' => ['model_version_id' => $this->version->id, 'element_id' => 'wall']]));
        self::assertFalse($this->validEvent(['type' => 'cursor', 'payload' => ['x' => '1', 'y' => 2, 'z' => 3]]));
        self::assertFalse($this->validEvent(['schema_version' => 2, 'type' => 'camera', 'payload' => $this->camera()]));
        self::assertFalse($this->validEvent(['type' => 'heartbeat', 'client_id' => 'desktop', 'payload' => null]));
        self::assertFalse($this->validEvent(['type' => 'heartbeat', 'sequence' => 1, 'payload' => null]));
        self::assertFalse($this->validEvent(['schema_version' => 2, 'client_id' => 'legacy-1', 'sequence' => 1, 'type' => 'heartbeat', 'payload' => null]));
        $state = $this->viewState();
        $request = new StoreDesignModelSessionViewStateRequest;
        $data = ['client_id' => 'desktop', 'sequence' => 1, 'view_state' => $state];
        $request->replace($data);
        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);
        self::assertTrue($validator->passes(), $validator->errors()->toJson());
        $data['view_state']['models'][0]['transform']['shift'][0] = 'NaN';
        $request->replace($data);
        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);
        self::assertFalse($validator->passes());
    }

    private function relay(string $type, int $sequence, ?array $payload, string $clientId = 'desktop'): ?array
    {
        return app(DesignModelSessionStateService::class)->relay((int) $this->session->organization_id, $this->actor, $this->session->id, [
            'schema_version' => 2, 'type' => $type, 'sequence' => $sequence, 'client_id' => $clientId, 'payload' => $payload,
            'sender' => ['id' => 999, 'name' => 'spoofed'], 'occurred_at' => '1900-01-01', 'model_set_revision_id' => 999,
        ]);
    }

    private function participants(): array
    {
        return app(DesignModelSessionStateService::class)->participants((int) $this->session->organization_id, $this->actor, $this->session->id);
    }

    private function camera(): array
    {
        return ['position' => [1, 2, 3], 'target' => [0, 0, 0], 'projection' => 'perspective', 'up' => [0, 1, 0], 'fov' => 45, 'zoom' => 1, 'aspect' => 1.5];
    }

    private function viewState(): array
    {
        return ['schema_version' => 1, 'model_set_revision_id' => (string) $this->session->model_set_revision_id, 'camera' => $this->camera(),
            'models' => [['version_id' => (string) $this->version->id, 'transform' => ['shift' => [0, 0, 0], 'rotation' => 0], 'visible' => true, 'hidden_element_ids' => [12], 'isolated_element_ids' => []]],
            'selection' => [['version_id' => (string) $this->version->id, 'element_id' => 12]],
            'sections' => [['id' => 'floor', 'normal' => [0, 1, 0], 'constant' => 1, 'enabled' => true]]];
    }

    private function validEvent(array $data): bool
    {
        $request = new StoreDesignModelSessionTransientEventRequest;
        $request->replace($data);
        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);

        return $validator->passes();
    }

    private function validViewState(array $data): bool
    {
        $request = new StoreDesignModelSessionViewStateRequest;
        $request->replace($data);
        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);

        return $validator->passes();
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Session access must be denied.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }

    private function assertInvalid(callable $operation): void
    {
        try {
            $operation();
            self::fail('Invalid session event must be rejected.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }
}
