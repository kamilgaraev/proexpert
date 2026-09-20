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
            self::assertSame('contracts.revision_terms_invalid', $error->messageKey());
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

    private function fixture(bool $positioned = false): array
    {
        $this->enableImmutableAuditWriter();
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
