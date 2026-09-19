<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContractLibraryPersistenceTest extends TestCase
{
    public function test_formulas_are_calculated_and_frozen_in_shared_revision(): void
    {
        $owner = Organization::factory()->create();
        $executor = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $otherActor = User::factory()->create(['current_organization_id' => $executor->id]);
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $library = new \App\Services\Contract\ContractLibraryService($authorization);
        $views = new \App\Services\Contract\ContractOrganizationViewService($authorization);
        $service = new \App\Services\Contract\ContractBuilderInstanceService($authorization, $views, $library, new \App\Services\Contract\ContractVariableValueValidator);
        $project = \App\Models\Project::factory()->create(['organization_id' => $owner->id]);
        $contractor = \App\Models\Contractor::create(['organization_id' => $owner->id, 'source_organization_id' => $executor->id, 'name' => 'Исполнитель']);
        $contract = \App\Models\Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
            'number' => 'FORMULA-1', 'date' => '2026-09-19', 'status' => 'draft', 'total_amount' => 100, 'contract_side_type' => 'subcontract',
        ]);
        $contract->parties()->create(['side' => 'first', 'role' => 'contractor', 'linked_organization_id' => $owner->id, 'name' => 'Подрядчик', 'snapshot' => []]);
        $contract->parties()->create(['side' => 'second', 'role' => 'subcontractor', 'linked_organization_id' => $executor->id, 'name' => 'Исполнитель', 'snapshot' => []]);
        $views->synchronizeNewContract($contract);
        $price = $library->create($actor, $owner->id, 'variable', 'Цена', ['type' => 'money', 'required' => true], 'price');
        $priceId = $price['item']['id'];
        $library->publish($actor, $owner->id, $priceId, 1, 1);
        $definition = ['type' => 'money', 'source' => ['kind' => 'formula', 'expression' => [
            'kind' => 'operation', 'operator' => 'divide', 'scale' => 2, 'args' => [
                ['kind' => 'reference', 'variable_id' => $priceId],
                ['kind' => 'literal', 'type' => 'number', 'value' => '2'],
            ],
        ]]];
        $computed = $library->create($actor, $owner->id, 'variable', 'Аванс', $definition, 'advance');
        $computedId = $computed['item']['id'];
        $library->publish($actor, $owner->id, $computedId, 1, 1);
        $content = ['document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $computedId]]]],
        ]], 'variables' => [$computedId => 1]];
        $template = $library->create($actor, $owner->id, 'template', 'Субподряд', $content, 'template');
        try {
            $library->publish($actor, $owner->id, $template['item']['id'], 1, 1);
            self::fail('Formula dependencies must have pinned versions');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame('draft', $library->read($actor, $owner->id, $template['item']['id'], 1)['version']['status']);
        }
        $content['variables'][$priceId] = 1;
        $library->revise($actor, $owner->id, $template['item']['id'], 1, 'Субподряд', $content, 'pinned');
        $library->publish($actor, $owner->id, $template['item']['id'], 2, 2);
        $values = [$priceId => ['amount' => '100.01', 'currency' => 'RUB']];
        try {
            $service->create($actor, $owner->id, $contract->id, $template['item']['id'], 2, $values + [$computedId => null], 'forged');
            self::fail('Computed values must not be supplied');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame(0, DB::table('contract_builder_instances')->where('contract_id', $contract->id)->count());
        }
        $created = $service->create($actor, $owner->id, $contract->id, $template['item']['id'], 2, $values, 'create');
        self::assertSame(['amount' => '50.01', 'currency' => 'RUB'], $created['values'][$computedId]);
        self::assertEquals($definition, $created['definitions'][$computedId]['definition']);
        self::assertSame($created, $service->read($otherActor, $executor->id, $contract->id, 1));
        $preview = $service->preview($otherActor, $executor->id, $contract->id, 1);
        self::assertStringContainsString('50.01', $preview['html']);
        $library->revise($actor, $owner->id, $computedId, 2, 'Другой аванс', ['type' => 'money'], 'changed');
        self::assertSame($created, $service->create($actor, $owner->id, $contract->id, $template['item']['id'], 2, $values, 'create'));
        self::assertSame($preview, $service->preview($otherActor, $executor->id, $contract->id, 1));
    }

    public function test_renderer_uses_values_conditions_rows_and_visible_clause_numbers_safely(): void
    {
        $renderer = new \App\Services\Contract\ContractDocumentRenderer;
        $flag = (string) Str::uuid();
        $table = (string) Str::uuid();
        $money = (string) Str::uuid();
        $text = (string) Str::uuid();
        $types = [
            $flag => ['type' => 'boolean'], $money => ['type' => 'money'], $text => ['type' => 'text'],
            $table => ['type' => 'table', 'columns' => [['id' => 'work', 'label' => 'Работа', 'definition' => ['type' => 'text']]]],
        ];
        $definitions = [];
        foreach ($types as $id => $definition) {
            $definitions[$id] = ['id' => $id, 'version' => 1, 'definition' => $definition];
        }
        $paragraph = fn (array $content): array => ['type' => 'paragraph', 'content' => $content];
        $variable = fn (string $id): array => ['type' => 'variable', 'attrs' => ['variableId' => $id]];
        $clause = fn (string $id, string $label): array => ['type' => 'clause', 'attrs' => ['id' => $id], 'content' => [$paragraph([['type' => 'text', 'text' => $label]])]];
        $document = ['type' => 'doc', 'content' => [
            $paragraph([['type' => 'clauseReference', 'attrs' => ['target' => 'final']]]),
            ['type' => 'conditional', 'attrs' => ['variableId' => $flag], 'content' => [$clause('optional', 'Условие')]],
            $clause('main', 'Работы'),
            ['type' => 'table', 'content' => [['type' => 'repeatRows', 'attrs' => ['variableId' => $table], 'content' => [
                ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [$paragraph([
                    ['type' => 'variable', 'attrs' => ['variableId' => $table, 'columnId' => 'work']],
                ])]]]],
            ]]]],
            $clause('final', 'Оплата'), $paragraph([$variable($money), $variable($text)]),
            $paragraph([['type' => 'text', 'text' => 'Ссылка', 'marks' => [['type' => 'bold'], ['type' => 'link', 'attrs' => ['href' => 'https://example.com/?a=1&b=2']]]]]),
        ]];
        $values = [
            $flag => false, $money => ['amount' => '99999999999999999999.01', 'currency' => 'RUB'],
            $text => '<img src=x onerror=alert(1)>',
            $table => [['id' => 'a', 'values' => ['work' => 'Монтаж']], ['id' => 'b', 'values' => ['work' => 'Пуск & наладка']]],
        ];
        $html = $renderer->render($document, $definitions, $values);
        self::assertStringContainsString('<a href="#clause-final">2</a>', $html);
        self::assertStringNotContainsString('Условие', $html);
        self::assertSame(2, substr_count($html, '<tr>'));
        self::assertStringContainsString('Пуск &amp; наладка', $html);
        self::assertStringContainsString('99999999999999999999.01', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('href="https://example.com/?a=1&amp;b=2"', $html);
        self::assertStringContainsString('<strong>Ссылка</strong>', $html);
        $values[$flag] = true;
        self::assertStringContainsString('<a href="#clause-final">3</a>', $renderer->render($document, $definitions, $values));
        $values[$flag] = false;
        $document['content'][0]['content'][0]['attrs']['target'] = 'optional';
        try {
            $renderer->render($document, $definitions, $values);
            self::fail('A link to an omitted clause must not display an incorrect number');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
    }

    public function test_block_resolver_rejects_cycles_and_conflicting_variable_versions(): void
    {
        $resolver = new \App\Services\Contract\ContractDocumentResolver;
        $blockId = (string) Str::uuid();
        $variableId = (string) Str::uuid();
        $reference = fn (int $version, string $instance): array => ['type' => 'blockReference', 'attrs' => ['blockId' => $blockId, 'version' => $version, 'instanceId' => $instance]];
        $cyclic = ['document' => ['type' => 'doc', 'content' => [$reference(1, 'loop')]], 'variables' => []];
        try {
            $resolver->resolve($cyclic, fn (): array => ['id' => 1, 'title' => 'Цикл', 'content' => $cyclic]);
            self::fail('Cyclic blocks must be rejected');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        $conflict = ['document' => ['type' => 'doc', 'content' => [$reference(1, 'first'), $reference(2, 'second')]], 'variables' => []];
        try {
            $resolver->resolve($conflict, fn (string $id, int $number): array => ['id' => $number, 'title' => 'Блок', 'content' => [
                'document' => ['type' => 'doc', 'content' => []], 'variables' => [$variableId => $number],
            ]]);
            self::fail('One variable cannot use conflicting definitions in a revision');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
    }

    public function test_publication_returns_requested_template_version_with_older_variable_version(): void
    {
        $owner = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $library = new \App\Services\Contract\ContractLibraryService($authorization);
        $variable = $library->create($actor, $owner->id, 'variable', 'Поле', ['type' => 'text'], 'field');
        $library->publish($actor, $owner->id, $variable['item']['id'], 1, 1);
        $content = ['document' => ['type' => 'doc', 'content' => []], 'variables' => [$variable['item']['id'] => 1]];
        $template = $library->create($actor, $owner->id, 'template', 'Шаблон', $content, 'template');
        $library->revise($actor, $owner->id, $template['item']['id'], 1, 'Вторая версия', $content, 'second');
        $published = $library->publish($actor, $owner->id, $template['item']['id'], 2, 2);
        self::assertSame(2, $published['version']['version_number']);
        self::assertSame('published', $published['version']['status']);
        self::assertSame('draft', $library->read($actor, $owner->id, $template['item']['id'], 1)['version']['status']);
    }

    public function test_document_schema_checks_references_conditions_repeated_rows_and_formatting(): void
    {
        $validator = new \App\Services\Contract\ContractDocumentValidator;
        $flag = (string) Str::uuid();
        $rows = (string) Str::uuid();
        $definitions = [
            $flag => ['type' => 'boolean'],
            $rows => ['type' => 'table', 'columns' => [['id' => 'work', 'label' => 'Работа', 'definition' => ['type' => 'text']]]],
        ];
        $paragraph = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Работы', 'marks' => [['type' => 'bold']]]]];
        $row = ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $rows, 'columnId' => 'work']]]],
        ]]]];
        $document = ['document' => ['type' => 'doc', 'content' => [
            ['type' => 'clause', 'attrs' => ['id' => 'scope'], 'content' => [$paragraph]],
            ['type' => 'paragraph', 'content' => [['type' => 'clauseReference', 'attrs' => ['target' => 'scope']]]],
            ['type' => 'conditional', 'attrs' => ['variableId' => $flag], 'content' => [$paragraph]],
            ['type' => 'table', 'content' => [['type' => 'repeatRows', 'attrs' => ['variableId' => $rows], 'content' => [$row]]]],
        ]], 'variables' => [$flag => 1, $rows => 1]];
        $validator->validate($document, $definitions);
        self::assertTrue(true);
        $invalid = [];
        $copy = $document;
        $copy['document']['content'][1]['content'][0]['attrs']['target'] = 'missing';
        $invalid[] = $copy;
        $copy = $document;
        $copy['document']['content'][] = $copy['document']['content'][0];
        $invalid[] = $copy;
        $copy = $document;
        $copy['document']['content'][2]['attrs']['variableId'] = $rows;
        $invalid[] = $copy;
        $copy = $document;
        $copy['document']['content'][3]['content'][0]['attrs']['variableId'] = $flag;
        $invalid[] = $copy;
        $copy = $document;
        $copy['document']['content'][3]['content'][0]['content'][0]['content'][0]['content'][0]['content'][0]['attrs']['columnId'] = 'missing';
        $invalid[] = $copy;
        foreach ([['type' => 'html', 'html' => '<script>bad()</script>'], ['type' => 'text', 'text' => 'Wrong parent']] as $node) {
            $copy = $document;
            $copy['document']['content'][] = $node;
            $invalid[] = $copy;
        }
        $copy = $document;
        $copy['document']['content'][0]['content'][0]['content'][0]['marks'] = [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]];
        $invalid[] = $copy;
        $copy = $document;
        $copy['variables'] = ['not-a-uuid' => 1];
        $invalid[] = $copy;
        foreach ($invalid as $content) {
            try {
                $validator->validate($content, $definitions);
                self::fail('Invalid document must be rejected');
            } catch (\App\Exceptions\BusinessLogicException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
        $large = ['document' => ['type' => 'doc', 'content' => []], 'variables' => []];
        foreach (range(1, 500) as $index) {
            $id = (string) Str::uuid();
            $large['variables'][$id] = 1;
            $large['document']['content'][] = ['type' => 'paragraph', 'content' => [
                ['type' => 'variable', 'attrs' => ['variableId' => $id]], ['type' => 'variable', 'attrs' => ['variableId' => $id]],
            ]];
        }
        $validator->validate($large);
        self::assertCount(500, $large['variables']);
    }

    public function test_publication_rejects_variable_versions_from_another_organization(): void
    {
        $owner = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $foreignActor = User::factory()->create(['current_organization_id' => $foreign->id]);
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $library = new \App\Services\Contract\ContractLibraryService($authorization);
        $variable = $library->create($foreignActor, $foreign->id, 'variable', 'Чужая переменная', ['type' => 'text'], 'variable');
        $library->publish($foreignActor, $foreign->id, $variable['item']['id'], 1, 1);
        $template = $library->create($actor, $owner->id, 'template', 'Шаблон', [
            'document' => ['type' => 'doc', 'content' => []], 'variables' => [$variable['item']['id'] => 1],
        ], 'template');
        try {
            $library->publish($actor, $owner->id, $template['item']['id'], 1, 1);
            self::fail('Cross-organization definitions must not be published');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $saved = $library->read($actor, $owner->id, $template['item']['id'], 1);
            self::assertSame('draft', $saved['version']['status']);
            self::assertSame(1, $saved['item']['lock_version']);
        }
    }

    public function test_instance_pins_library_and_values_and_is_shared_without_library_access(): void
    {
        $owner = Organization::factory()->create();
        $executor = Organization::factory()->create();
        $outsider = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $otherActor = User::factory()->create(['current_organization_id' => $executor->id]);
        $foreignActor = User::factory()->create(['current_organization_id' => $outsider->id]);
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $library = new \App\Services\Contract\ContractLibraryService($authorization);
        $views = new \App\Services\Contract\ContractOrganizationViewService($authorization);
        $service = new \App\Services\Contract\ContractBuilderInstanceService($authorization, $views, $library, new \App\Services\Contract\ContractVariableValueValidator);
        $project = \App\Models\Project::factory()->create(['organization_id' => $outsider->id]);
        $contractor = \App\Models\Contractor::create(['organization_id' => $owner->id, 'source_organization_id' => $executor->id, 'name' => 'Исполнитель']);
        $contract = \App\Models\Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
            'number' => 'BUILDER-1', 'date' => '2026-09-19', 'status' => 'draft', 'total_amount' => 100, 'contract_side_type' => 'subcontract',
        ]);
        $contract->parties()->create(['side' => 'first', 'role' => 'contractor', 'linked_organization_id' => $owner->id, 'name' => 'Сохранённый подрядчик', 'snapshot' => []]);
        $contract->parties()->create(['side' => 'second', 'role' => 'subcontractor', 'linked_organization_id' => $executor->id, 'name' => 'Сохранённый исполнитель', 'snapshot' => []]);
        $views->synchronizeNewContract($contract);
        $variable = $library->create($actor, $owner->id, 'variable', 'Стоимость', ['type' => 'money', 'required' => true], 'price');
        $library->publish($actor, $owner->id, $variable['item']['id'], 1, 1);
        $variableId = $variable['item']['id'];
        $blockContent = ['document' => ['type' => 'doc', 'content' => [
            ['type' => 'clause', 'attrs' => ['id' => 'price'], 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $variableId]]]],
            ]],
            ['type' => 'paragraph', 'content' => [['type' => 'clauseReference', 'attrs' => ['target' => 'price']]]],
        ]], 'variables' => [$variableId => 1]];
        $block = $library->create($actor, $owner->id, 'block', 'Цена', $blockContent, 'block');
        $library->publish($actor, $owner->id, $block['item']['id'], 1, 1);
        $content = ['document' => ['type' => 'doc', 'content' => [
            ['type' => 'blockReference', 'attrs' => ['blockId' => $block['item']['id'], 'version' => 1, 'instanceId' => 'first']],
            ['type' => 'blockReference', 'attrs' => ['blockId' => $block['item']['id'], 'version' => 1, 'instanceId' => 'second']],
        ]], 'variables' => []];
        $template = $library->create($actor, $owner->id, 'template', 'Субподряд', $content, 'template');
        try {
            $service->create($actor, $owner->id, $contract->id, $template['item']['id'], 1, [], 'draft');
            self::fail('Unpublished template must not create an instance');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame(0, DB::table('contract_builder_instances')->where('contract_id', $contract->id)->count());
        }
        $library->publish($actor, $owner->id, $template['item']['id'], 1, 1);
        try {
            $service->create($actor, $owner->id, $contract->id, $template['item']['id'], 1, [], 'missing-value');
            self::fail('Missing required value must not create an instance');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame(0, DB::table('contract_builder_instances')->where('contract_id', $contract->id)->count());
        }
        $values = [$variableId => ['amount' => '100.01', 'currency' => 'RUB']];
        $created = $service->create($actor, $owner->id, $contract->id, $template['item']['id'], 1, $values, 'create');
        self::assertSame($created, $service->create($actor, $owner->id, $contract->id, $template['item']['id'], 1, [$variableId => ['currency' => 'RUB', 'amount' => '100.01']], 'create'));
        self::assertSame($created, $service->read($otherActor, $executor->id, $contract->id, 1));
        self::assertSame('Стоимость', $created['definitions'][$variableId]['title']);
        self::assertCount(2, $created['blocks']);
        self::assertSame($blockContent, $created['blocks']['first']['content']);
        self::assertSame('first__price', $created['document']['content'][0]['attrs']['id']);
        self::assertSame('first__price', $created['document']['content'][1]['content'][0]['attrs']['target']);
        self::assertSame('second__price', $created['document']['content'][2]['attrs']['id']);
        self::assertSame($values, $created['values']);
        self::assertSame('Сохранённый подрядчик', $created['parties'][0]['name']);
        self::assertArrayNotHasKey('request_key', $created);
        $preview = $service->preview($otherActor, $executor->id, $contract->id, 1);
        self::assertSame($created, $preview['revision']);
        self::assertStringContainsString('href="#clause-second__price">2</a>', $preview['html']);
        $library->revise($actor, $owner->id, $variableId, 2, 'Цена после изменения', ['type' => 'number'], 'rename');
        $library->revise($actor, $owner->id, $block['item']['id'], 2, 'Другой блок', ['document' => ['type' => 'doc', 'content' => []], 'variables' => []], 'changed-block');
        $library->archive($actor, $owner->id, $block['item']['id'], 3, true);
        $library->archive($actor, $owner->id, $template['item']['id'], 2, true);
        self::assertSame($created, $service->read($otherActor, $executor->id, $contract->id, 1));
        self::assertSame($preview, $service->preview($otherActor, $executor->id, $contract->id, 1));
        self::assertSame($created, $service->create($actor, $owner->id, $contract->id, $template['item']['id'], 1, $values, 'create'));
        try {
            $service->create($actor, $owner->id, $contract->id, $template['item']['id'], 1, [], 'create');
            self::fail('Changed retry must not overwrite a revision');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        foreach ([fn () => $service->read($foreignActor, $outsider->id, $contract->id, 1), fn () => $library->read($otherActor, $executor->id, $template['item']['id'], 1)] as $read) {
            try {
                $read();
                self::fail('Project ownership or shared contract must not grant library or contract access');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                self::assertTrue(true);
            }
        }
        $denied = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $denied->shouldReceive('can')->andReturn(false);
        try {
            (new \App\Services\Contract\ContractBuilderInstanceService($denied, $views, $library, new \App\Services\Contract\ContractVariableValueValidator))
                ->create($actor, $owner->id, $contract->id, $template['item']['id'], 1, $values, 'create');
            self::fail('Replay also requires edit permission');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame(1, DB::table('contract_builder_instances')->where('contract_id', $contract->id)->count());
        }
    }

    public function test_revision_snapshots_are_immutable_and_cannot_cross_contracts(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $library = new \App\Services\Contract\ContractLibraryService($authorization);
        $template = $library->create($actor, $organization->id, 'template', 'Подряд', ['document' => ['type' => 'doc', 'content' => []], 'variables' => []], 'template');
        $library->publish($actor, $organization->id, $template['item']['id'], 1, 1);
        $project = \App\Models\Project::factory()->create(['organization_id' => $organization->id]);
        $contractor = \App\Models\Contractor::create(['organization_id' => $organization->id, 'name' => 'Исполнитель']);
        $instances = [];
        foreach (['REV-1', 'REV-2'] as $number) {
            $contract = \App\Models\Contract::create([
                'organization_id' => $organization->id, 'project_id' => $project->id,
                'contractor_id' => $contractor->id, 'number' => $number, 'date' => '2026-09-19',
                'status' => 'draft', 'total_amount' => 100, 'contract_side_type' => 'subcontract',
            ]);
            $instances[] = DB::table('contract_builder_instances')->insertGetId(['contract_id' => $contract->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $payload = [
            'instance_id' => $instances[0], 'revision_number' => 1,
            'template_version_id' => $template['version']['id'], 'author_organization_id' => $organization->id,
            'created_by' => $actor->id, 'document' => json_encode(['text' => 'Текст договора']),
            'definitions' => json_encode(['price' => ['type' => 'money']]),
            'values' => json_encode(['price' => ['amount' => '100.01', 'currency' => 'RUB']]),
            'parties' => json_encode([['name' => 'Заказчик'], ['name' => 'Исполнитель']]),
            'attachments' => '[]', 'content_hash' => hash('sha256', 'snapshot'),
            'request_key' => 'first', 'request_fingerprint' => hash('sha256', 'request'), 'created_at' => now(),
        ];
        $first = DB::table('contract_builder_revisions')->insertGetId($payload);
        $foreign = DB::table('contract_builder_revisions')->insertGetId(array_replace($payload, ['instance_id' => $instances[1]]));
        DB::table('contract_builder_instances')->where('id', $instances[0])->update(['current_revision_id' => $first]);
        $before = (array) DB::table('contract_builder_revisions')->find($first);
        $this->rejects(fn () => DB::table('contract_builder_revisions')->where('id', $first)->update(['document' => '{}']), 'P0001');
        $this->rejects(fn () => DB::table('contract_builder_revisions')->where('id', $first)->delete(), 'P0001');
        $this->rejects(fn () => DB::table('contract_builder_instances')->where('id', $instances[0])->update(['current_revision_id' => $foreign]), '23503');
        $this->rejects(fn () => DB::table('contract_builder_revisions')->insert(array_replace($payload, ['revision_number' => 2, 'base_revision_id' => $foreign, 'request_key' => 'next'])), '23503');
        $second = DB::table('contract_builder_revisions')->insertGetId(array_replace($payload, [
            'revision_number' => 2, 'base_revision_id' => $first, 'request_key' => 'next', 'document' => '{"text":"Changed"}',
        ]));
        DB::table('contract_builder_instances')->where('id', $instances[0])->update(['current_revision_id' => $second, 'lock_version' => 2]);
        $library->revise($actor, $organization->id, $template['item']['id'], 2, 'Новый шаблон', ['document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Другой текст']]]]], 'variables' => []], 'renamed');
        $library->archive($actor, $organization->id, $template['item']['id'], 3, true);
        self::assertSame($before, (array) DB::table('contract_builder_revisions')->find($first));
        self::assertSame($second, DB::table('contract_builder_instances')->where('id', $instances[0])->value('current_revision_id'));
        self::assertSame('100.01', json_decode($before['values'], true)['price']['amount']);
    }

    public function test_values_keep_types_currency_choice_identity_and_table_columns(): void
    {
        $validator = new \App\Services\Contract\ContractVariableValueValidator;
        $choice = ['type' => 'choice', 'options' => [['id' => 'a', 'label' => 'Название'], ['id' => 'b', 'label' => 'Название']]];
        $table = ['type' => 'table', 'columns' => [
            ['id' => 'quantity', 'label' => 'Сумма', 'definition' => ['type' => 'number', 'required' => true]],
            ['id' => 'price', 'label' => 'Сумма', 'definition' => ['type' => 'money', 'required' => true, 'constraints' => ['currency' => 'RUB']]],
        ]];
        $row = ['id' => 'row-1', 'values' => ['quantity' => '1.25', 'price' => ['amount' => '120.50', 'currency' => 'RUB']]];
        $valid = [
            [['type' => 'text'], 'Текст'], [['type' => 'boolean', 'required' => true], false],
            [['type' => 'number', 'constraints' => ['min' => '-1.01', 'max' => '-1']], '-1.005'],
            [['type' => 'percentage', 'constraints' => ['min' => 0, 'max' => 100]], '12.5'],
            [['type' => 'money'], ['amount' => '99999999999999999999.01', 'currency' => 'EUR']],
            [['type' => 'date'], '2024-02-29'], [$choice, 'b'], [$table, [$row]],
            [['type' => 'text'], null], [['type' => 'entity', 'entity_type' => 'project'], ['type' => 'project', 'id' => 7]],
        ];
        foreach ($valid as [$definition, $value]) {
            self::assertSame($value, $validator->validate($definition, $value, fn (string $type, int $id): bool => $type === 'project' && $id === 7));
        }
        $invalid = [
            [['type' => 'boolean'], 0], [['type' => 'text', 'required' => true], '  '],
            [['type' => 'number', 'required' => true], null], [['type' => 'number'], INF],
            [['type' => 'money'], ['amount' => 0.1, 'currency' => 'RUB']],
            [['type' => 'money', 'constraints' => ['currency' => 'RUB']], ['amount' => '1', 'currency' => 'USD']],
            [['type' => 'number', 'constraints' => ['scale' => 2]], '1.001'],
            [['type' => 'number', 'constraints' => ['max' => '99999999999999999999.01']], '99999999999999999999.02'],
            [['type' => 'date'], '2026-02-29'], [$choice, 'Название'], [$table, [$row, $row]],
            [$table, [['id' => 'row', 'values' => ['quantity' => 1]]]],
            [['type' => 'entity', 'entity_type' => 'project'], ['type' => 'project', 'id' => 8]],
        ];
        foreach ($invalid as [$definition, $value]) {
            try {
                $validator->validate($definition, $value);
                self::fail('Invalid or unauthorized values must be rejected');
            } catch (\App\Exceptions\BusinessLogicException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
    }

    public function test_typed_definitions_round_trip_and_reject_invalid_constraints(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $service = new \App\Services\Contract\ContractLibraryService($authorization);
        $definitions = [
            ['type' => 'text', 'constraints' => ['max_length' => 500]],
            ['type' => 'number', 'constraints' => ['min' => '-10.001', 'max' => '-10', 'scale' => 3]],
            ['type' => 'money', 'constraints' => ['currency' => 'RUB', 'min' => '99999999999999999999.01', 'max' => '99999999999999999999.02']],
            ['type' => 'percentage', 'constraints' => ['min' => 0, 'max' => 100]],
            ['type' => 'date', 'constraints' => ['min' => '2024-02-29', 'max' => '2026-09-19']],
            ['type' => 'boolean', 'required' => false],
            ['type' => 'choice', 'options' => [['id' => 'first', 'label' => 'Одинаково'], ['id' => 'second', 'label' => 'Одинаково']]],
            ['type' => 'entity', 'entity_type' => 'project'],
            ['type' => 'table', 'columns' => [['id' => 'amount', 'label' => 'Стоимость', 'definition' => ['type' => 'money']]]],
        ];
        foreach ($definitions as $index => $definition) {
            $definition += ['source' => ['kind' => 'manual'], 'display' => ['group' => 'Общие'], 'assignment' => null];
            $created = $service->create($actor, $organization->id, 'variable', 'Одинаковая подпись', $definition, 'type-'.$index);
            self::assertEquals($definition, $service->read($actor, $organization->id, $created['item']['id'], 1)['version']['content']);
        }
        $invalid = [
            ['type' => 'unknown'], ['type' => 'boolean', 'required' => null],
            ['type' => 'money', 'constraints' => ['currency' => 'rub']],
            ['type' => 'number', 'constraints' => ['min' => '99999999999999999999.02', 'max' => '99999999999999999999.01']],
            ['type' => 'number', 'constraints' => ['min' => '-10', 'max' => '-10.001']],
            ['type' => 'date', 'constraints' => ['min' => '2026-02-29']],
            ['type' => 'date', 'constraints' => ['min' => '2026-09-20', 'max' => '2026-09-19']],
            ['type' => 'choice', 'options' => [['id' => 'same', 'label' => 'A'], ['id' => 'same', 'label' => 'B']]],
            ['type' => 'table', 'columns' => [['id' => 'x', 'label' => 'X', 'definition' => ['type' => 'unknown']]]],
            ['type' => 'text', 'source' => ['kind' => 'url', 'url' => 'https://example.com']],
            ['type' => 'text', 'assignment' => ['target' => 'execute_code']],
        ];
        foreach ($invalid as $definition) {
            try {
                (new \App\Services\Contract\ContractVariableDefinitionValidator)->validate($definition);
                self::fail('Invalid definition must be rejected');
            } catch (\App\Exceptions\BusinessLogicException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
    }

    public function test_library_service_preserves_versions_replays_and_organization_boundaries(): void
    {
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $service = new \App\Services\Contract\ContractLibraryService($authorization);
        $content = ['type' => 'money', 'assignment' => null];
        $created = $service->create($actor, $organization->id, 'variable', 'Цена', $content, 'create-price');
        $itemId = $created['item']['id'];
        self::assertSame($created, $service->create($actor, $organization->id, 'variable', 'Цена', $content, 'create-price'));
        $published = $service->publish($actor, $organization->id, $itemId, 1, 1);
        self::assertSame('published', $published['version']['status']);
        self::assertSame($published, $service->publish($actor, $organization->id, $itemId, 1, 1));
        try {
            $service->revise($actor, $organization->id, $itemId, 1, 'Подмена', $content, 'stale');
            self::fail('Stale library edits must fail');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        $revised = $service->revise($actor, $organization->id, $itemId, 2, 'Стоимость', $content, 'rename');
        self::assertSame(2, $revised['version']['version_number']);
        self::assertSame($revised, $service->revise($actor, $organization->id, $itemId, 2, 'Стоимость', $content, 'rename'));
        self::assertSame($published['version'], $service->read($actor, $organization->id, $itemId, 1)['version']);
        $archived = $service->archive($actor, $organization->id, $itemId, 3, true);
        self::assertTrue($archived['is_archived']);
        self::assertSame($archived, $service->archive($actor, $organization->id, $itemId, 3, true));
        self::assertSame($published['version'], $service->read($actor, $organization->id, $itemId, 1)['version']);
        $denied = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $denied->shouldReceive('can')->with($actor, 'contracts.library.publish', ['organization_id' => $organization->id])->andReturn(false);
        try {
            (new \App\Services\Contract\ContractLibraryService($denied))->publish($actor, $organization->id, $itemId, 2, 4);
            self::fail('Publication requires its own permission');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame('draft', $service->read($actor, $organization->id, $itemId, 2)['version']['status']);
        }
        self::assertSame('Публикация версий библиотеки договоров', \App\Helpers\PermissionTranslator::getPermissionTranslation('contracts.library.publish', 'contract-management'));
        $foreignActor = User::factory()->create(['current_organization_id' => $foreign->id]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->read($foreignActor, $foreign->id, $itemId, 1);
    }

    public function test_versions_are_tenant_bound_and_published_content_is_immutable(): void
    {
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $actor = User::factory()->create();
        $itemId = (string) Str::uuid();
        DB::table('contract_library_items')->insert([
            'id' => $itemId, 'organization_id' => $organization->id, 'kind' => 'variable',
            'creation_key' => 'price', 'creation_fingerprint' => hash('sha256', 'price'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload = [
            'item_id' => $itemId, 'organization_id' => $organization->id, 'version_number' => 1,
            'title' => 'Цена', 'content' => json_encode(['type' => 'money', 'assignment' => null]),
            'created_by' => $actor->id, 'created_at' => now(),
            'request_key' => 'v1', 'request_fingerprint' => hash('sha256', 'v1'),
        ];
        $versionId = DB::table('contract_library_versions')->insertGetId($payload);
        $this->rejects(fn () => DB::table('contract_library_versions')->insert([
            ...$payload, 'organization_id' => $foreign->id, 'version_number' => 2, 'request_key' => 'foreign',
        ]), '23503');
        DB::table('contract_library_versions')->where('id', $versionId)->update([
            'status' => 'published', 'published_by' => $actor->id, 'published_at' => now(),
        ]);
        $published = (array) DB::table('contract_library_versions')->find($versionId);
        $this->rejects(fn () => DB::table('contract_library_versions')->where('id', $versionId)->update(['title' => 'Подмена']), 'P0001');
        $this->rejects(fn () => DB::table('contract_library_versions')->where('id', $versionId)->delete(), 'P0001');
        $this->rejects(fn () => DB::table('contract_library_items')->where('id', $itemId)->update(['organization_id' => $foreign->id]), 'P0001');
        $second = DB::table('contract_library_versions')->insertGetId([
            ...$payload, 'version_number' => 2, 'title' => 'Стоимость работ', 'request_key' => 'v2',
        ]);
        DB::table('contract_library_items')->where('id', $itemId)->update(['is_archived' => true, 'lock_version' => 2]);
        self::assertSame($published, (array) DB::table('contract_library_versions')->find($versionId));
        self::assertSame($itemId, DB::table('contract_library_versions')->where('id', $second)->value('item_id'));
        self::assertSame(2, DB::table('contract_library_versions')->where('item_id', $itemId)->count());
    }

    private function rejects(callable $action, string $sqlState): void
    {
        try {
            DB::transaction($action);
            self::fail('Storage must reject the invalid mutation');
        } catch (QueryException $exception) {
            self::assertSame($sqlState, (string) $exception->getCode());
        }
    }
}
