<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Evidence;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\CanonicalSourceDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CanonicalSourceDecimalTest extends TestCase
{
    #[Test]
    #[DataProvider('validDecimals')]
    public function it_accepts_only_exact_values_that_fit_the_shared_storage_contract(string $value): void
    {
        self::assertTrue(CanonicalSourceDecimal::isValid($value));
    }

    #[Test]
    #[DataProvider('invalidDecimals')]
    public function it_rejects_values_that_would_diverge_between_evidence_model_and_document_facts(mixed $value): void
    {
        self::assertFalse(CanonicalSourceDecimal::isValid($value));
    }

    /** @return iterable<string, array{string}> */
    public static function validDecimals(): iterable
    {
        yield 'zero' => ['0'];
        yield 'four decimal places' => ['0.1234'];
        yield 'positive upper boundary' => ['1000000000000.0000'];
        yield 'negative upper boundary' => ['-1000000000000'];
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDecimals(): iterable
    {
        yield 'integer input' => [1];
        yield 'float input' => [0.1234];
        yield 'oversized positive' => ['1000000000001'];
        yield 'oversized negative' => ['-1000000000001'];
        yield 'overprecision' => ['0.12345'];
        yield 'negative zero' => ['-0.0000'];
        yield 'exponent' => ['1e3'];
        yield 'leading zero' => ['01'];
        yield 'positive sign' => ['+1'];
        yield 'nan' => ['NaN'];
        yield 'infinity' => ['Infinity'];
    }
}
