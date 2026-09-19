<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Exceptions\ContractBuilderException;
use App\Services\Contract\ContractFormulaTypeChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ContractFormulaTypeCheckerTest extends TestCase
{
    private const FIELD = '5942e1c0-4aae-4ad5-b94e-0526966b9068';

    #[DataProvider('validExpressions')]
    public function test_valid_expression_types(array $expression, array $target, array $definitions = []): void
    {
        (new ContractFormulaTypeChecker)->validate($expression, $definitions, $target);
        $this->addToAssertionCount(1);
    }

    public static function validExpressions(): array
    {
        $number = self::literal('number', '1');
        $money = self::money('RUB');
        $date = self::literal('date', '2026-09-19');

        return [
            'percentage result' => [self::operation('add', [$number, $number]), ['type' => 'percentage']],
            'money times number' => [self::operation('multiply', [$money, $number]), ['type' => 'money', 'constraints' => ['currency' => 'RUB']]],
            'number times money' => [self::operation('multiply', [$number, $money]), ['type' => 'money']],
            'money ratio' => [self::operation('divide', [$money, $money], 2), ['type' => 'number']],
            'money division' => [self::operation('divide', [$money, $number], 2), ['type' => 'money']],
            'round money' => [self::operation('round', [$money], 2), ['type' => 'money']],
            'date addition' => [self::operation('add_days', [$date, $number]), ['type' => 'date']],
            'date difference' => [self::operation('days_between', [$date, $date]), ['type' => 'number']],
            'comparison' => [self::operation('less', [$money, $money]), ['type' => 'boolean']],
            'boolean' => [self::operation('not', [self::literal('boolean', false)]), ['type' => 'boolean']],
            'text branches' => [self::operation('if', [self::literal('boolean', false), self::literal('text', 'a'), self::literal('text', 'b')]), ['type' => 'text']],
            'unconstrained currency' => [self::reference(), ['type' => 'money', 'constraints' => ['currency' => 'RUB']], [self::FIELD => ['type' => 'money']]],
            'stable sum column' => [['kind' => 'sum', 'variable_id' => self::FIELD, 'column_id' => 'price'], ['type' => 'money'], [self::FIELD => ['type' => 'table', 'columns' => [
                ['id' => 'label', 'label' => 'Цена', 'definition' => ['type' => 'text']],
                ['id' => 'price', 'label' => 'Цена', 'definition' => ['type' => 'money']],
            ]]]],
        ];
    }

    #[DataProvider('invalidExpressions')]
    public function test_invalid_expression_types(array $expression, array $definitions = [], array $target = ['type' => 'number'], string $reason = 'type'): void
    {
        $this->expectException(ContractBuilderException::class);
        $this->expectExceptionMessage(trans_message('contracts.formula_'.$reason));
        (new ContractFormulaTypeChecker)->validate($expression, $definitions, $target);
    }

    public static function invalidExpressions(): array
    {
        $number = self::literal('number', '1');
        $money = self::money('RUB');
        $yes = self::literal('boolean', true);

        return [
            'unused wrong branch' => [self::operation('if', [$yes, $number, self::literal('text', 'bad')])],
            'unused wrong operand' => [self::operation('or', [$yes, $number]), [], ['type' => 'boolean']],
            'unused wrong currency' => [self::operation('if', [$yes, $money, self::money('USD')]), [], ['type' => 'money'], 'currency'],
            'money plus number' => [self::operation('add', [$money, $number])],
            'money times money' => [self::operation('multiply', [$money, $money])],
            'number divided by money' => [self::operation('divide', [$number, $money], 2)],
            'known currency mismatch' => [self::operation('equal', [$money, self::money('USD')]), [], ['type' => 'boolean'], 'currency'],
            'target currency mismatch' => [$money, [], ['type' => 'money', 'constraints' => ['currency' => 'USD']], 'currency'],
            'target type mismatch' => [$yes],
            'date operand' => [self::operation('add_days', [$number, $number]), [], ['type' => 'date']],
            'missing reference' => [self::reference(), [], ['type' => 'number'], 'missing'],
            'non numeric sum' => [['kind' => 'sum', 'variable_id' => self::FIELD, 'column_id' => 'text'], [self::FIELD => ['type' => 'table', 'columns' => [
                ['id' => 'text', 'definition' => ['type' => 'text']],
            ]]]],
            'missing column' => [['kind' => 'sum', 'variable_id' => self::FIELD, 'column_id' => 'missing'], [self::FIELD => ['type' => 'table', 'columns' => []]]],
            'table scalar reference' => [self::reference(), [self::FIELD => ['type' => 'table', 'columns' => []]]],
        ];
    }

    private static function reference(): array
    {
        return ['kind' => 'reference', 'variable_id' => self::FIELD];
    }

    private static function money(string $currency): array
    {
        return self::literal('money', ['amount' => '1', 'currency' => $currency]);
    }

    private static function literal(string $type, mixed $value): array
    {
        return ['kind' => 'literal', 'type' => $type, 'value' => $value];
    }

    private static function operation(string $operator, array $args, ?int $scale = null): array
    {
        return ['kind' => 'operation', 'operator' => $operator, 'args' => $args] + ($scale === null ? [] : ['scale' => $scale]);
    }
}
