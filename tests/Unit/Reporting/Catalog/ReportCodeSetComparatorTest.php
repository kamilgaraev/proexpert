<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportCodeSetComparator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ReportCodeSetComparatorTest extends TestCase
{
    private ReportCodeSetComparator $comparator;

    protected function setUp(): void
    {
        $this->comparator = new ReportCodeSetComparator;
    }

    public function test_validate_preserves_non_lexicographic_order(): void
    {
        $codes = ['project_portfolio_health', 'holding_performance', 'accepted_production_progress'];

        self::assertSame($codes, $this->comparator->validate($codes, 'candidate_code'));
    }

    public function test_validate_rejects_wrong_type_and_unsafe_code(): void
    {
        foreach ([42, 'Invalid_code', 'invalid-code', 'a'] as $code) {
            try {
                $this->comparator->validate([$code], 'subject');
                self::fail('Invalid code was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('subject_invalid', $exception->getMessage());
            }
        }
    }

    public function test_validate_rejects_duplicate_independently(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('subject_duplicate');

        $this->comparator->validate(['valid_code', 'valid_code'], 'subject');
    }

    public function test_equal_is_order_independent_without_mutating_inputs(): void
    {
        $left = ['third_code', 'first_code', 'second_code'];
        $right = ['second_code', 'third_code', 'first_code'];

        self::assertTrue($this->comparator->equal($left, $right));
        self::assertSame(['third_code', 'first_code', 'second_code'], $left);
        self::assertSame(['second_code', 'third_code', 'first_code'], $right);
    }

    public function test_equal_rejects_missing_and_extra_codes(): void
    {
        self::assertFalse($this->comparator->equal(['one_code'], []));
        self::assertFalse($this->comparator->equal(['one_code'], ['one_code', 'extra_code']));
    }
}
