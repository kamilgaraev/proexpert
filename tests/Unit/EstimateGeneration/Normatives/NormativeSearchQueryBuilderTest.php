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

    public function test_generic_construction_words_do_not_crowd_out_the_work_subject(): void
    {
        $query = (new NormativeSearchQueryBuilder)->build(
            'Устройство опалубки фундаментов',
        );

        self::assertStringNotContainsString('устройство', $query);
        self::assertStringContainsString('опалубки', $query);
        self::assertStringContainsString('фундаментов', $query);
    }
}
