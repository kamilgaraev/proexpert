<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Exceptions\ContractBuilderException;
use App\Services\Contract\ContractFormulaEngine;
use Tests\TestCase;

final class ContractFormulaEngineTest extends TestCase
{
    public function test_calculates_five_hundred_dependent_fields_exactly(): void
    {
        $definitions = [self::id(0) => ['type' => 'number', 'required' => true]];
        for ($index = 1; $index < 500; $index++) {
            $definitions[self::id($index)] = self::formula(['kind' => 'operation', 'operator' => 'add', 'args' => [
                ['kind' => 'reference', 'variable_id' => self::id($index - 1)],
                ['kind' => 'literal', 'type' => 'number', 'value' => '0.01'],
            ]]);
        }
        $result = (new ContractFormulaEngine)->calculate(array_reverse($definitions, true), [self::id(0) => '100000000000000000.01']);
        self::assertCount(500, $result);
        self::assertSame('100000000000000005', $result[self::id(499)]);
    }

    public function test_rejects_forged_computed_value_even_when_null(): void
    {
        $this->expectException(ContractBuilderException::class);
        $this->expectExceptionMessage(trans_message('contracts.formula_readonly'));
        (new ContractFormulaEngine)->calculate([self::id(0) => self::formula(['kind' => 'literal', 'type' => 'number', 'value' => '1'])], [self::id(0) => null]);
    }

    public function test_final_definition_constraints_are_enforced(): void
    {
        $this->expectException(ContractBuilderException::class);
        $definition = self::formula(['kind' => 'literal', 'type' => 'number', 'value' => '11']);
        $definition['constraints'] = ['max' => '10'];
        (new ContractFormulaEngine)->calculate([self::id(0) => $definition], []);
    }

    public function test_manual_fields_are_validated_even_when_formula_does_not_use_them(): void
    {
        $this->expectException(ContractBuilderException::class);
        (new ContractFormulaEngine)->calculate([
            self::id(0) => ['type' => 'number', 'required' => true],
            self::id(1) => self::formula(['kind' => 'literal', 'type' => 'number', 'value' => '1']),
        ], []);
    }

    public function test_computation_budget_is_shared_between_all_formulas(): void
    {
        $definitions = [self::id(0) => ['type' => 'table', 'columns' => [
            ['id' => 'amount', 'label' => 'Сумма', 'definition' => ['type' => 'number']],
        ]]];
        for ($index = 1; $index <= 11; $index++) {
            $definitions[self::id($index)] = self::formula(['kind' => 'sum', 'variable_id' => self::id(0), 'column_id' => 'amount']);
        }
        $rows = [];
        for ($index = 0; $index < 10000; $index++) {
            $rows[] = ['id' => 'row'.$index, 'values' => ['amount' => '1']];
        }
        $this->expectException(ContractBuilderException::class);
        $this->expectExceptionMessage(trans_message('contracts.formula_limit'));
        (new ContractFormulaEngine)->calculate($definitions, [self::id(0) => $rows]);
    }

    public function test_unknown_inputs_and_nested_formulas_are_rejected(): void
    {
        $engine = new ContractFormulaEngine;
        $formula = self::formula(['kind' => 'literal', 'type' => 'number', 'value' => '1']);
        foreach ([
            fn () => $engine->calculate([], [self::id(0) => '1']),
            fn () => $engine->validate([self::id(0) => ['type' => 'table', 'columns' => [['id' => 'x', 'label' => 'X', 'definition' => $formula]]]]),
        ] as $action) {
            try {
                $action();
                self::fail('Invalid input must fail');
            } catch (ContractBuilderException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
    }

    private static function formula(array $expression): array
    {
        return ['type' => 'number', 'source' => ['kind' => 'formula', 'expression' => $expression]];
    }

    private static function id(int $index): string
    {
        return sprintf('5942e1c0-4aae-4ad5-b94e-%012d', $index);
    }
}
