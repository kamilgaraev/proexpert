<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Exceptions\ContractBuilderException;
use App\Services\Contract\ContractDocumentRenderer;
use Tests\TestCase;

final class ContractEntityRenderingTest extends TestCase
{
    public function test_entity_labels_are_frozen_escaped_and_shared_by_repeated_insertions(): void
    {
        [$document, $definitions, $values] = $this->fixture();
        $snapshots = ['project:17' => ['label' => 'Проект <старое название> & партнёры']];
        $html = (new ContractDocumentRenderer)->render($document, $definitions, $values, $snapshots);

        self::assertSame(2, substr_count($html, 'Проект &lt;старое название&gt; &amp; партнёры'));
        self::assertStringNotContainsString('<старое название>', $html);
        self::assertStringNotContainsString('project:17', $html);
    }

    public function test_missing_mismatched_or_invalid_snapshots_are_rejected(): void
    {
        [$document, $definitions, $values] = $this->fixture();
        foreach ([[], ['contract:17' => ['label' => 'Чужой тип']], ['project:18' => ['label' => 'Другой проект']],
            ['project:17' => ['label' => ' ']], ['project:17' => ['label' => 17]],
            ['project:17' => ['label' => str_repeat('я', 1001)]]] as $snapshots) {
            try {
                (new ContractDocumentRenderer)->render($document, $definitions, $values, $snapshots);
                self::fail('An entity requires its own valid frozen label');
            } catch (ContractBuilderException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
    }

    public function test_five_hundred_dependent_values_render_twice_without_identity_loss(): void
    {
        $types = $definitions = $nodes = [];
        $first = $last = '';
        for ($index = 0; $index < 500; $index++) {
            $id = sprintf('11111111-1111-4111-8111-%012d', $index);
            if ($index === 0) {
                $first = $id;
                $definition = ['type' => 'number', 'required' => true];
            } else {
                $definition = ['type' => 'number', 'source' => ['kind' => 'formula', 'expression' => [
                    'kind' => 'operation', 'operator' => 'add', 'args' => [
                        ['kind' => 'reference', 'variable_id' => $last], ['kind' => 'literal', 'type' => 'number', 'value' => '1'],
                    ],
                ]]];
            }
            $types[$id] = $definition;
            $definitions[$id] = ['id' => $id, 'version' => 1, 'definition' => $definition];
            $node = ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $id]]]];
            $nodes[] = $node;
            $nodes[] = $node;
            $last = $id;
        }
        $values = (new \App\Services\Contract\ContractFormulaEngine)->calculate(array_reverse($types, true), [$first => '0']);
        $html = (new ContractDocumentRenderer)->render(['type' => 'doc', 'content' => $nodes], $definitions, $values);
        self::assertCount(500, $values);
        self::assertSame('499', $values[$last]);
        self::assertSame(1000, substr_count($html, '<p>'));
        self::assertSame(2, substr_count($html, '<p>499</p>'));
        self::assertSame(2, substr_count($html, '<p>0</p>'));
    }

    private function fixture(): array
    {
        $entityId = '11111111-1111-4111-8111-111111111111';
        $tableId = '22222222-2222-4222-8222-222222222222';
        $entity = ['type' => 'entity', 'entity_type' => 'project', 'required' => true];
        $definitions = [
            $entityId => ['id' => $entityId, 'version' => 1, 'definition' => $entity],
            $tableId => ['id' => $tableId, 'version' => 1, 'definition' => ['type' => 'table', 'columns' => [
                ['id' => 'project', 'label' => 'Проект', 'definition' => $entity],
            ]]],
        ];
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $entityId]]]],
            ['type' => 'table', 'content' => [['type' => 'repeatRows', 'attrs' => ['variableId' => $tableId], 'content' => [
                ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $tableId, 'columnId' => 'project']]]],
                ]]]],
            ]]]],
        ]];
        $reference = ['type' => 'project', 'id' => 17];

        return [$document, $definitions, [$entityId => $reference, $tableId => [['id' => 'row1', 'values' => ['project' => $reference]]]]];
    }
}
