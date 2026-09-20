<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Contract\ContractBuilderDraftService;
use App\Services\Contract\ContractBuilderInstanceService;
use App\Services\Contract\ContractLibraryService;
use App\Services\Contract\ContractTemplateCardService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ContractTemplateCardIntegrationTest extends TestCase
{
    use \Tests\Support\EnablesImmutableAuditWriter;

    public function test_template_card_save_replay_read_sources_and_private_revision_are_consistent(): void
    {
        [$actor, $input, $ids] = $this->fixture();
        $prepared = $this->postJson('/api/v1/admin/contracts/template-card/prepare', $input)->assertOk()->json('data');
        self::assertSame('120.01', $prepared['card']['terms']['total_amount']);
        self::assertSame(['amount' => '240.02', 'currency' => 'RUB'], $prepared['values'][$ids['formula']]);
        $payload = [...$input, 'is_fixed_amount' => true, 'base_amount' => 120.01,
            'idempotency_key' => 'card-one', 'template' => [...$input['template'], 'source_hash' => $prepared['source_hash']]];
        $saved = $this->postJson('/api/v1/admin/contracts', $payload)->assertCreated()->json('data');
        $contractId = $saved['id'];
        $this->postJson('/api/v1/admin/contracts', $payload)->assertOk()->assertJsonPath('data.id', $contractId);
        $this->postJson('/api/v1/admin/contracts', [...$payload, 'number' => 'CONFLICT'])->assertConflict();
        self::assertSame(1, Contract::where('number', $input['number'])->count());
        $contract = Contract::findOrFail($contractId);
        self::assertSame('120.01', $contract->getRawOriginal('total_amount'));
        self::assertSame('2026-12-01', substr($contract->getRawOriginal('end_date'), 0, 10));
        $instances = app(ContractBuilderInstanceService::class);
        $revision = $instances->read($actor, $actor->current_organization_id, $contractId, 1);
        self::assertEquals($prepared['values'], $revision['values']);
        self::assertSame(2, substr_count($instances->preview($actor, $actor->current_organization_id, $contractId, 1)['html'], 'Собственное условие'));
        $contract->project->update(['name' => 'Новое название проекта']);
        $drafts = app(ContractBuilderDraftService::class);
        $draftValues = [...$input['template']['values'], $ids['price'] => ['amount' => '150.01', 'currency' => 'RUB']];
        $draft = $drafts->save($actor, $actor->current_organization_id, $contractId, 1, 0, $revision['document'], $draftValues, 'draft-one');
        self::assertSame($prepared['values'][$ids['source']], $draft['values'][$ids['source']]);
        self::assertSame($draft, $drafts->read($actor, $actor->current_organization_id, $contractId));
        $card = app(ContractTemplateCardService::class)->read($actor, $actor->current_organization_id, $contractId);
        self::assertSame('150.01', $card['draft']['card']['terms']['total_amount']);
        self::assertSame('120.01', $contract->fresh()->getRawOriginal('total_amount'));
        try {
            $drafts->save($actor, $actor->current_organization_id, $contractId, 1, 0, $revision['document'], $draftValues, 'stale');
            self::fail('Stale draft was accepted');
        } catch (\App\Exceptions\ContractBuilderException $error) {
            self::assertSame(409, $error->getCode());
        }
    }

    public function test_invalid_values_currency_foreign_context_and_changed_sources_leave_no_partial_contract(): void
    {
        [, $input, $ids] = $this->fixture();
        $invalid = $input;
        $invalid['template']['values'][$ids['price']] = ['amount' => '1', 'currency' => 'USD'];
        $this->postJson('/api/v1/admin/contracts/template-card/prepare', $invalid)->assertUnprocessable();
        $invalid['template']['values'][$ids['price']] = ['not' => 'money'];
        $this->postJson('/api/v1/admin/contracts/template-card/prepare', $invalid)->assertUnprocessable();
        $foreign = Project::factory()->create();
        $this->postJson('/api/v1/admin/contracts/template-card/prepare', [...$input, 'project_id' => $foreign->id])->assertForbidden();
        $prepared = $this->postJson('/api/v1/admin/contracts/template-card/prepare', $input)->assertOk()->json('data');
        Project::findOrFail($input['project_id'])->update(['name' => 'Изменено до сохранения']);
        $this->postJson('/api/v1/admin/contracts', [...$input, 'base_amount' => 120.01, 'is_fixed_amount' => true,
            'idempotency_key' => 'changed-source', 'template' => [...$input['template'], 'source_hash' => $prepared['source_hash']]])->assertConflict();
        self::assertSame(0, Contract::where('number', $input['number'])->count());
        self::assertSame(0, DB::table('contract_template_card_operations')->count());
    }

    public function test_duplicate_assignments_and_late_source_change_roll_back_the_whole_creation(): void
    {
        [$actor, $input, $ids] = $this->fixture();
        $service = app(ContractTemplateCardService::class);
        $prepared = $service->prepare($actor, $actor->current_organization_id, $input);
        $duplicateId = '77777777-7777-4777-8777-777777777777';
        $duplicate = $prepared;
        $duplicate['definitions'][$duplicateId] = [...$prepared['definitions'][$ids['price']], 'id' => $duplicateId];
        $duplicate['values'][$duplicateId] = $prepared['values'][$ids['price']];
        $duplicate['document']['content'][0]['content'][0]['content'][] = ['type' => 'variable', 'attrs' => ['variableId' => $duplicateId]];
        try {
            (new \App\Services\Contract\ContractRevisionTermsCompiler)->compile($duplicate);
            self::fail('Two price assignments were accepted');
        } catch (\App\Exceptions\ContractBuilderException $error) {
            self::assertSame('contracts.revision_terms_invalid_assignment', $error->messageKey());
        }
        Contract::created(static function (Contract $contract): void {
            $contract->project()->update(['name' => 'Источник изменился внутри записи']);
        });
        $before = DB::table('legal_archive_documents')->count();
        $this->postJson('/api/v1/admin/contracts', [...$input, 'base_amount' => 120.01, 'is_fixed_amount' => true,
            'idempotency_key' => 'late-source', 'template' => [...$input['template'], 'source_hash' => $prepared['source_hash']]])->assertConflict();
        self::assertSame(0, Contract::where('number', $input['number'])->count());
        self::assertSame($before, DB::table('legal_archive_documents')->count());
        self::assertSame(0, DB::table('contract_builder_instances')->count());
        self::assertSame(0, DB::table('contract_template_card_operations')->count());
    }

    public function test_context_sources_are_typed_readonly_and_feed_formulas(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $derived = '22222222-2222-4222-8222-222222222222';
        $definitions = [
            $id => ['type' => 'date', 'required' => true, 'source' => ['kind' => 'contract_context', 'field' => 'contract.date']],
            $derived => ['type' => 'date', 'source' => ['kind' => 'formula', 'expression' => ['kind' => 'reference', 'variable_id' => $id]]],
        ];
        self::assertSame('date', \App\Services\Contract\ContractContextSourceFields::type('contract.date'));
        self::assertNull(\App\Services\Contract\ContractContextSourceFields::type('contract.secret'));
        $values = (new \App\Services\Contract\ContractFormulaEngine)->calculate($definitions, [], null, static fn (): string => '2026-09-20');
        self::assertSame('2026-09-20', $values[$derived]);
        $this->expectException(\App\Exceptions\ContractBuilderException::class);
        (new \App\Services\Contract\ContractFormulaEngine)->calculate($definitions, [$id => '2025-01-01']);
    }

    public function test_positioned_library_block_keeps_layout_values_and_card_terms_through_atomic_save(): void
    {
        [$actor, $input, $ids] = $this->fixture(true);
        $prepared = $this->postJson('/api/v1/admin/contracts/template-card/prepare', $input)->assertOk()->json('data');
        self::assertSame('group', $prepared['document']['content'][0]['type']);
        self::assertSame('card-block', $prepared['document']['content'][0]['attrs']['layout']['id']);
        self::assertStringContainsString('data-contract-print=', $prepared['html']);
        $saved = $this->postJson('/api/v1/admin/contracts', [...$input, 'is_fixed_amount' => true, 'base_amount' => 120.01,
            'idempotency_key' => 'positioned-card', 'template' => [...$input['template'], 'source_hash' => $prepared['source_hash']]])->assertCreated()->json('data');
        $revision = app(ContractBuilderInstanceService::class)->read($actor, $actor->current_organization_id, $saved['id'], 1);
        self::assertEquals($prepared['document'], $revision['document']);
        self::assertEquals($prepared['values'], $revision['values']);
        self::assertSame(['amount' => '240.02', 'currency' => 'RUB'], $revision['values'][$ids['formula']]);
        $card = $this->getJson('/api/v1/admin/contracts/'.$saved['id'].'/template-card')->assertOk()->json('data');
        self::assertEquals($prepared['card'], $card['revision']['card']);
        $contract = Contract::findOrFail($saved['id']);
        self::assertSame('120.01', $contract->getRawOriginal('total_amount'));
        self::assertSame('2026-12-01', substr($contract->getRawOriginal('end_date'), 0, 10));
    }

    public function test_standard_templates_are_idempotent_frozen_and_create_matching_subject_profile_and_terms(): void
    {
        [$actor, $input] = $this->fixture();
        $catalogue = $this->getJson('/api/v1/admin/contracts/standard-templates')->assertOk()->json('data');
        self::assertSame(['work', 'construction', 'subcontract'], array_column($catalogue, 'code'));
        $resolved = [];
        foreach ($catalogue as $standard) {
            $resolved = $this->postJson('/api/v1/admin/contracts/standard-templates/install', ['code' => $standard['code']])->assertOk()->json('data');
            $this->postJson('/api/v1/admin/contracts/standard-templates/install', ['code' => $standard['code']])
                ->assertOk()->assertJsonPath('data.template_id', $resolved['template_id']);
            $values = [];
            foreach ($resolved['definitions'] as $id => $field) {
                $definition = $field['definition'];
                if (isset($definition['source'])) {
                    continue;
                }
                $values[$id] = match ($definition['type']) {
                    'money' => ['amount' => '123.45', 'currency' => 'RUB'],
                    'date' => $definition['assignment']['field'] === 'start_date' ? '2026-10-01' : '2026-12-01',
                    default => 'Согласованное условие: '.$field['title'],
                };
            }
            $payload = [...$input, 'number' => 'BASE-'.$standard['code'], 'template' => [
                'template_id' => $resolved['template_id'], 'template_version' => 1, 'values' => $values,
            ]];
            $prepared = $this->postJson('/api/v1/admin/contracts/template-card/prepare', $payload)->assertOk()->json('data');
            $payload['template']['source_hash'] = $prepared['source_hash'];
            $payload['idempotency_key'] = 'base-'.$standard['code'];
            $this->postJson('/api/v1/admin/contracts', [...$payload, 'document_profile_code' => 'contract.work'])->assertUnprocessable();
            $this->postJson('/api/v1/admin/contracts', [...$payload, 'actual_advance_amount' => 5])->assertUnprocessable();
            $saved = $this->postJson('/api/v1/admin/contracts', $payload)->assertCreated()->json('data');
            $this->postJson('/api/v1/admin/contracts', $payload)->assertOk()->assertJsonPath('data.id', $saved['id']);
            $contract = Contract::findOrFail($saved['id']);
            self::assertSame('Согласованное условие: Предмет договора', $contract->subject);
            self::assertSame('Согласованное условие: Порядок и сроки оплаты', $contract->payment_terms);
            self::assertSame('123.45', $contract->getRawOriginal('total_amount'));
            self::assertSame($standard['contract_profile_code'], $contract->legalArchiveDocument()->firstOrFail()->type_profile_code);
            $reopened = $this->getJson('/api/v1/admin/contracts/'.$saved['id'].'/template-card')->assertOk()->json('data');
            self::assertSame($prepared['card'], $reopened['revision']['card']);
            $this->patchJson('/api/v1/admin/contract-library/'.$resolved['template_id'].'/archive', ['expected_version' => 1, 'archived' => true])->assertUnprocessable();
            $this->postJson('/api/v1/admin/contract-library/'.$resolved['template_id'].'/versions', [
                'kind' => 'template', 'title' => 'Подмена', 'content' => ['document' => $resolved['document'], 'variables' => array_fill_keys(array_keys($resolved['definitions']), 1)],
                'expected_version' => 1, 'request_key' => 'replace-base',
            ])->assertUnprocessable();
        }
        $foreign = Organization::factory()->verified()->create();
        $other = User::factory()->create(['current_organization_id' => $foreign->id]);
        $otherResolved = app(\App\Services\Contract\ContractStandardTemplateService::class)->install($other, $foreign->id, 'subcontract');
        self::assertNotSame($resolved['template_id'], $otherResolved['template_id']);
        try {
            app(ContractLibraryService::class)->resolveTemplate($other, $foreign->id, $resolved['template_id'], 1);
            self::fail('Foreign template was resolved');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            self::assertTrue(true);
        }
    }

    public function test_standard_template_creation_does_not_require_library_or_edit_permissions(): void
    {
        [$actor, $input] = $this->fixture();
        app(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(
            static fn ($user, string $permission): bool => !str_starts_with($permission, 'contracts.library.') && $permission !== 'contracts.edit',
        );
        $resolved = $this->postJson('/api/v1/admin/contracts/standard-templates/install', ['code' => 'work'])->assertOk()->json('data');
        $values = [];
        foreach ($resolved['definitions'] as $id => $field) {
            $definition = $field['definition'];
            if (isset($definition['source'])) {
                continue;
            }
            $values[$id] = match ($definition['type']) {
                'money' => ['amount' => '100', 'currency' => 'RUB'],
                'date' => '2026-12-01',
                default => 'Согласовано сторонами',
            };
        }
        $input['template'] = ['template_id' => $resolved['template_id'], 'template_version' => 1, 'values' => $values];
        $prepared = $this->postJson('/api/v1/admin/contracts/template-card/prepare', $input)->assertOk()->json('data');
        $input['template']['source_hash'] = $prepared['source_hash'];
        $this->postJson('/api/v1/admin/contracts', [...$input, 'idempotency_key' => 'create-only'])->assertCreated();
        $this->postJson('/api/v1/admin/contract-library', ['kind' => 'template', 'title' => 'Недопустимо',
            'content' => ['document' => $resolved['document'], 'variables' => array_fill_keys(array_keys($resolved['definitions']), 1)],
            'request_key' => 'create-denied'])->assertForbidden();
        self::assertSame(1, Contract::where('number', $input['number'])->count());
    }

    public function test_template_update_preserves_local_text_requires_explicit_replacement_and_freezes_proposal_origin(): void
    {
        [$actor, $input, $ids] = $this->fixture();
        $contractId = $this->createTemplateContract($input);
        $org = (int) $actor->current_organization_id;
        $instances = app(ContractBuilderInstanceService::class);
        $revision = $instances->read($actor, $org, $contractId, 1);
        $local = $revision['document'];
        $local['content'][] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Индивидуальный порядок работ']]];
        app(ContractBuilderDraftService::class)->save($actor, $org, $contractId, 1, 0, $local, $input['template']['values'], 'local-text');
        $library = app(ContractLibraryService::class);
        $item = $library->read($actor, $org, $input['template']['template_id'], 1);
        $content = $item['version']['content'];
        $content['document']['content'][] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Новая общая формулировка']]];
        $library->revise($actor, $org, $input['template']['template_id'], 2, 'Обновлённый шаблон', $content, 'updated-template');
        $library->publish($actor, $org, $input['template']['template_id'], 2, 3);
        $preview = $this->getJson("/api/v1/admin/contracts/{$contractId}/builder/template-update")->assertOk()->json('data');
        self::assertTrue($preview['conflict']);
        self::assertStringContainsString('Индивидуальный порядок', $preview['local_text']);
        self::assertStringContainsString('Новая общая', $preview['template_text']);
        self::assertSame($revision, $instances->read($actor, $org, $contractId, 1));
        $payload = array_intersect_key($preview, array_flip(['base_revision', 'expected_version', 'target_version', 'comparison_hash']))
            + ['resolution' => 'local', 'request_key' => 'keep-local'];
        $draft = $this->postJson("/api/v1/admin/contracts/{$contractId}/builder/template-update", $payload)->assertOk()->json('data');
        self::assertEquals($local, $draft['document']);
        self::assertSame($revision['values'][$ids['custom']], $draft['values'][$ids['custom']]);
        self::assertNotSame($revision['template_version_id'], $draft['template_version_id']);
        $this->postJson("/api/v1/admin/contracts/{$contractId}/builder/template-update", $payload)->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson("/api/v1/admin/contracts/{$contractId}/builder/template-update", [...$payload, 'request_key' => 'stale-update'])->assertConflict();
        $proposals = app(\App\Services\Contract\ContractProposalService::class);
        $proposal = $proposals->create($actor, $org, $contractId, 1, 2, '', 'updated-proposal');
        self::assertSame($draft['template_version_id'], (int) DB::table('contract_builder_proposals')->where('id', $proposal['id'])->value('template_version_id'));
        self::assertEquals($local, $proposal['content']['document']);
        self::assertSame(1, DB::table('contract_revision_documents')->count());
        $this->fakeRevisionStorage();
        $evidence = app(\App\Services\Contract\ContractBuilderAssetService::class)->upload($actor, $org, $contractId,
            \Illuminate\Http\UploadedFile::fake()->createWithContent('Письмо.txt', 'Согласовано внешней стороной'), 'evidence', 'accept-evidence');
        $proposals->decide($actor, $org, $contractId, $proposal['id'], 1, 'accepted', '', 'accept-update', ['basis' => 'Письмо внешней стороны', 'asset_id' => $evidence['id']]);
        $next = $instances->read($actor, $org, $contractId, 2);
        self::assertSame($draft['template_version_id'], $next['template_version_id']);
        self::assertEquals($local, $next['document']);
        self::assertSame(2, DB::table('contract_revision_documents')->count());
        self::assertSame($revision, $instances->read($actor, $org, $contractId, 1));
        $files = app(\App\Services\Contract\ContractRevisionDocumentService::class);
        $generations = DB::table('contract_revision_documents')->orderBy('id')->pluck('id');
        self::assertTrue($files->generate((int) $generations[0]));
        $archive = Contract::findOrFail($contractId)->legalArchiveDocument()->firstOrFail();
        $firstVersion = (int) $archive->current_primary_version_id;
        self::assertTrue($files->generate((int) $generations[1]));
        self::assertSame($firstVersion, (int) $archive->fresh()->current_primary_version_id);
        self::assertSame(2, $archive->versions()->count());
    }

    public function test_revision_docx_uses_existing_dossier_frozen_layout_and_idempotent_archive_version(): void
    {
        [$actor, $input] = $this->fixture(true);
        $this->fakeRevisionStorage();
        $contractId = $this->createTemplateContract($input);
        $contract = Contract::findOrFail($contractId);
        $document = $contract->legalArchiveDocument()->firstOrFail();
        $id = (int) DB::table('contract_revision_documents')->sole()->id;
        $service = app(\App\Services\Contract\ContractRevisionDocumentService::class);
        self::assertTrue($service->generate($id));
        $row = DB::table('contract_revision_documents')->where('id', $id)->firstOrFail();
        self::assertSame('ready', $row->status);
        self::assertSame((int) $document->id, (int) $row->document_id);
        $version = \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion::findOrFail($row->document_version_id);
        self::assertSame((int) $document->id, (int) $version->document_id);
        self::assertSame($row->content_hash, $version->metadata['contract_content_hash']);
        $path = tempnam(sys_get_temp_dir(), 'revision-test-');
        try {
            file_put_contents($path, \Illuminate\Support\Facades\Storage::disk('revision-tests')->get($row->storage_path));
            $zip = new \ZipArchive;
            self::assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            self::assertStringContainsString('Собственное', $xml);
            self::assertStringContainsString($row->content_hash, strip_tags($xml));
            self::assertStringContainsString('Внешний', $xml);
        } finally {
            unlink($path);
        }
        self::assertTrue($service->generate($id));
        self::assertSame(1, $document->versions()->count());
        self::assertSame($id, $service->request((int) $row->revision_id, (int) $actor->id));
        self::assertSame(1, DB::table('contract_revision_documents')->count());
        self::assertSame('ready', $service->state($actor, (int) $actor->current_organization_id, $contractId, 1)['status']);
        config()->set('legal-document-editor.editing_enabled', false);
        $access = $this->createMock(\App\Services\LegalArchive\Access\LegalDocumentAuthorizer::class);
        $this->app->instance(\App\Services\LegalArchive\Access\LegalDocumentAuthorizer::class, $access);
        $editor = $this->createMock(\App\Services\LegalArchive\Editor\LegalDocumentEditor::class);
        $editor->method('enabled')->willReturn(false);
        $this->app->instance(\App\Services\LegalArchive\Editor\LegalDocumentEditor::class, $editor);
        $sessions = app(\App\Services\LegalArchive\Editor\LegalDocumentEditorSessionService::class);
        foreach (['edit', 'review'] as $mode) {
            try {
                $sessions->open($version, $actor, $mode);
                self::fail('New Word editing must be disabled');
            } catch (\DomainException $error) {
                self::assertSame('legal_document_editor_disabled', $error->getMessage());
            }
        }
        $viewer = $sessions->open($version, $actor, 'view');
        self::assertSame('https://example.test/revision.docx', $viewer->viewerUrl);
        try {
            app(\App\Services\LegalArchive\Editor\LegalDocumentBlankDraftService::class)->start($document, $actor, 'Новый файл', (int) $document->lock_version);
            self::fail('Blank Word drafts must be disabled');
        } catch (\DomainException $error) {
            self::assertSame('legal_document_editor_disabled', $error->getMessage());
        }
        self::assertSame(1, $document->versions()->count());
    }

    public function test_template_replacement_lists_removed_values_and_requires_acknowledgement(): void
    {
        [$actor, $input, $ids] = $this->fixture();
        $contractId = $this->createTemplateContract($input);
        $org = (int) $actor->current_organization_id;
        $library = app(ContractLibraryService::class);
        $item = $library->read($actor, $org, $input['template']['template_id'], 1);
        $content = $item['version']['content'];
        unset($content['variables'][$ids['custom']]);
        $content['document']['content'][0]['content'][0]['content'] = array_values(array_filter(
            $content['document']['content'][0]['content'][0]['content'], static fn ($node): bool => ($node['attrs']['variableId'] ?? '') !== $ids['custom']));
        $library->revise($actor, $org, $input['template']['template_id'], 2, 'Без особого условия', $content, 'replace-template');
        $library->publish($actor, $org, $input['template']['template_id'], 2, 3);
        $preview = $this->getJson("/api/v1/admin/contracts/{$contractId}/builder/template-update")->assertOk()->json('data');
        self::assertSame($ids['custom'], $preview['removed_values'][0]['id']);
        $payload = array_intersect_key($preview, array_flip(['base_revision', 'expected_version', 'target_version', 'comparison_hash']))
            + ['resolution' => 'template', 'values' => $preview['values'], 'request_key' => 'replace-values'];
        $this->postJson("/api/v1/admin/contracts/{$contractId}/builder/template-update", $payload)->assertUnprocessable();
        $draft = $this->postJson("/api/v1/admin/contracts/{$contractId}/builder/template-update", [...$payload, 'acknowledge_removed_values' => true])->assertOk()->json('data');
        self::assertArrayNotHasKey($ids['custom'], $draft['values']);
        self::assertSame($input['template']['values'][$ids['price']], $draft['values'][$ids['price']]);
        self::assertSame(1, DB::table('contract_builder_revisions')->count());
        self::assertSame(1, DB::table('contract_revision_documents')->count());
    }

    public function test_revision_document_failure_is_retryable_and_foreign_contract_is_forbidden(): void
    {
        [$actor, $input] = $this->fixture();
        $this->fakeRevisionStorage(true);
        $contractId = $this->createTemplateContract($input);
        $row = DB::table('contract_revision_documents')->sole();
        $service = app(\App\Services\Contract\ContractRevisionDocumentService::class);
        try {
            $service->generate((int) $row->id);
            self::fail('Storage outage must fail');
        } catch (\RuntimeException) {
            self::assertSame('failed', DB::table('contract_revision_documents')->where('id', $row->id)->value('status'));
        }
        self::assertTrue($service->state($actor, (int) $actor->current_organization_id, $contractId, 1)['can_retry']);
        $service->retry($actor, (int) $actor->current_organization_id, $contractId, 1);
        self::assertTrue($service->generate((int) $row->id));
        $foreign = Organization::factory()->verified()->create();
        $other = User::factory()->create(['current_organization_id' => $foreign->id]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->state($other, (int) $foreign->id, $contractId, 1);
    }

    private function createTemplateContract(array $input): int
    {
        $prepared = $this->postJson('/api/v1/admin/contracts/template-card/prepare', $input)->assertOk()->json('data');

        return $this->postJson('/api/v1/admin/contracts', [...$input, 'base_amount' => 120.01, 'is_fixed_amount' => true,
            'idempotency_key' => 'instance-test', 'template' => [...$input['template'], 'source_hash' => $prepared['source_hash']]])->assertCreated()->json('data.id');
    }

    private function fakeRevisionStorage(bool $failFirst = false): void
    {
        $disk = \Illuminate\Support\Facades\Storage::fake('revision-tests');
        $storage = $this->createMock(\App\Services\Storage\FileService::class);
        $storage->method('disk')->willReturn($disk);
        $storage->method('temporaryUrl')->willReturn('https://example.test/revision.docx');
        $storage->method('putContent')->willReturnCallback(static function ($bytes, $directory, $filename, $visibility, $org) use ($disk, &$failFirst): string {
            if ($failFirst) {
                $failFirst = false;
                throw new \RuntimeException('temporary_storage_outage');
            }
            $path = "org-{$org->id}/{$directory}/{$filename}";
            $disk->put($path, $bytes);
            return $path;
        });
        $storage->method('upload')->willReturnCallback(static function ($upload, $directory, $existing, $visibility, $org) use ($disk): string {
            $path = "org-{$org->id}/{$directory}/".bin2hex(random_bytes(8)).'.'.$upload->getClientOriginalExtension();
            $disk->put($path, $upload->getContent());
            return $path;
        });
        $this->app->instance(\App\Services\Storage\FileService::class, $storage);
        $this->app->instance(\App\Services\LegalArchive\Files\LegalDocumentScanner::class, $this->createMock(\App\Services\LegalArchive\Files\LegalDocumentScanner::class));
    }

    public function test_system_fields_are_ready_idempotent_immutable_and_organization_scoped(): void
    {
        [$actor] = $this->fixture();
        $rows = $this->getJson('/api/v1/admin/contract-library/system-fields')->assertOk()->json('data');
        self::assertCount(19, $rows);
        $payload = ['code' => 'second_party.name'];
        $first = $this->postJson('/api/v1/admin/contract-library/system-fields/install', $payload)->assertOk()->json('data');
        $this->postJson('/api/v1/admin/contract-library/system-fields/install', $payload)->assertOk()->assertJsonPath('data.item.id', $first['item']['id']);
        self::assertSame('published', $first['version']['status']);
        self::assertSame(['kind' => 'contract_context', 'field' => 'second_party.name'], $first['version']['content']['source']);
        $this->postJson('/api/v1/admin/contract-library/system-fields/install', ['code' => 'organization.secret'])->assertUnprocessable();
        $library = app(ContractLibraryService::class);
        try {
            $library->revise($actor, $actor->current_organization_id, $first['item']['id'], $first['item']['lock_version'], 'Changed', ['type' => 'text'], 'system-change');
            self::fail('System definition changed');
        } catch (\App\Exceptions\ContractBuilderException $error) {
            self::assertSame('contract_templates.readonly', $error->messageKey());
        }
        $foreign = Organization::factory()->create();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(\App\Services\Contract\ContractStandardTemplateService::class)->installSystemField($actor, $foreign->id, 'contract.number');
    }

    public function test_template_publication_requires_real_clause_basis_and_preserves_published_history(): void
    {
        [$actor, $input] = $this->fixture();
        $library = app(ContractLibraryService::class);
        $id = $input['template']['template_id'];
        $original = $library->read($actor, $actor->current_organization_id, $id, 1);
        $content = $original['version']['content'];
        $content['document']['content'] = $content['document']['content'][0]['content'];
        $draft = $library->revise($actor, $actor->current_organization_id, $id, $original['item']['lock_version'], 'Missing clause', $content, 'missing-basis');
        try {
            $library->publish($actor, $actor->current_organization_id, $id, 2, $draft['item']['lock_version']);
            self::fail('Invalid template published');
        } catch (\App\Exceptions\ContractBuilderException $error) {
            self::assertSame('contracts.revision_terms_invalid_basis', $error->messageKey());
            self::assertMatchesRegularExpression('/Поле «(?:price|start|end|works)»/u', $error->getMessage());
            self::assertStringNotContainsString($input['template']['template_id'], $error->getMessage());
        }
        self::assertSame('draft', $library->read($actor, $actor->current_organization_id, $id, 2)['version']['status']);
        self::assertSame($original['version'], $library->read($actor, $actor->current_organization_id, $id, 1)['version']);
    }

    public function test_source_preview_uses_authoritative_context_and_preserves_manual_input(): void
    {
        [$actor, $input, $ids] = $this->fixture();
        $input['resolve_only'] = true;
        $input['template']['values'][$ids['source']] = 'Поддельное название';
        $preview = $this->postJson('/api/v1/admin/contracts/template-card/prepare', $input)->assertOk()->json('data');
        self::assertSame(Project::findOrFail($input['project_id'])->name, $preview['values'][$ids['source']]);
        self::assertSame('Собственное условие', $preview['values'][$ids['custom']]);
        self::assertNull($preview['html']);
        self::assertNull($preview['card']);
        self::assertSame(0, Contract::where('number', $input['number'])->count());
    }

    private function fixture(bool $positioned = false): array
    {
        $this->enableImmutableAuditWriter();
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\GenerateContractRevisionDocument::class]);
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->byDefault()->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $owner = Organization::factory()->verified()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $owner->users()->attach($actor->id, ['is_owner' => true, 'is_active' => true]);
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $contractor = Contractor::create(['organization_id' => $owner->id, 'name' => 'Внешний подрядчик']);
        $this->withoutMiddleware();
        $this->actingAs($actor, 'api_admin');
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Routing\Events\RouteMatched::class, static function ($event): void {
            $event->request->attributes->set('current_organization_id', $event->request->user()?->current_organization_id);
        });
        $library = app(ContractLibraryService::class);
        $ids = [];
        $definitions = [
            'custom' => ['type' => 'text', 'required' => true],
            'price' => ['type' => 'money', 'required' => true, 'constraints' => ['currency' => 'RUB'], 'assignment' => ['target' => 'price']],
            'end' => ['type' => 'date', 'required' => true, 'assignment' => ['target' => 'schedule', 'field' => 'end_date']],
            'source' => ['type' => 'text', 'required' => true, 'source' => ['kind' => 'contract_context', 'field' => 'project.name']],
        ];
        foreach ($definitions as $name => $definition) {
            $variable = $library->create($actor, $owner->id, 'variable', $name, $definition, 'variable-'.$name);
            $ids[$name] = $variable['item']['id'];
            $library->publish($actor, $owner->id, $ids[$name], 1, 1);
        }
        $formula = $library->create($actor, $owner->id, 'variable', 'Расчёт', ['type' => 'money', 'source' => ['kind' => 'formula', 'expression' => [
            'kind' => 'operation', 'operator' => 'multiply', 'args' => [
                ['kind' => 'reference', 'variable_id' => $ids['price']], ['kind' => 'literal', 'type' => 'number', 'value' => '2'],
            ],
        ]]], 'formula');
        $ids['formula'] = $formula['item']['id'];
        $library->publish($actor, $owner->id, $ids['formula'], 1, 1);
        $nodes = array_map(static fn (string $id): array => ['type' => 'variable', 'attrs' => ['variableId' => $id]], [...array_values($ids), $ids['custom']]);
        $content = [
            'document' => ['type' => 'doc', 'content' => [['type' => 'clause', 'attrs' => ['id' => 'terms'], 'content' => [['type' => 'paragraph', 'content' => $nodes]]]]],
            'variables' => array_fill_keys(array_values($ids), 1),
        ];
        if ($positioned) {
            $block = $library->create($actor, $owner->id, 'block', 'Условия карточки', $content, 'card-block');
            $library->publish($actor, $owner->id, $block['item']['id'], 1, 1);
            $content['document'] = ['type' => 'doc', 'attrs' => ['layout' => [
                'version' => 1, 'page' => ['format' => 'A4', 'orientation' => 'portrait',
                    'margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
                'grid' => ['size' => 5, 'snap' => true, 'visible' => true],
            ]], 'content' => [['type' => 'blockReference', 'attrs' => [
                'blockId' => $block['item']['id'], 'version' => 1, 'instanceId' => 'card-copy',
                'layout' => ['id' => 'card-block', 'x' => 10, 'y' => 15, 'width' => 150, 'minHeight' => 35,
                    'align' => 'left', 'firstLineIndent' => 5, 'lineHeight' => 1.5, 'spaceAfter' => 3],
            ]]]];
        }
        $template = $library->create($actor, $owner->id, 'template', 'Карточка', $content, 'template');
        $library->publish($actor, $owner->id, $template['item']['id'], 1, 1);

        return [$actor, [
            'project_id' => $project->id, 'contract_side_type' => 'subcontract', 'direction' => 'expense',
            'contractor_id' => $contractor->id, 'number' => 'TEMPLATE-CARD-1', 'date' => '2026-09-20',
            'template' => ['template_id' => $template['item']['id'], 'template_version' => 1, 'values' => [
                $ids['custom'] => 'Собственное условие', $ids['price'] => ['amount' => '120.01', 'currency' => 'RUB'], $ids['end'] => '2026-12-01',
            ]],
        ], $ids];
    }
}
