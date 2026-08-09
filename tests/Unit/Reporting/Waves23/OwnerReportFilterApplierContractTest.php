<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Support\Reporting\OwnerReportFilterApplier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OwnerReportFilterApplierContractTest extends TestCase
{
    #[Test]
    #[DataProvider('conditions')]
    public function canonical_and_operator_aware_filters_share_one_application_contract(
        mixed $condition,
        array $expected,
    ): void {
        $method = new ReflectionMethod(OwnerReportFilterApplier::class, 'normalizeCondition');

        self::assertSame($expected, $method->invoke(null, $condition));
    }

    public static function conditions(): array
    {
        return [
            'canonical scalar' => [52, ['eq', 52]],
            'canonical list' => [[1, 2], ['in', [1, 2]]],
            'operator aware scalar' => [
                ['operator' => 'gte', 'value' => 10],
                ['gte', 10],
            ],
            'operator aware list' => [
                ['operator' => 'not_in', 'value' => ['closed']],
                ['not_in', ['closed']],
            ],
        ];
    }
}
