<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Exceptions\ContractBuilderException;
use App\Services\Contract\ContractFormulaDependencies;
use Tests\TestCase;

final class ContractFormulaDependenciesTest extends TestCase
{
    public function test_five_hundred_reverse_ordered_dependencies_are_sorted_without_recursion(): void
    {
        $definitions = [];
        $expected = [];
        for ($index = 0; $index < 500; $index++) {
            $id = self::id($index);
            $expected[] = $id;
            $definitions[$id] = $index === 0 ? ['type' => 'number'] : self::formula(self::reference($index - 1));
        }
        self::assertSame($expected, (new ContractFormulaDependencies)->order(array_reverse($definitions, true)));
        self::assertSame([], (new ContractFormulaDependencies)->order([]));
    }

    public function test_repeated_references_count_as_one_dependency(): void
    {
        $formula = self::formula(['kind' => 'operation', 'operator' => 'add', 'args' => [self::reference(0), self::reference(0)]]);
        self::assertSame([self::id(0), self::id(1)], (new ContractFormulaDependencies)->order([
            self::id(1) => $formula,
            self::id(0) => ['type' => 'number'],
        ]));
    }

    public function test_cycle_in_unused_branch_is_rejected(): void
    {
        $this->expectException(ContractBuilderException::class);
        $this->expectExceptionMessage(trans_message('contracts.formula_cycle'));
        (new ContractFormulaDependencies)->order([
            self::id(0) => self::formula(['kind' => 'operation', 'operator' => 'if', 'args' => [
                ['kind' => 'literal', 'type' => 'boolean', 'value' => true],
                ['kind' => 'literal', 'type' => 'number', 'value' => '1'],
                self::reference(1),
            ]]),
            self::id(1) => self::formula(self::reference(0)),
        ]);
    }

    public function test_self_reference_is_rejected(): void
    {
        $this->expectException(ContractBuilderException::class);
        $this->expectExceptionMessage(trans_message('contracts.formula_cycle'));
        (new ContractFormulaDependencies)->order([self::id(0) => self::formula(self::reference(0))]);
    }

    public function test_unknown_table_dependency_is_rejected(): void
    {
        $this->expectException(ContractBuilderException::class);
        $this->expectExceptionMessage(trans_message('contracts.formula_missing'));
        (new ContractFormulaDependencies)->order([self::id(0) => self::formula([
            'kind' => 'sum', 'variable_id' => self::id(1), 'column_id' => 'price',
        ])]);
    }

    public function test_limit_is_checked_before_processing_definitions(): void
    {
        $this->expectException(ContractBuilderException::class);
        $this->expectExceptionMessage(trans_message('contracts.formula_limit'));
        (new ContractFormulaDependencies)->order(array_fill(0, 501, []));
    }

    private static function id(int $index): string
    {
        return sprintf('5942e1c0-4aae-4ad5-b94e-%012d', $index);
    }

    private static function reference(int $index): array
    {
        return ['kind' => 'reference', 'variable_id' => self::id($index)];
    }

    private static function formula(array $expression): array
    {
        return ['type' => 'number', 'source' => ['kind' => 'formula', 'expression' => $expression]];
    }
}
