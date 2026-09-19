<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Exceptions\ContractBuilderException;
use App\Services\Contract\ContractFormulaEvaluator;
use App\Services\Contract\ContractFormulaExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ContractFormulaEvaluatorTest extends TestCase
{
    private const FIELD = '5942e1c0-4aae-4ad5-b94e-0526966b9068';

    public function test_exact_arithmetic_rounding_conditions_comparison_and_calendar_operations(): void
    {
        $engine = new ContractFormulaEvaluator;
        $none = static fn (string $id): null => null;
        $number = static fn (string $value): array => self::literal('number', $value);
        $money = static fn (string $value): array => self::literal('money', ['amount' => $value, 'currency' => 'RUB']);
        $cases = [
            [self::operation('add', [$number('999999999999999999.99'), $number('0.01')]), ['type' => 'number', 'value' => '1000000000000000000']],
            [self::operation('subtract', [$number('0.01'), $number('0.03')]), ['type' => 'number', 'value' => '-0.02']],
            [self::operation('multiply', [$money('123456789012345678.91'), $number('2')]), ['type' => 'money', 'value' => ['amount' => '246913578024691357.82', 'currency' => 'RUB']]],
            [self::operation('divide', [$money('1'), $number('3')], 2), ['type' => 'money', 'value' => ['amount' => '0.33', 'currency' => 'RUB']]],
            [self::operation('divide', [$money('5'), $money('2')], 2), ['type' => 'number', 'value' => '2.5']],
            [self::operation('round', [$number('-1.235')], 2), ['type' => 'number', 'value' => '-1.24']],
            [self::operation('equal', [$number('1.00'), $number('1')]), ['type' => 'boolean', 'value' => true]],
            [self::operation('greater', [$money('2'), $money('1')]), ['type' => 'boolean', 'value' => true]],
            [self::operation('not', [self::literal('boolean', false)]), ['type' => 'boolean', 'value' => true]],
            [self::operation('if', [self::literal('boolean', true), $number('7'), ['kind' => 'reference', 'variable_id' => self::FIELD]]), ['type' => 'number', 'value' => '7']],
            [self::operation('and', [self::literal('boolean', false), ['kind' => 'reference', 'variable_id' => self::FIELD]]), ['type' => 'boolean', 'value' => false]],
            [self::operation('add_days', [self::literal('date', '2024-02-28'), $number('1')]), ['type' => 'date', 'value' => '2024-02-29']],
            [self::operation('days_between', [self::literal('date', '2024-03-01'), self::literal('date', '2024-02-28')]), ['type' => 'number', 'value' => '-2']],
            [self::operation('if', [self::literal('boolean', false), self::literal('text', 'Да'), self::literal('text', '')]), ['type' => 'text', 'value' => '']],
        ];
        foreach ($cases as [$expression, $expected]) {
            self::assertSame($expected, $engine->evaluate($expression, $none));
        }
    }

    public function test_references_and_table_sums_use_ids_and_preserve_currencies(): void
    {
        $definition = ['type' => 'table', 'columns' => [
            ['id' => 'price', 'label' => 'Сумма', 'definition' => ['type' => 'money', 'constraints' => ['currency' => 'RUB']]],
            ['id' => 'other', 'label' => 'Сумма', 'definition' => ['type' => 'money', 'constraints' => ['currency' => 'RUB']]],
        ]];
        $rows = [
            ['id' => 'first', 'values' => ['price' => ['amount' => '0.1', 'currency' => 'RUB'], 'other' => ['amount' => '100', 'currency' => 'RUB']]],
            ['id' => 'second', 'values' => ['price' => ['amount' => '0.2', 'currency' => 'RUB'], 'other' => ['amount' => '200', 'currency' => 'RUB']]],
        ];
        $expression = ['kind' => 'sum', 'variable_id' => self::FIELD, 'column_id' => 'price'];
        $resolve = static function (string $id) use ($definition, $rows): array {
            self::assertSame(self::FIELD, $id);

            return ['definition' => $definition, 'value' => $rows];
        };
        self::assertSame(['type' => 'money', 'value' => ['amount' => '0.3', 'currency' => 'RUB']], (new ContractFormulaEvaluator)->evaluate($expression, $resolve));
        self::assertSame([self::FIELD], (new ContractFormulaExpression)->references($expression));
        self::assertSame(['type' => 'money', 'value' => ['amount' => '0', 'currency' => 'RUB']], (new ContractFormulaEvaluator)->evaluate($expression, static fn (): array => ['definition' => $definition, 'value' => []]));
        self::assertSame(['type' => 'number', 'value' => '0'], (new ContractFormulaEvaluator)->evaluate(['kind' => 'reference', 'variable_id' => self::FIELD], static fn (): array => ['definition' => ['type' => 'number'], 'value' => '0']));
    }

    #[DataProvider('invalidExpressions')]
    public function test_rejects_unsafe_or_invalid_expressions(array $expression): void
    {
        $this->expectException(ContractBuilderException::class);
        (new ContractFormulaEvaluator)->evaluate($expression, static fn (): null => null);
    }

    public static function invalidExpressions(): array
    {
        $n = self::literal('number', '1');
        $deep = $n;
        for ($index = 0; $index < 34; $index++) {
            $deep = self::operation('round', [$deep], 0);
        }

        return [
            'code' => [['kind' => 'code', 'value' => 'phpinfo()']],
            'network' => [['kind' => 'operation', 'operator' => 'fetch', 'args' => [self::literal('text', 'https://example.test')]]],
            'unknown-key' => [array_replace($n, ['url' => 'https://example.test'])],
            'float' => [self::literal('number', 0.1)],
            'zero' => [self::operation('divide', [$n, self::literal('number', '0')], 2)],
            'currency' => [self::operation('add', [self::literal('money', ['amount' => '1', 'currency' => 'RUB']), self::literal('money', ['amount' => '1', 'currency' => 'USD'])])],
            'type' => [self::operation('add', [$n, self::literal('text', '1')])],
            'precision' => [self::operation('round', [$n], 13)],
            'missing-scale' => [self::operation('divide', [$n, $n])],
            'missing-value' => [['kind' => 'reference', 'variable_id' => self::FIELD]],
            'wrong-reference' => [['kind' => 'reference', 'variable_id' => 'human label']],
            'date' => [self::literal('date', '2025-02-29')],
            'fractional-days' => [self::operation('add_days', [self::literal('date', '2026-09-19'), self::literal('number', '0.5')])],
            'depth' => [$deep],
        ];
    }

    private static function literal(string $type, mixed $value): array
    {
        return ['kind' => 'literal', 'type' => $type, 'value' => $value];
    }

    private static function operation(string $operator, array $args, ?int $scale = null): array
    {
        return ['kind' => 'operation', 'operator' => $operator, 'args' => $args, ...($scale === null ? [] : ['scale' => $scale])];
    }
}
