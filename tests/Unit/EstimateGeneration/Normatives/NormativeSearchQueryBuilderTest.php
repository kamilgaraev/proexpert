<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeSearchQueryBuilder;
use PHPUnit\Framework\TestCase;

final class NormativeSearchQueryBuilderTest extends TestCase
{
    public function test_work_item_phrase_is_broadened_to_ranked_or_query(): void
    {
        self::assertSame(
            '"разработка" OR "грунта" OR "под" OR "фундаменты"',
            (new NormativeSearchQueryBuilder)->build('Разработка грунта под фундаменты'),
        );
    }
}
