<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use App\Services\Contract\ContractLibraryService;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ContractLibraryApiTest extends TestCase
{
    public function test_calculation_returns_preview_and_field_errors_without_creating_a_revision(): void
    {
        $this->withoutMiddleware();
        $owner = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $this->actingAs($actor, 'api_admin');
        Event::listen(RouteMatched::class, static function (RouteMatched $event): void {
            $event->request->attributes->set('current_organization_id', $event->request->user()?->current_organization_id);
        });
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $library = new ContractLibraryService($authorization);
        $input = $library->create($actor, $owner->id, 'variable', 'Делитель', ['type' => 'number', 'required' => true], 'input');
        $inputId = $input['item']['id'];
        $library->publish($actor, $owner->id, $inputId, 1, 1);
        $computed = $library->create($actor, $owner->id, 'variable', 'Результат', ['type' => 'number', 'source' => ['kind' => 'formula', 'expression' => [
            'kind' => 'operation', 'operator' => 'divide', 'scale' => 2, 'args' => [
                ['kind' => 'literal', 'type' => 'number', 'value' => '100.01'],
                ['kind' => 'reference', 'variable_id' => $inputId],
            ],
        ]]], 'computed');
        $computedId = $computed['item']['id'];
        $library->publish($actor, $owner->id, $computedId, 1, 1);
        $template = $library->create($actor, $owner->id, 'template', 'Расчёт', [
            'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $computedId]]]]]],
            'variables' => [$computedId => 1, $inputId => 1],
        ], 'template');
        $url = '/api/v1/admin/contract-library/'.$template['item']['id'].'/versions/1/calculate';
        $this->postJson($url, ['values' => [$inputId => '2']])->assertUnprocessable();
        $library->publish($actor, $owner->id, $template['item']['id'], 1, 1);
        $before = \Illuminate\Support\Facades\DB::table('contract_builder_revisions')->count();
        $result = $this->postJson($url, ['values' => [$inputId => '2']])->assertOk()->assertJsonPath('data.values.'.$computedId, '50.01')->json('data');
        self::assertSame(['template_id', 'template_version', 'values', 'html'], array_keys($result));
        self::assertStringContainsString('50.01', $result['html']);
        self::assertSame($result, $this->postJson($url, ['values' => [$inputId => '2']])->assertOk()->json('data'));
        $this->postJson($url, ['values' => []])->assertUnprocessable()->assertJsonValidationErrors('values.'.$inputId);
        $this->postJson($url, ['values' => [$inputId => '0']])->assertUnprocessable()->assertJsonValidationErrors('values.'.$computedId);
        $this->postJson($url, ['values' => [$inputId => '2', $computedId => '50.01']])->assertUnprocessable()->assertJsonValidationErrors('values.'.$computedId);
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('values');
        self::assertSame($before, \Illuminate\Support\Facades\DB::table('contract_builder_revisions')->count());
        $foreign = Organization::factory()->create();
        $this->actingAs(User::factory()->create(['current_organization_id' => $foreign->id]), 'api_admin');
        $this->postJson($url, ['values' => [$inputId => '2']])->assertNotFound();
        $this->actingAs($actor, 'api_admin');
        $library->archive($actor, $owner->id, $template['item']['id'], 2, true);
        $this->postJson($url, ['values' => [$inputId => '2']])->assertUnprocessable();
        $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($url, 'POST'));
        self::assertContains('authorize:contracts.library.view', $route->gatherMiddleware());
    }

    public function test_resolved_template_exposes_pinned_block_definitions_only_to_its_organization(): void
    {
        $this->withoutMiddleware();
        $owner = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $this->actingAs($actor, 'api_admin');
        Event::listen(RouteMatched::class, static function (RouteMatched $event): void {
            $event->request->attributes->set('current_organization_id', $event->request->user()?->current_organization_id);
        });
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $library = new ContractLibraryService($authorization);
        $field = $library->create($actor, $owner->id, 'variable', 'Цена', ['type' => 'money'], 'field');
        $fieldId = $field['item']['id'];
        $library->publish($actor, $owner->id, $fieldId, 1, 1);
        $block = $library->create($actor, $owner->id, 'block', 'Стоимость', [
            'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $fieldId]]]]]],
            'variables' => [$fieldId => 1],
        ], 'block');
        $library->publish($actor, $owner->id, $block['item']['id'], 1, 1);
        $template = $library->create($actor, $owner->id, 'template', 'Подряд', [
            'document' => ['type' => 'doc', 'content' => [['type' => 'blockReference', 'attrs' => ['blockId' => $block['item']['id'], 'version' => 1, 'instanceId' => 'price']]]],
            'variables' => [],
        ], 'template');
        $url = '/api/v1/admin/contract-library/'.$template['item']['id'].'/versions/1/resolved';
        $this->getJson($url)->assertUnprocessable();
        $library->publish($actor, $owner->id, $template['item']['id'], 1, 1);
        $result = $this->getJson($url)->assertOk()->assertJsonPath('data.template_id', $template['item']['id'])
            ->assertJsonPath('data.template_version', 1)->assertJsonPath('data.definitions.'.$fieldId.'.version', 1)
            ->assertJsonPath('data.definitions.'.$fieldId.'.definition.type', 'money')
            ->assertJsonPath('data.document.content.0.type', 'paragraph')->json('data');
        self::assertSame(1, $result['blocks']['price']['content']['variables'][$fieldId]);
        self::assertSame(['template_id', 'template_version', 'document', 'definitions', 'blocks'], array_keys($result));
        $foreign = Organization::factory()->create();
        $foreignActor = User::factory()->create(['current_organization_id' => $foreign->id]);
        $this->actingAs($foreignActor, 'api_admin');
        $this->getJson($url)->assertNotFound();
        $this->actingAs($actor, 'api_admin');
        $library->archive($actor, $owner->id, $template['item']['id'], 2, true);
        $this->getJson($url)->assertUnprocessable();
    }

    public function test_batch_definitions_reads_500_pinned_versions_with_bounded_queries_and_no_foreign_data(): void
    {
        $this->withoutMiddleware();
        $owner = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $this->actingAs($actor, 'api_admin');
        Event::listen(RouteMatched::class, static function (RouteMatched $event) use ($actor): void {
            $event->request->attributes->set('current_organization_id', $actor->current_organization_id);
        });
        $allowed = true;
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(static function () use (&$allowed): bool { return $allowed; });
        $this->app->instance(AuthorizationService::class, $authorization);
        $items = [];
        $versions = [];
        $references = [];
        for ($index = 0; $index < 500; $index++) {
            $id = (string) \Illuminate\Support\Str::uuid();
            $references[] = ['id' => $id, 'version' => 1];
            $items[] = ['id' => $id, 'organization_id' => $owner->id, 'kind' => 'variable', 'is_archived' => $index === 0,
                'creation_key' => 'batch-'.$index, 'creation_fingerprint' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()];
            $versions[] = ['item_id' => $id, 'organization_id' => $owner->id, 'version_number' => 1, 'title' => 'Одинаковая подпись',
                'content' => json_encode(['type' => 'money', 'constraints' => ['min' => '999999999999999999.01']]),
                'created_by' => $actor->id, 'created_at' => now(), 'request_key' => 'first', 'request_fingerprint' => str_repeat('b', 64)];
        }
        \Illuminate\Support\Facades\DB::table('contract_library_items')->insert($items);
        \Illuminate\Support\Facades\DB::table('contract_library_versions')->insert($versions);
        \Illuminate\Support\Facades\DB::table('contract_library_versions')->insert(array_replace($versions[0], ['version_number' => 2, 'title' => 'Новая подпись', 'request_key' => 'second']));
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'contract_library_versions') && str_starts_with($query->sql, 'select')) {
                $queries++;
            }
        });
        $url = '/api/v1/admin/contract-library/definitions/read';
        $data = $this->postJson($url, ['references' => $references])->assertOk()->assertJsonCount(500, 'data')->json('data');
        self::assertSame(2, $queries);
        self::assertSame('Одинаковая подпись', $data[$references[0]['id']]['title']);
        self::assertTrue($data[$references[0]['id']]['is_archived']);
        self::assertSame('999999999999999999.01', $data[$references[499]['id']]['definition']['constraints']['min']);
        self::assertSame(['id', 'version', 'version_id', 'title', 'definition', 'status', 'is_archived'], array_keys($data[$references[0]['id']]));
        $empty = $this->postJson($url, ['references' => []])->assertOk();
        self::assertInstanceOf(\stdClass::class, json_decode($empty->getContent())->data);
        $this->postJson($url, ['references' => array_merge($references, [$references[0]])])->assertUnprocessable();
        $this->postJson($url, ['references' => [$references[0], $references[0]]])->assertUnprocessable();
        $this->postJson($url, ['references' => [['id' => 'bad', 'version' => 1]]])->assertUnprocessable();
        $this->postJson($url, ['references' => [['id' => $references[0]['id'], 'version' => 0]]])->assertUnprocessable();
        $foreign = Organization::factory()->create();
        $foreignActor = User::factory()->create(['current_organization_id' => $foreign->id]);
        $foreignItem = (new ContractLibraryService($authorization))->create($foreignActor, $foreign->id, 'variable', 'Чужое определение', ['type' => 'text'], 'foreign');
        $this->postJson($url, ['references' => [$references[0], ['id' => $foreignItem['item']['id'], 'version' => 1]]])->assertNotFound();
        $this->postJson($url, ['references' => [['id' => $references[0]['id'], 'version' => 99]]])->assertNotFound();
        $allowed = false;
        $this->postJson($url, ['references' => [$references[0]]])->assertForbidden();
    }

    public function test_builder_http_shares_revision_and_preview_but_not_library_or_project_access(): void
    {
        $this->withoutMiddleware();
        $owner = Organization::factory()->create();
        $executor = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $otherActor = User::factory()->create(['current_organization_id' => $executor->id]);
        $foreignActor = User::factory()->create(['current_organization_id' => $foreign->id]);
        $this->actingAs($actor, 'api_admin');
        Event::listen(RouteMatched::class, static function (RouteMatched $event): void {
            $event->request->attributes->set('current_organization_id', $event->request->user()?->current_organization_id);
        });
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $project = \App\Models\Project::factory()->create(['organization_id' => $foreign->id]);
        $contractor = \App\Models\Contractor::create(['organization_id' => $owner->id, 'source_organization_id' => $executor->id, 'name' => 'Исполнитель']);
        $contract = \App\Models\Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
            'number' => 'API-BUILDER', 'date' => '2026-09-19', 'status' => 'draft', 'total_amount' => 100, 'contract_side_type' => 'subcontract',
        ]);
        $contract->parties()->create(['side' => 'first', 'role' => 'contractor', 'linked_organization_id' => $owner->id, 'name' => 'Подрядчик', 'snapshot' => []]);
        $contract->parties()->create(['side' => 'second', 'role' => 'subcontractor', 'linked_organization_id' => $executor->id, 'name' => 'Исполнитель', 'snapshot' => []]);
        (new \App\Services\Contract\ContractOrganizationViewService($authorization))->synchronizeNewContract($contract);
        $library = new ContractLibraryService($authorization);
        $template = $library->create($actor, $owner->id, 'template', 'Договор', [
            'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Сохранённые условия']]]]], 'variables' => [],
        ], 'template');
        $library->publish($actor, $owner->id, $template['item']['id'], 1, 1);
        $base = '/api/v1/admin/contracts/'.$contract->id.'/builder';
        $this->getJson($base)->assertOk()->assertJsonPath('data.revision', null)->assertJsonPath('data.can_create', true);
        $prepared = $this->getJson('/api/v1/admin/contract-library/'.$template['item']['id'].'/versions/1/resolved')->assertOk();
        $preparedJson = json_decode($prepared->getContent());
        self::assertInstanceOf(\stdClass::class, $preparedJson->data->definitions);
        self::assertInstanceOf(\stdClass::class, $preparedJson->data->blocks);
        $payload = ['template_id' => $template['item']['id'], 'template_version' => 1, 'values' => (object) [], 'request_key' => 'create'];
        $response = $this->postJson($base, $payload)->assertOk()->assertJsonPath('data.revision_number', 1);
        $json = json_decode($response->getContent());
        self::assertInstanceOf(\stdClass::class, $json->data->values);
        self::assertInstanceOf(\stdClass::class, $json->data->definitions);
        self::assertInstanceOf(\stdClass::class, $json->data->blocks);
        $revision = $response->json('data');
        $this->postJson($base, $payload)->assertOk()->assertJsonPath('data.id', $revision['id']);
        $this->postJson($base, array_replace($payload, ['request_key' => 'different']))->assertConflict()->assertJsonPath('code', 'http_409');
        $this->postJson($base, array_replace($payload, ['template_id' => 'invalid']))->assertUnprocessable();
        $this->actingAs($otherActor, 'api_admin');
        $this->getJson($base)->assertOk()->assertJsonPath('data.revision.id', $revision['id'])->assertJsonPath('data.can_create', false);
        $this->getJson($base.'/revisions/1')->assertOk()->assertJsonPath('data.id', $revision['id']);
        $this->getJson($base.'/revisions/1/preview')->assertOk()
            ->assertJsonPath('data.revision.id', $revision['id'])
            ->assertJsonPath('data.html', '<article class="contract-document"><p>Сохранённые условия</p></article>');
        $this->getJson('/api/v1/admin/contract-library/'.$template['item']['id'].'/versions/1')->assertNotFound();
        $this->actingAs($foreignActor, 'api_admin');
        $this->getJson($base.'/revisions/1')->assertNotFound();
        $this->getJson($base)->assertNotFound();
        $this->getJson($base.'/revisions/1/preview')->assertNotFound();
    }

    public function test_library_http_validation_pagination_versions_and_organization_boundary(): void
    {
        $this->withoutMiddleware();
        $owner = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $this->actingAs($actor, 'api_admin');
        Event::listen(RouteMatched::class, static function (RouteMatched $event) use ($actor): void {
            $event->request->attributes->set('current_organization_id', $actor->current_organization_id);
        });
        $allowPublish = false;
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(static function ($user, string $permission) use (&$allowPublish): bool {
            return $permission !== 'contracts.library.publish' || $allowPublish;
        });
        $this->app->instance(AuthorizationService::class, $authorization);
        $base = '/api/v1/admin/contract-library';
        $payload = ['kind' => 'variable', 'title' => 'Цена', 'content' => ['type' => 'money'], 'request_key' => 'create'];
        $created = $this->postJson($base, $payload)->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.version.status', 'draft')->json('data');
        self::assertArrayNotHasKey('creation_key', $created['item']);
        self::assertArrayNotHasKey('request_fingerprint', $created['version']);
        self::assertArrayNotHasKey('request_key', $created['version']);
        $this->postJson($base, $payload)->assertOk()->assertJsonPath('data.item.id', $created['item']['id']);
        $this->postJson($base, array_replace($payload, ['title' => 'Конфликт']))->assertConflict()->assertJsonPath('code', 'http_409');
        $this->postJson($base, array_replace($payload, ['content' => ['type' => 'unknown'], 'request_key' => 'bad-definition']))->assertUnprocessable()->assertJsonPath('code', 'http_422');
        $id = $created['item']['id'];
        $this->postJson($base.'/'.$id.'/versions/1/publish', ['expected_version' => 1])->assertForbidden();
        $allowPublish = true;
        $this->postJson($base.'/'.$id.'/versions/1/publish', ['expected_version' => 1])->assertOk()->assertJsonPath('data.version.status', 'published');
        $this->postJson($base.'/'.$id.'/versions', ['title' => 'Стоимость', 'content' => ['type' => 'money'], 'request_key' => 'next', 'expected_version' => 2])
            ->assertOk()->assertJsonPath('data.version.version_number', 2);
        $this->getJson($base.'/'.$id.'/versions/1')->assertOk()->assertJsonPath('data.version.title', 'Цена');
        $this->postJson($base, array_replace($payload, ['title' => 'Второе поле', 'request_key' => 'second']))->assertOk();
        $foreignActor = User::factory()->create(['current_organization_id' => $foreign->id]);
        $foreignItem = (new ContractLibraryService($authorization))->create($foreignActor, $foreign->id, 'variable', 'Чужое поле', ['type' => 'text'], 'foreign');
        $this->getJson($base.'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->getJson($base.'?search='.rawurlencode('Стоимость'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson($base.'?status=published&kind=variable')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.version_number', 1)->assertJsonPath('data.0.title', 'Цена')
            ->assertJsonPath('data.0.status', 'published');
        $this->getJson($base.'?status=draft&search='.rawurlencode('Стоимость'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.version_number', 2);
        $this->getJson($base.'?status=published&search='.rawurlencode('Стоимость'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'?status=invalid')->assertUnprocessable();
        $this->getJson($base.'/'.$foreignItem['item']['id'].'/versions/1')->assertNotFound();
        $this->getJson($base.'?per_page=101')->assertUnprocessable();
        $this->postJson($base, array_replace($payload, ['kind' => 'invalid']))->assertUnprocessable();
        $this->patchJson($base.'/'.$id.'/archive', ['expected_version' => 3, 'archived' => true])->assertOk()->assertJsonPath('data.item.is_archived', true);
        $this->getJson($base)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($base.'?status=published')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'?status=published&archived=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.version_number', 1);
        $this->getJson($base.'?archived=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
    }

    public function test_registered_routes_keep_separate_permissions(): void
    {
        $expected = [
            ['POST', 'api/v1/admin/contract-library', 'contracts.library.create'],
            ['GET', 'api/v1/admin/contract-library', 'contracts.library.view'],
            ['POST', 'api/v1/admin/contract-library/definitions/read', 'contracts.library.view'],
            ['GET', 'api/v1/admin/contract-library/{libraryItem}/versions/{libraryVersion}/resolved', 'contracts.library.view'],
            ['POST', 'api/v1/admin/contract-library/{libraryItem}/versions/{libraryVersion}/publish', 'contracts.library.publish'],
            ['PATCH', 'api/v1/admin/contract-library/{libraryItem}/archive', 'contracts.library.archive'],
            ['POST', 'api/v1/admin/contracts/{contract}/builder', 'contracts.edit'],
            ['GET', 'api/v1/admin/contracts/{contract}/builder', 'contracts.view'],
            ['GET', 'api/v1/admin/contracts/{contract}/builder/revisions/{revision}/preview', 'contracts.view'],
            ['POST', 'api/v1/admin/contracts/{contract}/builder/revisions/{revision}/export', 'contracts.view'],
            ['GET', 'api/v1/admin/contracts/{contract}/builder/draft/source-changes', 'contracts.edit'],
            ['GET', 'api/v1/admin/contract-library/entities', 'contracts.view'],
        ];
        foreach ($expected as [$method, $uri, $permission]) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route): bool => $route->uri() === $uri && in_array($method, $route->methods(), true));
            self::assertNotNull($route);
            self::assertContains('authorize:'.$permission, $route->gatherMiddleware());
        }
    }
}
