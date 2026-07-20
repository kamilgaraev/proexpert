<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObjectTypeSignalClassifierTest extends TestCase
{
    #[DataProvider('residentialDescriptions')]
    public function test_residential_wording_is_classified_without_office_or_warehouse_templates(string $description): void
    {
        self::assertTrue(ObjectTypeSignalClassifier::isResidential($description));
        self::assertSame('residential', ObjectTypeSignalClassifier::canonical($description));
    }

    public static function residentialDescriptions(): iterable
    {
        yield ['Двухэтажный коттедж площадью 180 м²'];
        yield ['Частный дом для одной семьи'];
        yield ['Индивидуальный жилой дом'];
        yield ['Загородный таунхаус'];
        yield ['Дачный дом с мансардой'];
        yield ['Особняк с гаражом'];
        yield ['ИЖС'];
    }
}
