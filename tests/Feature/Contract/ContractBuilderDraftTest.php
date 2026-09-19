<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Contract\ContractBuilderDraftService;
use App\Services\Contract\ContractBuilderInstanceService;
use App\Services\Contract\ContractLibraryService;
use App\Services\Contract\ContractOrganizationViewService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ContractBuilderDraftTest extends TestCase
{
    use \Tests\Support\EnablesImmutableAuditWriter;
    public function test_draft_http_validates_version_and_returns_private_typed_maps(): void
    {
        [$actor, $other, $contract, $variableId, , $revision] = $this->fixture();
        $this->withoutMiddleware();
        $this->actingAs($actor, 'api_admin');
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Routing\Events\RouteMatched::class, static function (\Illuminate\Routing\Events\RouteMatched $event): void {
            $event->request->attributes->set('current_organization_id', $event->request->user()?->current_organization_id);
        });
        $url = '/api/v1/admin/contracts/'.$contract->id.'/builder/draft';
        $this->getJson($url)->assertOk()->assertJsonPath('data', null);
        $payload = ['base_revision' => 1, 'document' => $revision['document'], 'values' => [$variableId => 'Локально'], 'request_key' => 'http'];
        $this->putJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $response = $this->putJson($url, [...$payload, 'expected_version' => 0])->assertOk()->assertJsonPath('data.version', 1);
        $data = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR)->data;
        self::assertIsObject($data->values);
        self::assertIsObject($data->definitions);
        self::assertIsObject($data->blocks);
        self::assertFalse(property_exists($data, 'request_key'));
        $this->getJson($url.'/preview')->assertOk()->assertJsonPath('data.draft.version', 1);
        $this->actingAs($other, 'api_admin');
        $this->getJson($url)->assertOk()->assertJsonPath('data', null);
        $route = \Illuminate\Support\Facades\Route::getRoutes()->match(\Illuminate\Http\Request::create($url, 'PUT'));
        self::assertContains('authorize:contracts.edit', $route->gatherMiddleware());
        self::assertSame(1, DB::table('contract_builder_revisions')->count());
    }

    public function test_private_drafts_preserve_shared_revision_and_library_with_safe_retries(): void
    {
        [$actor, $other, $contract, $variableId, $templateId, $original] = $this->fixture();
        $service = app(ContractBuilderDraftService::class);
        self::assertNull($service->read($actor, $actor->current_organization_id, $contract->id));
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Локальные условия: '], ['type' => 'variable', 'attrs' => ['variableId' => $variableId]],
        ]]]];
        $first = $service->save($actor, $actor->current_organization_id, $contract->id, 1, 0, $document, [$variableId => 'Первый черновик'], 'first');
        self::assertSame(1, $first['version']);
        self::assertEquals($first, $service->save($actor, $actor->current_organization_id, $contract->id, 1, 0, $document, [$variableId => 'Первый черновик'], 'first'));
        self::assertNull($service->read($other, $other->current_organization_id, $contract->id));
        self::assertSame($original, app(ContractBuilderInstanceService::class)->read($other, $other->current_organization_id, $contract->id, 1));
        self::assertStringContainsString('Первый черновик', $service->preview($actor, $actor->current_organization_id, $contract->id)['html']);
        $second = $service->save($actor, $actor->current_organization_id, $contract->id, 1, 1, $document, [$variableId => 'Второй черновик'], 'second');
        self::assertSame(2, $second['version']);
        self::assertEquals($first, $service->save($actor, $actor->current_organization_id, $contract->id, 1, 0, $document, [$variableId => 'Первый черновик'], 'first'));
        self::assertEquals($second, $service->read($actor, $actor->current_organization_id, $contract->id));
        foreach ([[0, 'stale'], [2, 'first']] as [$expectedVersion, $key]) {
            try {
                $service->save($actor, $actor->current_organization_id, $contract->id, 1, $expectedVersion, $document, [$variableId => 'Перезапись'], $key);
                self::fail('Conflicting draft write was accepted');
            } catch (\App\Exceptions\ContractBuilderException $exception) {
                self::assertSame(409, $exception->getCode());
            }
        }
        $otherDraft = $service->save($other, $other->current_organization_id, $contract->id, 1, 0, $document, [$variableId => 'Черновик второй стороны'], 'first');
        self::assertNotSame($first['id'], $otherDraft['id']);
        self::assertEquals($second, $service->read($actor, $actor->current_organization_id, $contract->id));
        self::assertSame(1, DB::table('contract_builder_revisions')->count());
        self::assertSame(3, DB::table('contract_builder_draft_operations')->count());
        $template = app(ContractLibraryService::class)->read($actor, $actor->current_organization_id, $templateId, 1);
        self::assertSame($original['document'], $template['version']['content']['document']);
        self::assertSame($original, app(ContractBuilderInstanceService::class)->read($actor, $actor->current_organization_id, $contract->id, 1));
    }

    public function test_draft_rejects_foreign_organization_invalid_values_and_changed_contract_status(): void
    {
        [$actor, $other, $contract, $variableId, , $revision] = $this->fixture();
        $service = app(ContractBuilderDraftService::class);
        try {
            $service->save($actor, $other->current_organization_id, $contract->id, 1, 0, $revision['document'], [$variableId => 'Wrong organization'], 'impersonate');
            self::fail('Organization impersonation was accepted');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame(0, DB::table('contract_builder_drafts')->count());
        }
        try {
            $service->save($actor, $actor->current_organization_id, $contract->id, 1, 0, $revision['document'], [$variableId => ['not text']], 'invalid');
            self::fail('Invalid value was stored');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame(0, DB::table('contract_builder_drafts')->count());
        }
        $contract->update(['status' => 'active']);
        try {
            $service->save($actor, $actor->current_organization_id, $contract->id, 1, 0, $revision['document'], [$variableId => 'New terms'], 'active');
            self::fail('Active contract draft was accepted');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertSame(0, DB::table('contract_builder_drafts')->count());
        }
    }

    public function test_entity_labels_stay_frozen_across_source_rename_and_private_draft_edits(): void
    {
        [$actor, $other, $contract, $variableId, , $revision] = $this->fixture(true);
        $key = 'project:'.$contract->project_id;
        $oldLabel = $revision['entity_snapshots'][$key]['label'];
        $contract->project->update(['name' => 'Изменённый источник']);
        $instances = app(ContractBuilderInstanceService::class);
        self::assertSame($revision, $instances->read($other, $other->current_organization_id, $contract->id, 1));
        self::assertStringContainsString(htmlspecialchars($oldLabel, ENT_QUOTES, 'UTF-8'), $instances->preview($other, $other->current_organization_id, $contract->id, 1)['html']);
        $drafts = app(ContractBuilderDraftService::class);
        $draft = $drafts->save($other, $other->current_organization_id, $contract->id, 1, 0, $revision['document'], $revision['values'], 'entity-draft');
        self::assertSame($revision['entity_snapshots'], $draft['entity_snapshots']);
        self::assertStringNotContainsString('Изменённый источник', $drafts->preview($other, $other->current_organization_id, $contract->id)['html']);
        $foreign = Project::factory()->create();
        try {
            $drafts->save($other, $other->current_organization_id, $contract->id, 1, 1, $revision['document'], [$variableId => ['type' => 'project', 'id' => $foreign->id]], 'foreign-reference');
            self::fail('New foreign references must be rejected');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        self::assertSame(1, $drafts->read($other, $other->current_organization_id, $contract->id)['version']);
    }

    public function test_entity_catalog_scopes_search_permissions_and_batches_repeated_references(): void
    {
        [$actor, $other, $contract] = $this->fixture();
        $catalog = app(\App\Services\Contract\ContractEntityCatalog::class);
        $organizationId = (int) $actor->current_organization_id;
        $contract->project->update(['name' => 'Каталог % один']);
        $foreign = Project::factory()->create(['name' => 'Каталог % чужой']);
        self::assertSame([$contract->project_id], array_column($catalog->search($actor, $organizationId, 'project', 'Каталог %'), 'id'));
        $foreign->organizations()->attach($organizationId, ['role' => 'contractor', 'is_active' => true]);
        self::assertCount(2, $catalog->search($actor, $organizationId, 'project', 'Каталог %'));
        $foreign->organizations()->updateExistingPivot($organizationId, ['is_active' => false]);
        self::assertCount(1, $catalog->search($actor, $organizationId, 'project', 'Каталог %'));
        $definitions = $values = [];
        for ($index = 0; $index < 500; $index++) {
            $definitions['field'.$index] = ['type' => 'entity', 'entity_type' => 'project'];
            $values['field'.$index] = ['type' => 'project', 'id' => (int) $contract->project_id];
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $snapshots = $catalog->snapshots($actor, $organizationId, $definitions, $values);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertCount(1, $snapshots);
        self::assertCount(1, array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from "projects"')));
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(false);
        $restricted = new \App\Services\Contract\ContractEntityCatalog($authorization,
            app(\App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface::class), app(\App\Services\Contract\ContractAccessService::class));
        foreach (['project', 'contract', 'organization', 'counterparty', 'estimate'] as $type) {
            try {
                $restricted->search($actor, $organizationId, $type, '');
                self::fail('Source permission is required');
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                self::assertTrue(true);
            }
        }
        try {
            $catalog->search($other, $organizationId, 'project', '');
            self::fail('Organization impersonation must be rejected');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertTrue(true);
        }
    }

    public function test_entity_search_http_validates_type_and_never_exposes_foreign_projects(): void
    {
        [$actor, , $contract] = $this->fixture();
        $this->withoutMiddleware();
        $this->actingAs($actor, 'api_admin');
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Routing\Events\RouteMatched::class, static function (\Illuminate\Routing\Events\RouteMatched $event): void {
            $event->request->attributes->set('current_organization_id', $event->request->user()?->current_organization_id);
        });
        $foreign = Project::factory()->create();
        $response = $this->getJson('/api/v1/admin/contract-library/entities?type=project');
        $response->assertOk()->assertJsonPath('success', true);
        self::assertContains($contract->project_id, array_column($response->json('data'), 'id'));
        self::assertNotContains($foreign->id, array_column($response->json('data'), 'id'));
        self::assertSame(['id', 'label'], array_keys($response->json('data.0')));
        $this->getJson('/api/v1/admin/contract-library/entities?type=users')->assertUnprocessable();
    }

    public function test_batched_contractor_catalog_preserves_holding_access_and_excludes_foreign_self_execution(): void
    {
        [$actor] = $this->fixture();
        $head = Organization::factory()->create();
        Organization::findOrFail($actor->current_organization_id)->update(['parent_organization_id' => $head->id]);
        $shared = \App\Models\Contractor::create(['organization_id' => $head->id, 'name' => 'Общий подрядчик', 'contractor_type' => 'manual']);
        $self = \App\Models\Contractor::create(['organization_id' => $head->id, 'name' => 'Собственные силы', 'contractor_type' => 'self_execution']);
        $unrelated = \App\Models\Contractor::create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Чужой подрядчик', 'contractor_type' => 'manual']);
        $sharing = app(\App\BusinessModules\Core\MultiOrganization\Services\HierarchicalContractorSharing::class);
        foreach ([$shared, $self, $unrelated] as $contractor) {
            self::assertSame($sharing->canUseContractor($contractor->id, $actor->current_organization_id),
                $sharing->availableQuery($actor->current_organization_id)->whereKey($contractor->id)->exists());
        }
        self::assertTrue($sharing->availableQuery($actor->current_organization_id)->whereKey($shared->id)->exists());
        self::assertFalse($sharing->availableQuery($actor->current_organization_id)->whereKey($self->id)->exists());
        $single = app(\App\BusinessModules\Core\MultiOrganization\Services\SingleContractorAccess::class);
        self::assertFalse($single->availableQuery($actor->current_organization_id)->whereKey($shared->id)->exists());
    }

    public function test_explicit_source_refresh_compares_changes_rejects_stale_preview_and_preserves_common_revision(): void
    {
        [$actor, , $contract, , , $revision] = $this->fixture(true);
        $organizationId = (int) $actor->current_organization_id;
        $service = app(ContractBuilderDraftService::class);
        $draft = $service->save($actor, $organizationId, $contract->id, 1, 0, $revision['document'], $revision['values'], 'before-refresh');
        self::assertSame([], $service->sourceChanges($actor, $organizationId, $contract->id)['changes']);
        $contract->project->update(['name' => 'Первое изменение']);
        $preview = $service->sourceChanges($actor, $organizationId, $contract->id);
        self::assertSame([['reference' => 'project:'.$contract->project_id,
            'before' => $revision['entity_snapshots']['project:'.$contract->project_id]['label'], 'after' => 'Первое изменение']], $preview['changes']);
        self::assertSame($draft, $service->read($actor, $organizationId, $contract->id));
        $contract->project->update(['name' => 'Второе изменение']);
        try {
            $service->save($actor, $organizationId, $contract->id, 1, 1, $revision['document'], $revision['values'], 'stale-refresh', $preview['source_hash']);
            self::fail('A changed source must require a new comparison');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        $preview = $service->sourceChanges($actor, $organizationId, $contract->id);
        $updated = $service->save($actor, $organizationId, $contract->id, 1, 1, $revision['document'], $revision['values'], 'refresh', $preview['source_hash']);
        self::assertSame(2, $updated['version']);
        self::assertSame('Второе изменение', $updated['entity_snapshots']['project:'.$contract->project_id]['label']);
        $contract->project->update(['name' => 'Третье изменение']);
        self::assertEquals($updated, $service->save($actor, $organizationId, $contract->id, 1, 1, $revision['document'], $revision['values'], 'refresh', $preview['source_hash']));
        self::assertSame($revision, app(ContractBuilderInstanceService::class)->read($actor, $organizationId, $contract->id, 1));
    }

    public function test_typed_source_values_and_dependent_formulas_refresh_only_explicitly(): void
    {
        [$actor, , $contract, $entityId, , $revision] = $this->fixture(true, true);
        $organizationId = (int) $actor->current_organization_id;
        $sourceId = array_values(array_filter($revision['definitions'], static fn (array $field): bool => ($field['definition']['source']['kind'] ?? null) === 'entity_field'))[0]['id'];
        $formulaId = array_values(array_filter($revision['definitions'], static fn (array $field): bool => ($field['definition']['source']['kind'] ?? null) === 'formula'))[0]['id'];
        self::assertSame($contract->project->name, $revision['values'][$sourceId]);
        self::assertSame($revision['values'][$sourceId], $revision['values'][$formulaId]);
        $input = [$entityId => $revision['values'][$entityId]];
        $service = app(ContractBuilderDraftService::class);
        $contract->project->update(['name' => 'Обновлённое значение источника']);
        $draft = $service->save($actor, $organizationId, $contract->id, 1, 0, $revision['document'], $input, 'source-draft');
        self::assertSame($revision['values'], $draft['values']);
        try {
            $service->save($actor, $organizationId, $contract->id, 1, 1, $revision['document'], [...$input, $sourceId => 'Подмена'], 'forged-source');
            self::fail('Source values must be server-owned');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        $preview = $service->sourceChanges($actor, $organizationId, $contract->id);
        self::assertSame('Обновлённое значение источника', array_column($preview['changes'], 'after', 'reference')[$sourceId]);
        $updated = $service->save($actor, $organizationId, $contract->id, 1, 1, $revision['document'], $input, 'typed-refresh', $preview['source_hash']);
        self::assertSame('Обновлённое значение источника', $updated['values'][$sourceId]);
        self::assertSame($updated['values'][$sourceId], $updated['values'][$formulaId]);
        self::assertSame($revision, app(ContractBuilderInstanceService::class)->read($actor, $organizationId, $contract->id, 1));
    }

    public function test_source_money_dates_and_private_field_rejection_use_server_types(): void
    {
        [$actor, , $contract] = $this->fixture();
        $contract->update(['currency' => 'USD', 'total_amount' => '123.45', 'start_date' => '2026-10-01']);
        $reference = (string) \Illuminate\Support\Str::uuid();
        $money = (string) \Illuminate\Support\Str::uuid();
        $date = (string) \Illuminate\Support\Str::uuid();
        $definitions = [
            $reference => ['type' => 'entity', 'entity_type' => 'contract'],
            $money => ['type' => 'money', 'source' => ['kind' => 'entity_field', 'variable_id' => $reference, 'entity_type' => 'contract', 'field' => 'total_amount']],
            $date => ['type' => 'date', 'source' => ['kind' => 'entity_field', 'variable_id' => $reference, 'entity_type' => 'contract', 'field' => 'start_date']],
        ];
        $catalog = app(\App\Services\Contract\ContractEntityCatalog::class);
        $input = [$reference => ['type' => 'contract', 'id' => $contract->id]];
        $values = $catalog->sourceValues($actor, $actor->current_organization_id, $definitions, $input);
        self::assertSame(['amount' => '123.45', 'currency' => 'USD'], $values[$money]);
        self::assertSame('2026-10-01', $values[$date]);
        foreach ([['type' => 'text', 'field' => 'notes'], ['type' => 'number', 'field' => 'total_amount']] as $invalid) {
            $definitions[$money]['type'] = $invalid['type'];
            $definitions[$money]['source']['field'] = $invalid['field'];
            try {
                $catalog->sourceValues($actor, $actor->current_organization_id, $definitions, $input);
                self::fail('Private or incompatible fields cannot be source bindings');
            } catch (\App\Exceptions\ContractBuilderException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
    }

    public function test_revision_export_keeps_organization_paths_and_recovers_storage_failure_without_new_revision(): void
    {
        [$actor, $other, $contract, , , $revision] = $this->fixture();
        $paths = [];
        $fail = true;
        $files = \Mockery::mock(\App\Services\Storage\FileService::class);
        $files->shouldReceive('putContent')->times(3)->andReturnUsing(static function (string $bytes, string $directory, string $filename, string $visibility, Organization $organization) use (&$paths, &$fail): string|false {
            self::assertStringStartsWith('%PDF-', $bytes);
            self::assertSame('private', $visibility);
            $path = 'org-'.$organization->id.'/'.$directory.'/'.$filename;
            $paths[] = $path;
            if ($fail) {
                $fail = false;
                return false;
            }
            return $path;
        });
        $files->shouldReceive('temporaryUrl')->twice()->andReturnUsing(static function (string $path, int $minutes, Organization $organization, array $headers): string {
            self::assertStringStartsWith('org-'.$organization->id.'/', $path);
            self::assertSame(5, $minutes);
            self::assertSame('application/pdf', $headers['ResponseContentType']);
            return 'https://files.example.test/'.$path;
        });
        $service = new \App\Services\Contract\ContractBuilderExportService(app(ContractBuilderInstanceService::class),
            new \App\Services\Contract\ContractDocumentExporter, $files);
        try {
            $service->export($actor, $actor->current_organization_id, $contract->id, 1, 'pdf');
            self::fail('Storage failure must be visible');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(503, $exception->getCode());
        }
        $first = $service->export($actor, $actor->current_organization_id, $contract->id, 1, 'pdf');
        $second = $service->export($other, $other->current_organization_id, $contract->id, 1, 'pdf');
        self::assertSame($paths[0], $paths[1]);
        self::assertNotSame($paths[1], $paths[2]);
        self::assertSame($revision['content_hash'], $first['content_hash']);
        self::assertSame($first['content_hash'], $second['content_hash']);
        self::assertSame(300, $second['expires_in']);
        self::assertSame(1, DB::table('contract_builder_revisions')->where('id', $revision['id'])->count());
        try {
            $service->export($actor, $other->current_organization_id, $contract->id, 1, 'pdf');
            self::fail('Impersonation must be rejected before export');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertTrue(true);
        }
    }

    public function test_organization_template_is_reused_as_contractor_on_another_owners_project(): void
    {
        [$actor, $other, $firstContract, $variableId, $templateId, $firstRevision] = $this->fixture();
        $project = Project::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $project->organizations()->attach($actor->current_organization_id, ['role' => 'contractor', 'is_active' => true]);
        $contract = Contract::create([
            'organization_id' => $actor->current_organization_id, 'project_id' => $project->id,
            'contractor_id' => $firstContract->contractor_id, 'contract_side_type' => 'subcontract',
            'number' => 'OTHER-PROJECT-1', 'date' => '2026-09-19', 'status' => 'draft', 'total_amount' => 100,
        ]);
        foreach ($firstContract->parties()->get() as $party) {
            $contract->parties()->create($party->only(['side', 'role', 'linked_organization_id', 'name', 'snapshot']));
        }
        app(ContractOrganizationViewService::class)->synchronizeNewContract($contract);
        $revision = app(ContractBuilderInstanceService::class)->create($actor, $actor->current_organization_id, $contract->id,
            $templateId, 1, [$variableId => 'Условия другого проекта'], 'second-project');
        self::assertSame($firstRevision['template_version_id'], $revision['template_version_id']);
        self::assertSame('Условия другого проекта', $revision['values'][$variableId]);
        self::assertSame($firstRevision, app(ContractBuilderInstanceService::class)->read($actor, $actor->current_organization_id, $firstContract->id, 1));
        self::assertSame($revision, app(ContractBuilderInstanceService::class)->read($other, $other->current_organization_id, $contract->id, 1));
        try {
            app(ContractLibraryService::class)->read($other, $other->current_organization_id, $templateId, 1);
            self::fail('A shared revision must not expose its authors library');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            self::assertTrue(true);
        }
    }

    public function test_proposal_freezes_draft_and_only_opposite_organization_can_accept_once(): void
    {
        [$actor, $other, $contract, $variableId, , $original] = $this->fixture();
        $drafts = app(ContractBuilderDraftService::class);
        $proposals = app(\App\Services\Contract\ContractProposalService::class);
        $drafts->save($actor, $actor->current_organization_id, $contract->id, 1, 0, $original['document'], [$variableId => 'Предложено'], 'draft');
        $proposal = $proposals->create($actor, $actor->current_organization_id, $contract->id, 1, 1, 'На рассмотрение', 'proposal');
        self::assertEquals($proposal, $proposals->create($actor, $actor->current_organization_id, $contract->id, 1, 1, 'На рассмотрение', 'proposal'));
        $drafts->save($actor, $actor->current_organization_id, $contract->id, 1, 1, $original['document'], [$variableId => 'Приватная новая правка'], 'later-draft');
        self::assertSame('Предложено', $proposals->show($other, $other->current_organization_id, $contract->id, $proposal['id'])['content']['values'][$variableId]);
        $colleague = User::factory()->create(['current_organization_id' => $actor->current_organization_id]);
        foreach ([$actor, $colleague] as $selfActor) {
            try {
                $proposals->decide($selfActor, $actor->current_organization_id, $contract->id, $proposal['id'], 1, 'accepted', '', 'self');
                self::fail('Author organization cannot accept its own proposal');
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                self::assertSame(1, DB::table('contract_builder_revisions')->count());
            }
        }
        $accepted = $proposals->decide($other, $other->current_organization_id, $contract->id, $proposal['id'], 1, 'accepted', 'Согласовано', 'accept');
        self::assertSame('accepted', $accepted['status']);
        self::assertEquals($accepted, $proposals->decide($other, $other->current_organization_id, $contract->id, $proposal['id'], 1, 'accepted', 'Согласовано', 'accept'));
        self::assertSame(2, DB::table('contract_builder_revisions')->count());
        $instances = app(ContractBuilderInstanceService::class);
        $revision = $instances->read($other, $other->current_organization_id, $contract->id, 2);
        self::assertSame('Предложено', $revision['values'][$variableId]);
        self::assertSame($original, $instances->read($actor, $actor->current_organization_id, $contract->id, 1));
        self::assertEquals($revision, $instances->read($actor, $actor->current_organization_id, $contract->id, 2));
        self::assertSame('Приватная новая правка', $drafts->read($actor, $actor->current_organization_id, $contract->id)['values'][$variableId]);
    }

    public function test_proposal_conflicts_require_new_basis_and_permission_is_separate(): void
    {
        [$actor, $other, $contract, $variableId, , $original] = $this->fixture();
        $drafts = app(ContractBuilderDraftService::class);
        $service = app(\App\Services\Contract\ContractProposalService::class);
        $drafts->save($actor, $actor->current_organization_id, $contract->id, 1, 0, $original['document'], [$variableId => 'Первое'], 'first');
        $first = $service->create($actor, $actor->current_organization_id, $contract->id, 1, 1, '', 'first');
        $drafts->save($other, $other->current_organization_id, $contract->id, 1, 0, $original['document'], [$variableId => 'Встречное'], 'counter');
        $counter = $service->create($other, $other->current_organization_id, $contract->id, 1, 1, '', 'counter');
        $denied = \Mockery::mock(AuthorizationService::class);
        $denied->shouldReceive('can')->with($other, 'contracts.revisions.accept', ['organization_id' => $other->current_organization_id])->andReturn(false);
        $restricted = new \App\Services\Contract\ContractProposalService($denied, app(ContractOrganizationViewService::class), $drafts);
        try {
            $restricted->decide($other, $other->current_organization_id, $contract->id, $first['id'], 1, 'accepted', '', 'denied');
            self::fail('Separate permission is required');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame(1, DB::table('contract_builder_revisions')->count());
        }
        $service->decide($other, $other->current_organization_id, $contract->id, $first['id'], 1, 'accepted', '', 'accept');
        $attempts = [
            fn () => $service->decide($actor, $actor->current_organization_id, $contract->id, $counter['id'], 1, 'accepted', '', 'stale'),
            fn () => $service->create($actor, $actor->current_organization_id, $contract->id, 1, 1, '', 'stale'),
            fn () => $service->create($actor, $actor->current_organization_id, $contract->id, 1, 1, 'Different payload', 'first'),
            fn () => $service->decide($other, $other->current_organization_id, $contract->id, $first['id'], 1, 'rejected', '', 'accept'),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('Conflicting write was accepted');
            } catch (\App\Exceptions\ContractBuilderException $exception) {
                self::assertSame(409, $exception->getCode());
            }
        }
        $rejected = $service->decide($actor, $actor->current_organization_id, $contract->id, $counter['id'], 1, 'rejected', 'Основание устарело', 'reject');
        self::assertSame('rejected', $rejected['status']);
        self::assertSame(2, DB::table('contract_builder_revisions')->count());
        foreach ([
            fn () => DB::table('contract_builder_proposals')->where('id', $first['id'])->update(['message' => 'Replacement']),
            fn () => DB::table('contract_builder_proposals')->where('id', $counter['id'])->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                self::fail('Proposal history must be immutable');
            } catch (\Illuminate\Database\QueryException $exception) {
                self::assertStringContainsString('contract_builder_proposal_immutable', $exception->getMessage());
            }
        }
    }

    public function test_proposal_http_scopes_paginates_and_rebases_without_overwriting_newer_draft(): void
    {
        [$actor, $other, $contract, $variableId, , $original] = $this->fixture();
        $drafts = app(ContractBuilderDraftService::class);
        $drafts->save($actor, $actor->current_organization_id, $contract->id, 1, 0, $original['document'], [$variableId => 'Предложение'], 'draft');
        $this->withoutMiddleware();
        $this->actingAs($actor, 'api_admin');
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Routing\Events\RouteMatched::class, static function (\Illuminate\Routing\Events\RouteMatched $event): void {
            $event->request->attributes->set('current_organization_id', $event->request->user()?->current_organization_id);
        });
        $url = '/api/v1/admin/contracts/'.$contract->id.'/builder/proposals';
        $this->postJson($url, ['base_revision' => 1])->assertUnprocessable();
        $first = null;
        for ($index = 0; $index < 21; $index++) {
            $response = $this->postJson($url, ['base_revision' => 1, 'draft_version' => 1, 'request_key' => 'proposal-'.$index])->assertOk();
            $first ??= $response->json('data');
        }
        $page = $this->getJson($url)->assertOk()->assertJsonCount(20, 'data.items')->assertJsonPath('data.can_decide', true);
        self::assertArrayNotHasKey('content', $page->json('data.items.0'));
        self::assertArrayNotHasKey('request_key', $page->json('data.items.0'));
        $this->getJson($url.'?before='.$page->json('data.next_cursor'))->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.next_cursor', null);
        $this->postJson($url.'/'.$first['id'].'/decision', ['expected_version' => 1, 'decision' => 'accepted', 'request_key' => 'self'])->assertForbidden();
        $this->actingAs($other, 'api_admin');
        $preview = $this->getJson($url.'/'.$first['id'].'/preview')->assertOk();
        self::assertStringContainsString('Предложение', $preview->json('data.html'));
        $content = json_decode($preview->getContent(), false, 512, JSON_THROW_ON_ERROR)->data->proposal->content;
        self::assertIsObject($content->blocks);
        self::assertIsObject($content->entity_snapshots);
        $this->postJson($url.'/'.$first['id'].'/decision', ['expected_version' => 1, 'decision' => 'accepted', 'request_key' => 'accept'])->assertOk()->assertJsonPath('data.status', 'accepted');
        $route = \Illuminate\Support\Facades\Route::getRoutes()->match(\Illuminate\Http\Request::create($url.'/'.$first['id'].'/decision', 'POST'));
        self::assertContains('authorize:contracts.revisions.accept', $route->gatherMiddleware());
        $rebased = $drafts->save($actor, $actor->current_organization_id, $contract->id, 2, 1, $original['document'], [$variableId => 'Новое основание'], 'rebase');
        self::assertSame(2, $rebased['base_revision']);
        self::assertSame(2, $rebased['version']);
        try {
            $drafts->save($actor, $actor->current_organization_id, $contract->id, 2, 1, $original['document'], [$variableId => 'Устаревшая вкладка'], 'old-tab');
            self::fail('An old tab must not overwrite a rebased draft');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        $outsider = User::factory()->create(['current_organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($outsider, 'api_admin');
        $this->getJson($url)->assertNotFound();
        $this->getJson($url.'/'.$first['id'])->assertNotFound();
        $translations = require base_path('lang/ru/permissions.php');
        self::assertStringContainsString('Принятие', $translations['values']['contracts.revisions.accept']);
    }

    public function test_revision_confirmation_is_specific_to_side_and_hash_and_new_revision_requires_both_again(): void
    {
        [$actor, $other, $contract, $variableId, , $original] = $this->fixture();
        $service = app(\App\Services\Contract\ContractRevisionConfirmationService::class);
        $first = $service->confirm($actor, $actor->current_organization_id, $contract->id, 1, $original['content_hash'], 'confirm');
        self::assertEquals($first, $service->confirm($actor, $actor->current_organization_id, $contract->id, 1, $original['content_hash'], 'confirm'));
        self::assertFalse($service->state($other, $other->current_organization_id, $contract->id, 1)['fully_confirmed']);
        $service->confirm($other, $other->current_organization_id, $contract->id, 1, $original['content_hash'], 'confirm');
        self::assertTrue($service->state($actor, $actor->current_organization_id, $contract->id, 1)['fully_confirmed']);
        self::assertFalse($service->state($actor, $actor->current_organization_id, $contract->id, 1)['can_confirm']);
        app(ContractBuilderDraftService::class)->save($actor, $actor->current_organization_id, $contract->id, 1, 0, $original['document'], [$variableId => 'Изменение'], 'change');
        $proposals = app(\App\Services\Contract\ContractProposalService::class);
        $proposal = $proposals->create($actor, $actor->current_organization_id, $contract->id, 1, 1, '', 'change');
        $proposals->decide($other, $other->current_organization_id, $contract->id, $proposal['id'], 1, 'accepted', '', 'accept');
        self::assertSame([], $service->state($actor, $actor->current_organization_id, $contract->id, 2)['items']);
        self::assertFalse($service->state($actor, $actor->current_organization_id, $contract->id, 2)['fully_confirmed']);
        self::assertTrue($service->state($actor, $actor->current_organization_id, $contract->id, 2)['can_confirm']);
        self::assertTrue($service->state($actor, $actor->current_organization_id, $contract->id, 1)['fully_confirmed']);
        foreach ([
            fn () => $service->confirm($actor, $actor->current_organization_id, $contract->id, 1, $original['content_hash'], 'stale-tab'),
            fn () => $service->confirm($actor, $actor->current_organization_id, $contract->id, 2, $original['content_hash'], 'wrong-hash'),
        ] as $attempt) {
            try { $attempt(); self::fail('Obsolete or wrong revision cannot be confirmed'); }
            catch (\App\Exceptions\ContractBuilderException $exception) { self::assertSame(409, $exception->getCode()); }
        }
        try {
            $service->confirm($actor, $other->current_organization_id, $contract->id, 2, $proposal['content_hash'], 'impersonate');
            self::fail('Cannot confirm another organization');
        } catch (\Illuminate\Auth\Access\AuthorizationException) { self::assertSame(2, DB::table('contract_revision_confirmations')->count()); }
        $this->withoutMiddleware();
        $this->actingAs($actor, 'api_admin');
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Routing\Events\RouteMatched::class, static function (\Illuminate\Routing\Events\RouteMatched $event): void {
            $event->request->attributes->set('current_organization_id', $event->request->user()?->current_organization_id);
        });
        $url = '/api/v1/admin/contracts/'.$contract->id.'/builder/revisions/2/confirmations';
        $this->getJson($url)->assertOk()->assertJsonPath('data.can_confirm', true)->assertJsonCount(0, 'data.items');
        $this->postJson($url, ['content_hash' => 'wrong', 'request_key' => 'bad'])->assertUnprocessable();
        $this->postJson($url, ['content_hash' => $proposal['content_hash'], 'request_key' => 'new'])->assertOk()->assertJsonPath('data.side', 'first');
        $this->getJson($url)->assertOk()->assertJsonPath('data.can_confirm', false)->assertJsonPath('data.fully_confirmed', false);
        $route = \Illuminate\Support\Facades\Route::getRoutes()->match(\Illuminate\Http\Request::create($url, 'POST'));
        self::assertContains('authorize:contracts.revisions.confirm', $route->gatherMiddleware());
        try {
            DB::transaction(fn () => DB::table('contract_revision_confirmations')->where('id', $first['id'])->delete());
            self::fail('Historical confirmations must remain');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('contract_revision_confirmation_immutable', $exception->getMessage());
        }
    }

    public function test_attachments_freeze_bytes_and_share_only_when_proposed_or_in_a_revision(): void
    {
        [$actor, $other, $contract, $variableId, , $original] = $this->fixture();
        $this->fakeBuilderStorage();
        $assets = app(\App\Services\Contract\ContractBuilderAssetService::class);
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('Приложение.txt', 'Состав работ зафиксирован');
        $asset = $assets->upload($actor, $actor->current_organization_id, $contract->id, $file, 'attachment', 'upload');
        self::assertEquals($asset, $assets->upload($actor, $actor->current_organization_id, $contract->id, $file, 'attachment', 'upload'));
        self::assertSame(hash('sha256', 'Состав работ зафиксирован'), $asset['sha256']);
        try {
            $assets->download($other, $other->current_organization_id, $contract->id, $asset['id']);
            self::fail('Private attachment is not shared yet');
        } catch (\Illuminate\Auth\Access\AuthorizationException) { self::assertTrue(true); }
        $drafts = app(ContractBuilderDraftService::class);
        $draft = $drafts->save($actor, $actor->current_organization_id, $contract->id, 1, 0, $original['document'], [$variableId => 'С приложением'], 'draft', null, [$asset['id']]);
        self::assertEquals([$asset], $draft['attachments']);
        $proposals = app(\App\Services\Contract\ContractProposalService::class);
        $proposal = $proposals->create($actor, $actor->current_organization_id, $contract->id, 1, 1, '', 'proposal');
        self::assertSame($asset, $assets->download($other, $other->current_organization_id, $contract->id, $asset['id'])['asset']);
        $drafts->save($actor, $actor->current_organization_id, $contract->id, 1, 1, $original['document'], [$variableId => 'Без приложения'], 'remove', null, []);
        $proposals->decide($other, $other->current_organization_id, $contract->id, $proposal['id'], 1, 'accepted', '', 'accept');
        $revision = app(ContractBuilderInstanceService::class)->read($other, $other->current_organization_id, $contract->id, 2);
        self::assertEquals([$asset], $revision['attachments']);
        self::assertSame([], $original['attachments']);
        $otherDraft = $drafts->save($other, $other->current_organization_id, $contract->id, 2, 0, $revision['document'], [$variableId => 'Встречная правка'], 'counter', null, [$asset['id']]);
        self::assertEquals([$asset], $otherDraft['attachments']);
        $privateAsset = $assets->upload($actor, $actor->current_organization_id, $contract->id, $file, 'attachment', 'private');
        try {
            $assets->attachments($other, $other->current_organization_id, $contract->id, [$privateAsset['id']], []);
            self::fail('Other organization cannot attach an unshared file');
        } catch (\App\Exceptions\ContractBuilderException $exception) { self::assertSame(422, $exception->getCode()); }
        $stored = DB::table('contract_builder_assets')->where('id', $asset['id'])->firstOrFail();
        self::assertSame('Состав работ зафиксирован', \Illuminate\Support\Facades\Storage::disk('s3')->get($stored->storage_path));
        try {
            DB::transaction(fn () => DB::table('contract_builder_assets')->where('id', $asset['id'])->update(['sha256' => str_repeat('0', 64)]));
            self::fail('File identity is immutable');
        } catch (\Illuminate\Database\QueryException $exception) { self::assertStringContainsString('contract_builder_asset_immutable', $exception->getMessage()); }
    }

    public function test_external_decision_and_confirmation_require_separate_authority_and_frozen_evidence(): void
    {
        [$actor, , $contract, $variableId, , $original] = $this->fixture(false, false, true);
        $this->fakeBuilderStorage();
        $organizationId = (int) $actor->current_organization_id;
        $assets = app(\App\Services\Contract\ContractBuilderAssetService::class);
        $evidence = $assets->upload($actor, $organizationId, $contract->id,
            \Illuminate\Http\UploadedFile::fake()->createWithContent('Подтверждение.txt', 'Письменное подтверждение внешней стороны'), 'evidence', 'evidence');
        $basis = ['basis' => 'Письмо № 17; полномочие сотрудника — доверенность № 4', 'asset_id' => $evidence['id']];
        app(ContractBuilderDraftService::class)->save($actor, $organizationId, $contract->id, 1, 0, $original['document'], [$variableId => 'Согласовано с внешней стороной'], 'draft');
        $proposals = app(\App\Services\Contract\ContractProposalService::class);
        $proposal = $proposals->create($actor, $organizationId, $contract->id, 1, 1, '', 'proposal');
        self::assertNull($proposal['recipient_organization_id']);
        try {
            $proposals->decide($actor, $organizationId, $contract->id, $proposal['id'], 1, 'accepted', '', 'self');
            self::fail('External status must not permit self-acceptance');
        } catch (\Illuminate\Auth\Access\AuthorizationException) { self::assertTrue(true); }
        $decision = $proposals->decide($actor, $organizationId, $contract->id, $proposal['id'], 1, 'accepted', '', 'external', $basis);
        self::assertSame('external', $decision['decision_kind']);
        self::assertEquals($evidence, $decision['decision_proof']);
        self::assertEquals($decision, $proposals->decide($actor, $organizationId, $contract->id, $proposal['id'], 1, 'accepted', '', 'external', $basis));
        $confirmations = app(\App\Services\Contract\ContractRevisionConfirmationService::class);
        $state = $confirmations->state($actor, $organizationId, $contract->id, 2);
        self::assertSame(['second'], $state['external_sides']);
        try {
            $confirmations->confirm($actor, $organizationId, $contract->id, 2, $proposal['content_hash'], 'wrong-side', [...$basis, 'side' => 'first']);
            self::fail('Connected side cannot be recorded as external');
        } catch (\Illuminate\Auth\Access\AuthorizationException) { self::assertTrue(true); }
        $confirmation = $confirmations->confirm($actor, $organizationId, $contract->id, 2, $proposal['content_hash'], 'external-confirm', [...$basis, 'side' => 'second']);
        self::assertEquals($evidence, $confirmation['proof']);
        self::assertSame($basis['basis'], $confirmation['basis']);
        self::assertNull($confirmation['party_organization_id']);
        $confirmations->confirm($actor, $organizationId, $contract->id, 2, $proposal['content_hash'], 'own-confirm');
        self::assertTrue($confirmations->state($actor, $organizationId, $contract->id, 2)['fully_confirmed']);
        $denied = \Mockery::mock(AuthorizationService::class);
        $denied->shouldReceive('can')->with($actor, 'contracts.revisions.record_external', ['organization_id' => $organizationId])->andReturn(false);
        $restricted = new \App\Services\Contract\ContractRevisionConfirmationService($denied, app(ContractOrganizationViewService::class));
        try {
            $restricted->confirm($actor, $organizationId, $contract->id, 2, $proposal['content_hash'], 'denied', [...$basis, 'side' => 'second']);
            self::fail('External recording needs its own permission');
        } catch (\Illuminate\Auth\Access\AuthorizationException) { self::assertTrue(true); }
    }

    public function test_external_confirmation_http_and_file_upload_preserve_evidence_and_scope(): void
    {
        [$actor, , $contract, , , $revision] = $this->fixture(false, false, true);
        $this->fakeBuilderStorage();
        $this->withoutMiddleware();
        $this->actingAs($actor, 'api_admin');
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Routing\Events\RouteMatched::class, static function (\Illuminate\Routing\Events\RouteMatched $event): void {
            $event->request->attributes->set('current_organization_id', $event->request->user()?->current_organization_id);
        });
        $url = '/api/v1/admin/contracts/'.$contract->id.'/builder';
        $this->postJson($url.'/assets', ['kind' => 'evidence', 'request_key' => 'missing'])->assertUnprocessable();
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('Письмо.txt', 'Согласовано редакция 1');
        $asset = $this->post($url.'/assets', ['kind' => 'evidence', 'request_key' => 'upload', 'file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.kind', 'evidence')->json('data');
        self::assertArrayNotHasKey('storage_path', $asset);
        $payload = ['content_hash' => $revision['content_hash'], 'side' => 'second', 'basis' => 'Письмо № 5 и доверенность № 2', 'request_key' => 'external'];
        $this->postJson($url.'/revisions/1/external-confirmation', $payload)->assertUnprocessable()->assertJsonValidationErrors('asset_id');
        $this->postJson($url.'/revisions/1/external-confirmation', [...$payload, 'asset_id' => $asset['id']])->assertOk()->assertJsonPath('data.proof.sha256', $asset['sha256']);
        $this->getJson($url.'/assets/'.$asset['id'].'/download')->assertOk()->assertJsonPath('data.expires_in', 300);
        $otherContract = Contract::create(['organization_id' => $actor->current_organization_id, 'project_id' => $contract->project_id,
            'contractor_id' => $contract->contractor_id, 'number' => 'FOREIGN-ASSET', 'date' => '2026-09-19', 'status' => 'draft', 'total_amount' => 1]);
        foreach ($contract->parties()->get() as $party) {
            $otherContract->parties()->create($party->only(['side', 'role', 'linked_organization_id', 'name', 'snapshot']));
        }
        app(ContractOrganizationViewService::class)->synchronizeNewContract($otherContract);
        $this->getJson('/api/v1/admin/contracts/'.$otherContract->id.'/builder/assets/'.$asset['id'].'/download')->assertNotFound();
        $route = \Illuminate\Support\Facades\Route::getRoutes()->match(\Illuminate\Http\Request::create($url.'/revisions/1/external-confirmation', 'POST'));
        self::assertContains('authorize:contracts.revisions.record_external', $route->gatherMiddleware());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_concurrent_proposal_acceptance_serializes_on_contract_and_rejects_stale_basis(): void
    {
        [$actor, $other, $contract, $variableId, , $original] = $this->fixture();
        app(ContractBuilderDraftService::class)->save($actor, $actor->current_organization_id, $contract->id, 1, 0, $original['document'], [$variableId => 'Правки'], 'draft');
        $service = app(\App\Services\Contract\ContractProposalService::class);
        $first = $service->create($actor, $actor->current_organization_id, $contract->id, 1, 1, '', 'first');
        $second = $service->create($actor, $actor->current_organization_id, $contract->id, 1, 1, '', 'second');
        DB::commit();
        $raceName = 'contract-proposal-'.bin2hex(random_bytes(5));
        $connection = (array) config('database.connections.'.config('database.default'));
        $environment = array_merge(is_array(getenv()) ? getenv() : [], [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'LOG_CHANNEL' => 'stderr',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'], 'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'], 'MOST_RACE_NAME' => $raceName,
        ]);
        $workers = [];
        DB::beginTransaction();
        try {
            Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            foreach ([$first, $second] as $proposal) {
                $worker = new \Symfony\Component\Process\Process([PHP_BINARY, base_path('tests/Support/Contract/accept_proposal_worker.php'),
                    (string) $other->id, (string) $contract->id, (string) $proposal['id']], base_path(), $environment, timeout: 35);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $locked = DB::table('pg_stat_activity')->where('application_name', $raceName)->where('wait_event_type', 'Lock')->count();
                if ($locked === 2) { break; }
                foreach ($workers as $worker) {
                    if (!$worker->isRunning()) { self::fail($worker->getErrorOutput().$worker->getOutput()); }
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $locked);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['success']));
            $rejected = array_values(array_filter($results, static fn (array $result): bool => !$result['success']));
            self::assertSame(409, $rejected[0]['code']);
            self::assertSame(2, DB::table('contract_builder_revisions')->where('instance_id', DB::table('contract_builder_instances')->where('contract_id', $contract->id)->value('id'))->count());
        } finally {
            if (DB::transactionLevel() > 0) { DB::rollBack(); }
            foreach ($workers as $worker) { if ($worker->isRunning()) { $worker->stop(); } }
            DB::beginTransaction();
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_concurrent_activation_scheduling_allows_only_one_pending_activation(): void
    {
        [$actor, $other, $contract, , , $revision] = $this->fixture(application: true);
        $confirmations = app(\App\Services\Contract\ContractRevisionConfirmationService::class);
        foreach ([$actor, $other] as $party) {
            $confirmations->confirm($party, $party->current_organization_id, $contract->id, 1, $revision['content_hash'], 'race-confirm');
        }
        DB::commit();
        $raceName = 'contract-activation-'.bin2hex(random_bytes(5));
        $connection = (array) config('database.connections.'.config('database.default'));
        $environment = array_merge(is_array(getenv()) ? getenv() : [], [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'LOG_CHANNEL' => 'stderr',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'], 'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'], 'MOST_RACE_NAME' => $raceName,
        ]);
        $workers = [];
        DB::beginTransaction();
        try {
            Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            foreach (['first', 'second'] as $key) {
                $worker = new \Symfony\Component\Process\Process([PHP_BINARY, base_path('tests/Support/Contract/schedule_activation_worker.php'),
                    (string) $actor->id, (string) $contract->id, $revision['content_hash'], $key], base_path(), $environment, timeout: 35);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $locked = DB::table('pg_stat_activity')->where('application_name', $raceName)->where('wait_event_type', 'Lock')->count();
                if ($locked === 2) { break; }
                foreach ($workers as $worker) {
                    if (!$worker->isRunning()) { self::fail($worker->getErrorOutput().$worker->getOutput()); }
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $locked);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['success']));
            $rejected = array_values(array_filter($results, static fn (array $result): bool => !$result['success']));
            self::assertSame(409, $rejected[0]['code']);
            self::assertSame(1, DB::table('contract_revision_activations')->where('instance_id', DB::table('contract_builder_instances')->where('contract_id', $contract->id)->value('id'))->count());
        } finally {
            if (DB::transactionLevel() > 0) { DB::rollBack(); }
            foreach ($workers as $worker) { if ($worker->isRunning()) { $worker->stop(); } }
            DB::beginTransaction();
        }
    }

    public function test_legal_archive_binding_reuses_exact_pdf_after_failure_and_keeps_internal_workflows_separate(): void
    {
        [$actor, $other, $contract, , , $revision] = $this->fixture();
        $this->fakeBuilderStorage();
        $publisher = \Mockery::mock(\App\Services\Contract\ContractRevisionArchivePublisher::class);
        $payloads = [];
        $failed = false;
        $publisher->shouldReceive('publish')->times(3)->andReturnUsing(function (int $organizationId, int $actorId, array $data, \Illuminate\Http\UploadedFile $upload) use (&$payloads, &$failed) {
            $bytes = file_get_contents($upload->getRealPath());
            $payloads[] = ['organization_id' => $organizationId, 'actor_id' => $actorId, 'data' => $data, 'hash' => hash('sha256', $bytes)];
            self::assertStringStartsWith('%PDF-', $bytes);
            if (!$failed) { $failed = true; throw new \RuntimeException('Simulated archive outage'); }
            $document = \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument::create([
                'organization_id' => $organizationId, 'title' => $data['title'], 'document_type' => 'contract',
                'metadata' => $data['metadata'], 'created_by_user_id' => $actorId,
            ]);
            $file = \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile::create([
                'document_id' => $document->id, 'organization_id' => $organizationId, 'role' => 'primary', 'title' => 'Договор.pdf',
            ]);
            $version = \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion::create([
                'document_id' => $document->id, 'document_file_id' => $file->id, 'organization_id' => $organizationId,
                'version_number' => '1.0', 'is_current' => true, 'status' => 'uploaded', 'processing_status' => 'ready',
                'file_path' => 'org-'.$organizationId.'/legal-archive/test.pdf', 'original_filename' => $upload->getClientOriginalName(),
                'mime_type' => 'application/pdf', 'size_bytes' => strlen($bytes), 'content_hash' => hash('sha256', $bytes),
                'metadata' => $data['version_metadata'],
            ]);
            $file->update(['current_version_id' => $version->id]);
            $document->update(['current_primary_version_id' => $version->id]);
            return $document;
        });
        $access = \Mockery::mock(\App\Services\LegalArchive\Access\LegalDocumentAuthorizer::class);
        $access->shouldReceive('authorize')->withArgs(static fn ($user, $document, $ability): bool => $ability === 'view' && (int) $document->organization_id === (int) $user->current_organization_id)->andReturnNull();
        $this->app->instance(\App\Services\Contract\ContractRevisionArchivePublisher::class, $publisher);
        $this->app->instance(\App\Services\LegalArchive\Access\LegalDocumentAuthorizer::class, $access);
        $service = app(\App\Services\Contract\ContractRevisionLegalArchiveService::class);
        try {
            $service->prepare($actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash']);
            self::fail('Archive failure should be visible');
        } catch (\App\Exceptions\ContractBuilderException $exception) { self::assertSame(503, $exception->getCode()); }
        self::assertSame('failed', $service->state($actor, $actor->current_organization_id, $contract->id, 1)['binding']['status']);
        $state = $service->prepare($actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash']);
        self::assertEquals($payloads[0], $payloads[1]);
        self::assertSame('ready', $state['binding']['status']);
        self::assertSame($revision['content_hash'], $state['binding']['content_hash']);
        $snapshotParties = \App\BusinessModules\Features\LegalArchive\Models\LegalDocumentParty::where('document_version_id', $state['binding']['document_version_id'])->orderBy('id')->get();
        self::assertEquals($revision['parties'], $snapshotParties->pluck('snapshot')->all());
        self::assertSame('contract.subcontract', $payloads[1]['data']['type_profile_code']);
        self::assertEquals($state, $service->prepare($actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash']));
        self::assertNull($service->state($other, $other->current_organization_id, $contract->id, 1)['binding']);
        $otherState = $service->prepare($other, $other->current_organization_id, $contract->id, 1, $revision['content_hash']);
        self::assertNotSame($state['binding']['document_id'], $otherState['binding']['document_id']);
        self::assertSame($state['binding']['content_hash'], $otherState['binding']['content_hash']);
        $document = \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument::findOrFail($state['binding']['document_id']);
        $guard = new \App\Services\LegalArchive\Editor\LegalDocumentEditGuard(DB::connection());
        self::assertSame('legal_document_editing_frozen', $guard->mutationBlocker($document));
        $guard->assertWorkflowSubmissionAllowed($document);
        $guard->assertSignatureAllowed($document);
        self::assertSame(1, DB::table('contract_builder_revisions')->count());
    }

    public function test_activation_schedule_requires_both_confirmations_and_separate_right_and_preserves_future_conditions(): void
    {
        [$actor, $other, $contract, , , $revision] = $this->fixture();
        $service = app(\App\Services\Contract\ContractRevisionActivationService::class);
        $date = now()->addDays(10)->toDateString();
        $before = $contract->refresh()->getAttributes();
        try {
            app(\App\Services\Contract\ContractLifecycleService::class)->transition($contract, 'activate', $actor, 'Legacy request');
            self::fail('Legacy activation must not bypass confirmations');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertSame(trans_message('contracts.builder_revision_required'), $exception->getMessage());
        }
        try {
            $service->schedule($actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash'], null, $date, 'Согласованный договор', 'activation');
            self::fail('Both confirmations required');
        } catch (\App\Exceptions\ContractBuilderException $exception) { self::assertSame(409, $exception->getCode()); }
        $confirmations = app(\App\Services\Contract\ContractRevisionConfirmationService::class);
        foreach ([$actor, $other] as $party) {
            $confirmations->confirm($party, $party->current_organization_id, $contract->id, 1, $revision['content_hash'], 'confirm');
        }
        $activation = $service->schedule($actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash'], null, $date, 'Согласованный договор', 'activation');
        self::assertSame('scheduled', $activation['status']);
        self::assertSame($date, $activation['effective_date']);
        self::assertEquals($before, $contract->refresh()->getAttributes());
        self::assertNull(DB::table('contract_builder_instances')->value('effective_revision_id'));
        self::assertSame(0, $contract->payments()->count());
        self::assertSame(0, $contract->performanceActs()->count());
        self::assertEquals($activation, $service->schedule($actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash'], null, $date, 'Согласованный договор', 'activation'));
        foreach ([['changed-key', 'Другое основание'], ['activation', 'Другое основание']] as [$key, $basis]) {
            try {
                $service->schedule($actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash'], null, $date, $basis, $key);
                self::fail('Scheduled revision and key are immutable');
            } catch (\App\Exceptions\ContractBuilderException $exception) { self::assertSame(409, $exception->getCode()); }
        }
        $state = $service->state($other, $other->current_organization_id, $contract->id);
        self::assertFalse($state['can_activate']);
        self::assertFalse($state['can_retry']);
        self::assertSame($activation['id'], $state['items'][0]['id']);
        try {
            DB::transaction(static fn () => DB::table('contract_revision_activations')->where('id', $activation['id'])->update(['basis' => 'changed']));
            self::fail('Activation basis is immutable');
        } catch (\Illuminate\Database\QueryException $exception) { self::assertStringContainsString('contract_revision_activation_immutable', $exception->getMessage()); }
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(static fn ($user, string $permission): bool => $permission !== 'contracts.revisions.activate');
        $restricted = new \App\Services\Contract\ContractRevisionActivationService($authorization, app(ContractOrganizationViewService::class), app(ContractBuilderInstanceService::class), new \App\Services\Contract\ContractRevisionTermsCompiler);
        self::assertFalse($restricted->state($actor, $actor->current_organization_id, $contract->id)['can_retry']);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $restricted->schedule($actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash'], null, $date, 'Согласованный договор', 'activation');
    }

    public function test_revision_application_is_atomic_due_dated_retryable_and_amendment_preserves_facts_and_private_payment_plans(): void
    {
        $this->enableImmutableAuditWriter();
        [$actor, $other, $contract, $priceId, , $revision] = $this->fixture(application: true);
        app(\App\Services\Contract\ContractStateEventService::class)->createContractCreatedEvent($contract, null, $actor->id);
        $confirmations = app(\App\Services\Contract\ContractRevisionConfirmationService::class);
        foreach ([$actor, $other] as $party) {
            $confirmations->confirm($party, $party->current_organization_id, $contract->id, 1, $revision['content_hash'], 'confirm-first');
        }
        $activationService = app(\App\Services\Contract\ContractRevisionActivationService::class);
        $date = now()->addDays(10)->toDateString();
        $activation = $activationService->schedule($actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash'], null, $date, 'Договор подписан', 'activate-first');
        $applications = app(\App\Services\Contract\ContractRevisionApplicationService::class);
        self::assertSame('scheduled', $applications->applyDue($activation['id'])['status']);
        self::assertSame('100.00', $contract->refresh()->total_amount);
        self::assertSame(0, $contract->specifications()->count());
        $this->artisan('contracts:apply-due-revisions')->assertExitCode(0);
        self::assertNull(DB::table('contract_builder_instances')->value('effective_revision_id'));
        $this->travelTo(\Illuminate\Support\Carbon::parse($date)->startOfDay());
        $specifications = app(\App\Services\Contract\SpecificationService::class);
        $faulty = \Mockery::mock(\App\Services\Contract\SpecificationService::class);
        $faulty->shouldReceive('applyRevisionPlan')->once()->andReturnUsing(static function (...$args) use ($specifications): void {
            $specifications->applyRevisionPlan(...$args);
            throw new \RuntimeException('simulated module failure after write');
        });
        $failing = new \App\Services\Contract\ContractRevisionApplicationService($activationService, app(ContractOrganizationViewService::class), app(\App\Services\Contract\ContractAuditedMutationService::class), app(\App\Services\Contract\ContractStateEventService::class), $faulty);
        try {
            $failing->retry($actor, $actor->current_organization_id, $contract->id, $activation['id']);
            self::fail('Partial failure must be visible');
        } catch (\App\Exceptions\ContractBuilderException $exception) { self::assertSame(503, $exception->getCode()); }
        self::assertSame('100.00', $contract->refresh()->total_amount);
        self::assertSame(0, \App\Models\Specification::where('builder_revision_id', $revision['id'])->count());
        self::assertSame('failed', $activationService->state($actor, $actor->current_organization_id, $contract->id)['items'][0]['status']);
        $applied = $applications->retry($actor, $actor->current_organization_id, $contract->id, $activation['id']);
        self::assertSame('applied', $applied['status']);
        self::assertSame(2, $applied['attempts']);
        self::assertSame('120.00', $contract->refresh()->total_amount);
        self::assertSame('active', $contract->getRawOriginal('status'));
        self::assertSame(1, $contract->specifications()->count());
        self::assertSame('work-1', $contract->activeSpecification()->scope_items[0]['id']);
        self::assertSame(['works'], $contract->activeSpecification()->scope_items[0]['clause_ids']);
        self::assertSame(0, $contract->performanceActs()->count());
        self::assertSame(0, $contract->payments()->count());
        self::assertSame(0, $contract->completedWorks()->count());
        self::assertEquals($applied, $applications->retry($other, $other->current_organization_id, $contract->id, $activation['id']));
        $plans = app(\App\BusinessModules\Core\Payments\Services\ContractRevisionPaymentPlanService::class);
        self::assertSame([], $plans->state($actor, $actor->current_organization_id, $contract->id)['items']);
        $plan = $plans->apply($actor, $actor->current_organization_id, $contract->id, $activation['id']);
        self::assertSame('120.00', $plan['conditions']['terms']['total_amount']);
        self::assertNull($plan['conditions']['due_date']);
        self::assertSame(['price'], $plan['bases']['price']['clause_ids']);
        self::assertEquals($plan, $plans->apply($actor, $actor->current_organization_id, $contract->id, $activation['id']));
        self::assertSame([], $plans->state($other, $other->current_organization_id, $contract->id)['items']);
        $otherPlan = $plans->apply($other, $other->current_organization_id, $contract->id, $activation['id']);
        self::assertNotSame($plan['id'], $otherPlan['id']);
        self::assertEquals($plan['conditions'], $otherPlan['conditions']);
        $act = $contract->performanceActs()->create(['project_id' => $contract->project_id, 'act_document_number' => 'EXECUTED', 'act_date' => $date,
            'amount' => '30.00', 'currency' => 'RUB', 'status' => 'approved', 'is_approved' => true, 'created_by_user_id' => $actor->id, 'approval_date' => $date]);
        $fact = $act->refresh()->getAttributes();
        $paid = $this->paymentFact($actor, $contract, '30.00');
        $paidSnapshot = $paid->refresh()->getAttributes();
        $values = $revision['values'];
        $values[$priceId] = ['amount' => '200', 'currency' => 'RUB'];
        app(ContractBuilderDraftService::class)->save($actor, $actor->current_organization_id, $contract->id, 1, 0, $revision['document'], $values, 'amendment-draft');
        $proposals = app(\App\Services\Contract\ContractProposalService::class);
        $proposal = $proposals->create($actor, $actor->current_organization_id, $contract->id, 1, 1, 'Изменение цены', 'amendment-proposal');
        $proposals->decide($other, $other->current_organization_id, $contract->id, $proposal['id'], 1, 'accepted', '', 'amendment-accept');
        $next = app(ContractBuilderInstanceService::class)->read($actor, $actor->current_organization_id, $contract->id, 2);
        self::assertSame('120.00', $contract->refresh()->total_amount);
        foreach ([$actor, $other] as $party) {
            $confirmations->confirm($party, $party->current_organization_id, $contract->id, 2, $next['content_hash'], 'confirm-amendment');
        }
        $nextActivation = $activationService->schedule($other, $other->current_organization_id, $contract->id, 2, $next['content_hash'], (int) $revision['id'], $date, 'Допсоглашение №1', 'activate-amendment');
        $this->artisan('contracts:apply-due-revisions')->assertExitCode(0);
        self::assertSame('200.00', $contract->refresh()->total_amount);
        self::assertEquals($fact, $act->refresh()->getAttributes());
        self::assertEquals($paidSnapshot, $paid->refresh()->getAttributes());
        self::assertSame(2, $contract->specifications()->count());
        self::assertSame(1, $contract->specifications()->wherePivot('is_active', true)->count());
        self::assertSame(1, $contract->payments()->count());
        $newPlan = $plans->apply($actor, $actor->current_organization_id, $contract->id, $nextActivation['id']);
        self::assertSame('200.00', $newPlan['conditions']['terms']['total_amount']);
        self::assertFalse($plans->state($actor, $actor->current_organization_id, $contract->id)['items'][1]['is_current']);
        self::assertCount(1, $plans->state($other, $other->current_organization_id, $contract->id)['items']);
        self::assertEquals($revision, app(ContractBuilderInstanceService::class)->read($actor, $actor->current_organization_id, $contract->id, 1));
        $this->travelBack();
    }

    public function test_activation_http_keeps_typed_maps_exact_revision_permission_and_contract_scope(): void
    {
        $this->enableImmutableAuditWriter();
        [$actor, $other, $contract, , , $revision] = $this->fixture(application: true);
        $this->withoutMiddleware();
        $this->actingAs($actor, 'api_admin');
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Routing\Events\RouteMatched::class, static function (\Illuminate\Routing\Events\RouteMatched $event): void {
            $event->request->attributes->set('current_organization_id', $event->request->user()?->current_organization_id);
        });
        $url = '/api/v1/admin/contracts/'.$contract->id.'/builder';
        $this->getJson($url.'/revisions/1/activation-preview')->assertOk()->assertJsonPath('data.plan.terms.total_amount', '120.00');
        $payload = ['content_hash' => $revision['content_hash'], 'effective_date' => now()->toDateString(), 'basis' => 'Подписан', 'request_key' => 'http'];
        $this->postJson($url.'/revisions/1/activate', $payload)->assertUnprocessable()->assertJsonValidationErrors('previous_revision_id');
        $payload['previous_revision_id'] = null;
        $this->postJson($url.'/revisions/1/activate', $payload)->assertConflict();
        $confirmations = app(\App\Services\Contract\ContractRevisionConfirmationService::class);
        foreach ([$actor, $other] as $party) {
            $confirmations->confirm($party, $party->current_organization_id, $contract->id, 1, $revision['content_hash'], 'confirm-http');
        }
        $this->getJson($url.'/activations')->assertOk()->assertJsonPath('data.can_activate', true);
        $response = $this->postJson($url.'/revisions/1/activate', $payload)->assertOk()->assertJsonPath('data.status', 'applied');
        $activationId = $response->json('data.id');
        $object = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR)->data;
        self::assertInstanceOf(\stdClass::class, $object->plan->terms);
        self::assertInstanceOf(\stdClass::class, $object->plan->bases);
        $this->postJson($url.'/revisions/1/activate', $payload)->assertOk()->assertJsonPath('data.id', $activationId);
        $this->getJson($url.'/activations/'.$activationId)->assertOk();
        self::assertArrayNotHasKey('plan', $this->getJson($url.'/activations')->assertOk()->json('data.items.0'));
        $this->getJson($url.'/payment-plans')->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson($url.'/activations/'.$activationId.'/payment-plan')->assertOk()->assertJsonPath('data.conditions.terms.total_amount', '120.00');
        $this->actingAs($other, 'api_admin');
        $this->getJson($url.'/payment-plans')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/v1/admin/contracts/99999999/builder/activations/'.$activationId)->assertNotFound();
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(static fn ($user, string $permission): bool => !in_array($permission, ['contracts.revisions.activate', 'payments.schedule.create'], true));
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->postJson($url.'/activations/'.$activationId.'/retry')->assertForbidden();
        $this->postJson($url.'/activations/'.$activationId.'/payment-plan')->assertForbidden();
        self::assertSame('Ввод редакции договора в действие и повтор применения её условий', trans('permissions.values')['contracts.revisions.activate']);
    }

    public function test_activation_rejects_reduction_below_paid_history_without_touching_the_fact(): void
    {
        $this->enableImmutableAuditWriter();
        [$actor, $other, $contract, , , $revision] = $this->fixture(application: true);
        $payment = $this->paymentFact($other, $contract, '130.00');
        $before = $payment->refresh()->getAttributes();
        $confirmations = app(\App\Services\Contract\ContractRevisionConfirmationService::class);
        foreach ([$actor, $other] as $party) {
            $confirmations->confirm($party, $party->current_organization_id, $contract->id, 1, $revision['content_hash'], 'paid-confirm');
        }
        $service = app(\App\Services\Contract\ContractRevisionActivationService::class);
        $activation = $service->schedule($actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash'], null, now()->toDateString(), 'Подписан', 'paid');
        try {
            app(\App\Services\Contract\ContractRevisionApplicationService::class)->applyDue($activation['id']);
            self::fail('Paid history must be preserved');
        } catch (\App\Exceptions\ContractBuilderException $exception) { self::assertSame(409, $exception->getCode()); }
        $failed = $service->show($actor, $actor->current_organization_id, $contract->id, $activation['id']);
        self::assertSame('failed', $failed['status']);
        self::assertSame('contracts.revision_history_incompatible', $failed['last_error']);
        self::assertSame(trans_message('contracts.revision_history_incompatible'), $failed['error_message']);
        self::assertEquals($before, $payment->refresh()->getAttributes());
        self::assertSame('100.00', $contract->refresh()->total_amount);
        self::assertSame(0, $contract->specifications()->count());
        self::assertSame('cancelled', $service->cancel($actor, $actor->current_organization_id, $contract->id, $activation['id'], 'Требуется новая редакция', 'cancel-paid')['status']);
    }

    public function test_activation_can_be_cancelled_and_rescheduled_without_changing_effective_conditions(): void
    {
        $this->enableImmutableAuditWriter();
        [$actor, $other, $contract, , , $revision] = $this->fixture(application: true);
        $confirmations = app(\App\Services\Contract\ContractRevisionConfirmationService::class);
        foreach ([$actor, $other] as $party) {
            $confirmations->confirm($party, $party->current_organization_id, $contract->id, 1, $revision['content_hash'], 'confirm-cancel');
        }
        $service = app(\App\Services\Contract\ContractRevisionActivationService::class);
        $applications = app(\App\Services\Contract\ContractRevisionApplicationService::class);
        $activation = $service->schedule($actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash'], null, now()->addDay()->toDateString(), 'Подписан', 'future');
        $cancelled = $service->cancel($other, $other->current_organization_id, $contract->id, $activation['id'], 'Ошибка даты', 'cancel');
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('Ошибка даты', $cancelled['cancellation_basis']);
        self::assertEquals($cancelled, $service->cancel($other, $other->current_organization_id, $contract->id, $activation['id'], 'Ошибка даты', 'cancel'));
        self::assertEquals($cancelled, $applications->applyDue($activation['id']));
        self::assertSame('100.00', $contract->refresh()->total_amount);
        self::assertSame(0, $contract->specifications()->count());
        $next = $service->schedule($actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash'], null, now()->toDateString(), 'Исправлена дата', 'now');
        self::assertNotSame($activation['id'], $next['id']);
        self::assertSame('applied', $applications->applyDue($next['id'])['status']);
        self::assertSame('120.00', $contract->refresh()->total_amount);
        $events = DB::table('contract_state_events')->where('contract_id', $contract->id);
        self::assertSame('120.00', number_format((float) $events->sum('amount_delta'), 2, '.', ''));
        try {
            $service->cancel($actor, $actor->current_organization_id, $contract->id, $next['id'], 'Поздно', 'late');
            self::fail('Applied revision cannot be cancelled');
        } catch (\App\Exceptions\ContractBuilderException $exception) { self::assertSame(409, $exception->getCode()); }
        try {
            DB::transaction(static fn () => DB::table('contract_revision_activations')->where('id', $activation['id'])->update(['cancellation_basis' => 'changed']));
            self::fail('Cancelled activation is immutable');
        } catch (\Illuminate\Database\QueryException $exception) { self::assertStringContainsString('contract_revision_activation_immutable', $exception->getMessage()); }
    }

    public function test_legacy_mutations_cannot_change_builder_conditions_or_activate_from_execution(): void
    {
        $this->enableImmutableAuditWriter();
        [$actor, , $contract] = $this->fixture(application: true);
        $mutations = app(\App\Services\Contract\ContractAuditedMutationService::class);
        foreach ([['total_amount' => 900], ['currency' => 'USD'], ['status' => 'active'], ['start_date' => '2026-10-01']] as $attributes) {
            try {
                $mutations->update($contract->fresh(), $attributes, 'legacy_update', $actor->id);
                self::fail('Legacy update must not bypass confirmed revisions');
            } catch (\App\Exceptions\ContractBuilderException $exception) { self::assertSame(409, $exception->getCode()); }
        }
        $contract->completion_percentage = 20;
        self::assertFalse($mutations->syncCompletionStatus($contract, $actor->id));
        self::assertSame('draft', $contract->fresh()->getRawOriginal('status'));
        self::assertSame('100.00', $contract->fresh()->total_amount);
        $mutations->update($contract->fresh(), ['actual_advance_amount' => 10], 'payment_recorded', $actor->id);
        self::assertSame('10.00', $contract->fresh()->actual_advance_amount);
        $agreement = \App\Models\SupplementaryAgreement::create(['contract_id' => $contract->id, 'number' => 'OLD-API', 'agreement_date' => now(), 'change_amount' => 50, 'subject_changes' => []]);
        try {
            app(\App\Services\Contract\SupplementaryAgreementService::class)->applyOnce($agreement, $actor->id);
            self::fail('Legacy agreement must not bypass revisions');
        } catch (\App\Exceptions\ContractBuilderException $exception) { self::assertSame(409, $exception->getCode()); }
        self::assertNull($agreement->refresh()->applied_at);
        $specification = \App\Models\Specification::create(['number' => 'LEGACY', 'spec_date' => now(), 'total_amount' => 100, 'scope_items' => [], 'status' => 'approved']);
        $contract->specifications()->attach($specification->id, ['attached_at' => now(), 'is_active' => true]);
        $specifications = app(\App\Services\Contract\SpecificationService::class);
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update'
                    ? $specifications->updateForOrganization($specification->id, $actor->current_organization_id, ['total_amount' => 500])
                    : $specifications->deleteForOrganization($specification->id, $actor->current_organization_id);
                self::fail('Legacy specification must remain unchanged');
            } catch (\App\Exceptions\ContractBuilderException $exception) { self::assertSame(409, $exception->getCode()); }
        }
        self::assertSame('100.00', $specification->refresh()->total_amount);
        $request = \Illuminate\Http\Request::create('/api/v1/admin/projects/'.$contract->project_id.'/contracts/'.$contract->id.'/specifications/attach', 'POST');
        $route = \Illuminate\Support\Facades\Route::getRoutes()->match($request);
        self::assertContains(\App\Http\Middleware\GuardLegacyContractSpecification::class, $route->gatherMiddleware());
        $request->setRouteResolver(static fn () => $route);
        $request->setUserResolver(static fn () => $actor);
        try {
            app(\App\Http\Middleware\GuardLegacyContractSpecification::class)->handle($request, static function (): never { self::fail('Legacy attach controller must not execute'); });
            self::fail('Legacy specification attach must be rejected');
        } catch (\App\Exceptions\ContractBuilderException $exception) { self::assertSame(409, $exception->getCode()); }
        $guard = app(\App\Services\Contract\ContractBuilderMutationGuard::class);
        $this->expectException(\App\Exceptions\ContractBuilderException::class);
        $guard->assertLegacy($contract);
    }

    public function test_legacy_adoption_is_explicit_scoped_optimistic_and_keeps_existing_conditions_until_activation(): void
    {
        $this->enableImmutableAuditWriter();
        [$actor, $other, $contract, , $templateId, $input] = $this->fixture(application: true, legacy: true);
        $instances = app(ContractBuilderInstanceService::class);
        self::assertFalse($instances->state($actor, $actor->current_organization_id, $contract->id)['can_create']);
        self::assertTrue($instances->state($actor, $actor->current_organization_id, $contract->id)['can_adopt']);
        self::assertFalse($instances->state($other, $other->current_organization_id, $contract->id)['can_adopt']);
        $service = app(\App\Services\Contract\ContractBuilderAdoptionService::class);
        $preview = $service->preview($actor, $actor->current_organization_id, $contract->id);
        self::assertSame(0, DB::table('contract_builder_instances')->count());
        $contract->update(['total_amount' => '101']);
        try {
            $instances->create($actor, $actor->current_organization_id, $contract->id, $templateId, 1, $input['values'], 'adopt', ['fingerprint' => $preview['fingerprint'], 'basis' => 'Перевод выбранного договора']);
            self::fail('Stale baseline must be rejected');
        } catch (\App\Exceptions\ContractBuilderException $exception) { self::assertSame(409, $exception->getCode()); }
        self::assertSame(0, DB::table('contract_builder_legacy_adoptions')->count());
        $preview = $service->preview($actor, $actor->current_organization_id, $contract->id);
        $before = $contract->refresh()->getAttributes();
        $parties = $contract->parties()->orderBy('side')->get()->toArray();
        $this->withoutMiddleware();
        $this->actingAs($actor, 'api_admin');
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Routing\Events\RouteMatched::class, static function (\Illuminate\Routing\Events\RouteMatched $event): void {
            $event->request->attributes->set('current_organization_id', $event->request->user()?->current_organization_id);
        });
        $url = '/api/v1/admin/contracts/'.$contract->id.'/builder';
        $payload = ['template_id' => $templateId, 'template_version' => 1, 'values' => $input['values'], 'request_key' => 'adopt-http', 'basis' => 'Перевод выбранного договора', 'fingerprint' => $preview['fingerprint']];
        $this->postJson($url.'/adopt', array_diff_key($payload, ['basis' => true]))->assertUnprocessable()->assertJsonValidationErrors('basis');
        $revision = $this->postJson($url.'/adopt', $payload)->assertOk()->json('data');
        $this->postJson($url.'/adopt', $payload)->assertOk()->assertJsonPath('data.id', $revision['id']);
        self::assertEquals($before, $contract->refresh()->getAttributes());
        self::assertEquals($parties, $contract->parties()->orderBy('side')->get()->toArray());
        self::assertNull(DB::table('contract_builder_instances')->value('effective_revision_id'));
        self::assertSame(1, DB::table('contract_builder_legacy_adoptions')->count());
        self::assertSame('active', json_decode(DB::table('contract_builder_legacy_adoptions')->value('baseline'), true)['contract']['status']);
        self::assertTrue($instances->state($actor, $actor->current_organization_id, $contract->id)['can_edit_draft']);
        $confirmations = app(\App\Services\Contract\ContractRevisionConfirmationService::class);
        foreach ([$actor, $other] as $party) {
            $confirmations->confirm($party, $party->current_organization_id, $contract->id, 1, $revision['content_hash'], 'adopt-confirm');
        }
        $activation = app(\App\Services\Contract\ContractRevisionActivationService::class)->schedule($actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash'], null, now()->toDateString(), 'Ввод подтверждённой редакции', 'adopt-activate');
        app(\App\Services\Contract\ContractRevisionApplicationService::class)->retry($actor, $actor->current_organization_id, $contract->id, $activation['id']);
        self::assertSame('120.00', $contract->refresh()->total_amount);
        self::assertSame(0, $contract->payments()->count());
        $this->actingAs($other, 'api_admin');
        $this->getJson($url.'/adoption-preview')->assertNotFound();
    }

    private function fakeBuilderStorage(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');
        $files = \Mockery::mock(\App\Services\Storage\FileService::class);
        $files->shouldReceive('putContent')->andReturnUsing(static function (string $bytes, string $directory, string $filename, string $visibility, Organization $organization): string {
            self::assertSame('private', $visibility);
            $path = 'org-'.$organization->id.'/'.$directory.'/'.$filename;
            \Illuminate\Support\Facades\Storage::disk('s3')->put($path, $bytes);
            return $path;
        });
        $files->shouldReceive('temporaryUrl')->andReturnUsing(static fn (string $path): string => 'https://files.example.test/'.$path);
        $files->shouldReceive('disk')->andReturn(\Illuminate\Support\Facades\Storage::disk('s3'));
        $this->app->instance(\App\Services\Storage\FileService::class, $files);
    }

    private function paymentFact(User $actor, Contract $contract, string $amount): \App\BusinessModules\Core\Payments\Models\PaymentTransaction
    {
        $document = \App\BusinessModules\Core\Payments\Models\PaymentDocument::create([
            'organization_id' => $actor->current_organization_id, 'project_id' => $contract->project_id,
            'document_type' => 'invoice', 'document_number' => 'PAID-'.$actor->id, 'document_date' => now()->toDateString(),
            'direction' => 'outgoing', 'invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id,
            'amount' => $amount, 'currency' => 'RUB', 'paid_amount' => $amount, 'remaining_amount' => 0, 'status' => 'paid',
        ]);
        return \App\BusinessModules\Core\Payments\Models\PaymentTransaction::create([
            'organization_id' => $actor->current_organization_id, 'project_id' => $contract->project_id,
            'payment_document_id' => $document->id, 'amount' => $amount, 'currency' => 'RUB',
            'payment_method' => 'bank_transfer', 'transaction_date' => now()->toDateString(), 'status' => 'completed',
            'created_by_user_id' => $actor->id,
        ]);
    }

    private function fixture(bool $entity = false, bool $source = false, bool $external = false, bool $application = false, bool $legacy = false): array
    {
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $owner = Organization::factory()->create();
        $executor = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $other = User::factory()->create(['current_organization_id' => $executor->id]);
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $contractor = \App\Models\Contractor::create([
            'organization_id' => $owner->id, 'source_organization_id' => $executor->id, 'name' => $executor->name,
        ]);
        $contract = Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id, 'contract_side_type' => 'subcontract',
            'number' => 'DRAFT-1', 'date' => '2026-09-19', 'status' => $legacy ? 'active' : 'draft', 'total_amount' => 100,
        ]);
        foreach ([['first', 'contractor', $owner], ['second', 'subcontractor', $executor]] as [$side, $role, $organization]) {
            $contract->parties()->create(['side' => $side, 'role' => $role, 'linked_organization_id' => $external && $side === 'second' ? null : $organization->id, 'name' => $organization->name, 'snapshot' => []]);
        }
        app(ContractOrganizationViewService::class)->synchronizeNewContract($contract);
        $library = app(ContractLibraryService::class);
        $definition = $entity ? ['type' => 'entity', 'entity_type' => 'project', 'required' => true] : ['type' => 'text', 'required' => true];
        if ($application) {
            $definition = ['type' => 'money', 'required' => true, 'assignment' => ['target' => 'price']];
        }
        $variable = $library->create($actor, $owner->id, 'variable', 'Условие', $definition, 'variable');
        $variableId = $variable['item']['id'];
        $library->publish($actor, $owner->id, $variableId, 1, 1);
        $content = ['document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $variableId]]]]]], 'variables' => [$variableId => 1]];
        if ($source) {
            $field = $library->create($actor, $owner->id, 'variable', 'Название из проекта', ['type' => 'text', 'required' => true,
                'source' => ['kind' => 'entity_field', 'variable_id' => $variableId, 'entity_type' => 'project', 'field' => 'name']], 'source-variable');
            $sourceId = $field['item']['id'];
            $library->publish($actor, $owner->id, $sourceId, 1, 1);
            $formula = $library->create($actor, $owner->id, 'variable', 'Зависимая формула', ['type' => 'text',
                'source' => ['kind' => 'formula', 'expression' => ['kind' => 'reference', 'variable_id' => $sourceId]]], 'source-formula');
            $formulaId = $formula['item']['id'];
            $library->publish($actor, $owner->id, $formulaId, 1, 1);
            $content['variables'][$sourceId] = 1;
            $content['variables'][$formulaId] = 1;
            $content['document']['content'][0]['content'][] = ['type' => 'variable', 'attrs' => ['variableId' => $sourceId]];
        }
        $applicationValues = [];
        if ($application) {
            $content['document']['content'] = [['type' => 'clause', 'attrs' => ['id' => 'price'], 'content' => $content['document']['content']]];
            $works = $library->create($actor, $owner->id, 'variable', 'План работ', ['type' => 'table', 'columns' => [
                ['id' => 'name', 'label' => 'Работа', 'definition' => ['type' => 'text']],
                ['id' => 'unit', 'label' => 'Единица', 'definition' => ['type' => 'text']],
                ['id' => 'quantity', 'label' => 'Объём', 'definition' => ['type' => 'number']],
                ['id' => 'price', 'label' => 'Цена', 'definition' => ['type' => 'money']],
            ], 'assignment' => ['target' => 'works', 'columns' => ['name' => 'name', 'unit' => 'unit', 'quantity' => 'quantity', 'price' => 'price']]], 'works');
            $worksId = $works['item']['id'];
            $library->publish($actor, $owner->id, $worksId, 1, 1);
            $content['variables'][$worksId] = 1;
            $content['document']['content'][] = ['type' => 'clause', 'attrs' => ['id' => 'works'], 'content' => [
                ['type' => 'table', 'content' => [['type' => 'repeatRows', 'attrs' => ['variableId' => $worksId], 'content' => [
                    ['type' => 'tableRow', 'content' => array_map(static fn (string $column): array => [
                        'type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [
                            ['type' => 'variable', 'attrs' => ['variableId' => $worksId, 'columnId' => $column]],
                        ]]],
                    ], ['name', 'unit', 'quantity', 'price'])],
                ]]]],
            ]];
            $applicationValues[$worksId] = [['id' => 'work-1', 'values' => ['name' => 'Монтаж', 'unit' => 'шт.', 'quantity' => '2', 'price' => ['amount' => '60', 'currency' => 'RUB']]]];
        }
        $template = $library->create($actor, $owner->id, 'template', 'Договор', $content, 'template');
        $templateId = $template['item']['id'];
        $library->publish($actor, $owner->id, $templateId, 1, 1);
        $value = $entity ? ['type' => 'project', 'id' => $project->id] : 'Общие условия';
        if ($application) {
            $value = ['amount' => '120', 'currency' => 'RUB'];
        }
        $revision = $legacy ? ['values' => [$variableId => $value, ...$applicationValues]]
            : app(ContractBuilderInstanceService::class)->create($actor, $owner->id, $contract->id, $templateId, 1, [$variableId => $value, ...$applicationValues], 'instance');

        return [$actor, $other, $contract, $variableId, $templateId, $revision];
    }
}
