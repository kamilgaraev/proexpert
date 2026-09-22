<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Exceptions\ContractBuilderException;
use App\Services\Contract\ContractRevisionTermsCompiler;
use App\Services\Contract\ContractVariableDefinitionValidator;
use Tests\TestCase;

final class ContractRevisionTermsCompilerTest extends TestCase
{
    public function test_compiles_stable_assignments_and_clause_bases_without_creating_facts(): void
    {
        $revision = $this->revision();
        $result = (new ContractRevisionTermsCompiler)->compile($revision);
        self::assertSame('120.00', $result['terms']['total_amount']);
        self::assertSame('20.00', $result['terms']['planned_advance_amount']);
        self::assertSame('RUB', $result['terms']['currency']);
        self::assertSame('2026-09-20', $result['terms']['start_date']);
        self::assertSame('2026-10-20', $result['terms']['end_date']);
        self::assertSame('5.000', $result['terms']['warranty_retention_percentage']);
        self::assertSame('clause-0', $result['bases']['price']['clause_ids'][0]);
        self::assertSame(self::id(0), $result['bases']['price']['variable_id']);
        self::assertSame('same-label-row-1', $result['works'][0]['id']);
        self::assertSame('same-label-row-2', $result['works'][1]['id']);
        self::assertSame('0.03', $result['works'][0]['amount']);
        self::assertSame('2.00', $result['works'][1]['amount']);
        self::assertArrayNotHasKey('actual_advance_amount', $result['terms']);
        self::assertArrayNotHasKey('paid_amount', $result['terms']);
        self::assertArrayNotHasKey('completed_quantity', $result['works'][0]);
    }

    public function test_hidden_condition_does_not_apply_a_term_and_unassigned_fields_remain_valid(): void
    {
        $revision = $this->revision();
        $revision['definitions'][] = ['id' => self::id(8), 'version' => 1, 'definition' => ['type' => 'boolean']];
        $revision['values'][self::id(8)] = false;
        $revision['document']['content'][0] = ['type' => 'conditional', 'attrs' => ['variableId' => self::id(8)], 'content' => [$revision['document']['content'][0]]];
        $result = (new ContractRevisionTermsCompiler)->compile($revision);
        self::assertArrayNotHasKey('total_amount', $result['terms']);
        self::assertArrayNotHasKey('price', $result['bases']);
    }

    public function test_invalid_or_ambiguous_module_assignments_cannot_be_applied(): void
    {
        $mutations = [
            static function (array &$r): void { $r['values'][self::id(1)]['currency'] = 'USD'; },
            static function (array &$r): void { $r['values'][self::id(1)]['amount'] = '121'; },
            static function (array &$r): void { $r['values'][self::id(3)] = '2026-09-19'; },
            static function (array &$r): void { $r['values'][self::id(4)] = '101'; },
            static function (array &$r): void { $r['values'][self::id(0)]['amount'] = '0.001'; },
            static function (array &$r): void { $r['values'][self::id(0)]['amount'] = '-1'; },
            static function (array &$r): void { $r['definitions'][1]['definition']['assignment']['target'] = 'price'; },
            static function (array &$r): void { unset($r['definitions'][2]['definition']['assignment']['field']); },
            static function (array &$r): void { unset($r['definitions'][5]['definition']['assignment']['columns']); },
            static function (array &$r): void { $r['document']['content'][0] = $r['document']['content'][0]['content'][0]; },
            static function (array &$r): void { array_shift($r['document']['content']); },
            static function (array &$r): void { $r['values'][self::id(5)][0]['values']['q'] = null; },
            static function (array &$r): void {
                foreach ($r['values'][self::id(5)] as &$row) {
                    $row['values']['q'] = '1';
                    $row['values']['p']['amount'] = '999999999999.99';
                }
            },
        ];
        foreach ($mutations as $index => $mutate) {
            $revision = $this->revision();
            $mutate($revision);
            try {
                (new ContractRevisionTermsCompiler)->compile($revision);
                self::fail('Invalid assignment accepted: '.$index);
            } catch (ContractBuilderException $exception) {
                self::assertSame(422, $exception->getCode());
                self::assertStringStartsWith('contracts.revision_terms_invalid_', $exception->messageKey());
                self::assertStringNotContainsString(self::id(0), $exception->getMessage());
            }
        }
    }

    public function test_mapping_uses_column_ids_and_checks_column_types(): void
    {
        $definition = $this->revision()['definitions'][5]['definition'];
        (new ContractVariableDefinitionValidator)->validate($definition);
        $definition['assignment']['columns']['price'] = 'q';
        $this->expectException(ContractBuilderException::class);
        (new ContractVariableDefinitionValidator)->validate($definition);
    }

    private function revision(): array
    {
        $definitions = [
            ['type' => 'money', 'assignment' => ['target' => 'price']],
            ['type' => 'money', 'assignment' => ['target' => 'advance']],
            ['type' => 'date', 'assignment' => ['target' => 'schedule', 'field' => 'start_date']],
            ['type' => 'date', 'assignment' => ['target' => 'schedule', 'field' => 'end_date']],
            ['type' => 'percentage', 'assignment' => ['target' => 'retention']],
            ['type' => 'table', 'columns' => [
                ['id' => 'n', 'label' => 'Поле', 'definition' => ['type' => 'text']],
                ['id' => 'u', 'label' => 'Поле', 'definition' => ['type' => 'text']],
                ['id' => 'q', 'label' => 'Поле', 'definition' => ['type' => 'number']],
                ['id' => 'p', 'label' => 'Поле', 'definition' => ['type' => 'money']],
            ], 'assignment' => ['target' => 'works', 'columns' => ['name' => 'n', 'unit' => 'u', 'quantity' => 'q', 'price' => 'p']]],
            ['type' => 'text'],
        ];
        $values = [
            ['amount' => '120', 'currency' => 'RUB'], ['amount' => '20', 'currency' => 'RUB'],
            '2026-09-20', '2026-10-20', '5', [
                ['id' => 'same-label-row-1', 'values' => ['n' => 'Работа', 'u' => 'м', 'q' => '0.5', 'p' => ['amount' => '0.05', 'currency' => 'RUB']]],
                ['id' => 'same-label-row-2', 'values' => ['n' => 'Работа', 'u' => 'м', 'q' => '2', 'p' => ['amount' => '1', 'currency' => 'RUB']]],
            ], 'Внутри текста без назначения',
        ];
        $revision = ['document' => ['type' => 'doc', 'content' => []], 'definitions' => [], 'values' => []];
        foreach ($definitions as $index => $definition) {
            $id = self::id($index);
            $revision['definitions'][] = ['id' => $id, 'version' => 1, 'definition' => $definition];
            $revision['values'][$id] = $values[$index];
            $revision['document']['content'][] = ['type' => 'clause', 'attrs' => ['id' => 'clause-'.$index], 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $id]]]],
            ]];
            if ($definition['type'] === 'table') {
                $revision['document']['content'][$index]['content'] = [['type' => 'table', 'content' => [
                    ['type' => 'repeatRows', 'attrs' => ['variableId' => $id], 'content' => [
                        ['type' => 'tableRow', 'content' => array_map(static fn (string $column): array => [
                            'type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [
                                ['type' => 'variable', 'attrs' => ['variableId' => $id, 'columnId' => $column]],
                            ]]],
                        ], ['n', 'u', 'q', 'p'])],
                    ]],
                ]]];
            }
        }

        return $revision;
    }

    private static function id(int $index): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $index);
    }
}
