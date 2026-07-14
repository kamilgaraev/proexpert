<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiPricingCatalogTest extends TestCase
{
    #[Test]
    public function it_selects_the_latest_effective_version_for_an_exact_operation_and_model(): void
    {
        $catalog = new AiPricingCatalog([
            'vision' => ['timeweb' => ['timeweb/vision-v2' => [
                ['version' => '2026-01', 'effective_at' => '2026-01-01T00:00:00+00:00', 'currency' => 'RUB', 'input_per_million' => '10.00', 'cached_input_per_million' => '5.00', 'output_per_million' => '20.00'],
                ['version' => '2026-07', 'effective_at' => '2026-07-01T00:00:00+00:00', 'currency' => 'RUB', 'input_per_million' => '12.00', 'cached_input_per_million' => '6.00', 'output_per_million' => '24.00'],
            ]]],
        ]);

        $snapshot = $catalog->resolve('vision', 'timeweb', 'timeweb/vision-v2', new DateTimeImmutable('2026-07-14T00:00:00+00:00'));

        self::assertSame('2026-07', $snapshot->version);
        self::assertSame('RUB', $snapshot->currency);
    }

    #[Test]
    public function unknown_prices_fail_closed(): void
    {
        $this->expectException(DomainException::class);
        (new AiPricingCatalog([]))->resolve('ocr', 'timeweb', 'unknown/model', new DateTimeImmutable);
    }
}
