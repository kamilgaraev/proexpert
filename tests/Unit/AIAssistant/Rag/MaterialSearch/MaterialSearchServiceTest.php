<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag\MaterialSearch;

use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchQuery;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\SyntheticMaterialSearchCorpus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaterialSearchServiceTest extends TestCase
{
    #[DataProvider('concreteQueries')]
    public function testConcreteSearchExcludesCoatingsAndFinishedProducts(string $query): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $result = (new MaterialSearchService($corpus))->search($corpus->context(), new MaterialSearchQuery($query));
        $envelope = $result->localEnvelope();
        $text = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        self::assertContains($envelope['status'], ['verified', 'partial']);
        self::assertStringContainsString('Бетон товарный В25 М350', $text);
        self::assertStringNotContainsString('Гидроизоляция', $text);
        self::assertStringNotContainsString('Перемычка', $text);
        self::assertStringNotContainsString('Цемент', $text);
        self::assertFalse($result->hasMore());
        self::assertSame('search_subset', $envelope['coverage']['claimScope']['kind']);
    }

    public static function concreteQueries(): array
    {
        return [['бетон с ценой за 1 м³'], ['найди бетон за1м³'], ['бетон за м3'],
            ['товарный бетон за кубический метр'], ['готовая бетонная смесь'], ['бетонная смесь']];
    }

    public function testModelCanRefineGradeThenReadCanonicalPrice(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $service = new MaterialSearchService($corpus);
        $broad = $service->search($corpus->context(), new MaterialSearchQuery('бетон', 1));
        self::assertTrue($broad->hasMore());
        $narrow = $service->search($corpus->context(), new MaterialSearchQuery('бетон В30 за м³'))->localEnvelope();
        self::assertCount(1, $narrow['nextSafeRefs']);
        $read = $service->readSelected($corpus->context(), $narrow['nextSafeRefs'][0])->localEnvelope();
        $prices = array_values(array_filter($read['facts'], static fn (array $fact): bool => $fact['kind'] === 'price'));

        self::assertSame('8250.50', $prices[0]['decimal']);
        self::assertSame('RUB', $prices[0]['currency']);
        self::assertSame('m3', $prices[0]['perUnit']);
        self::assertSame('selected_entity', $read['coverage']['claimScope']['kind']);
        self::assertSame($narrow['profileRef'], $read['profileRef']);
        self::assertSame($narrow['resultGenerationRef'], $read['resultGenerationRef']);
    }

    public function testWindowCoverageIsHonestAndOmittedRefsCanBeRead(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $service = new MaterialSearchService($corpus);
        $result = $service->search($corpus->context(), new MaterialSearchQuery('бетон', 1));
        $envelope = $result->localEnvelope();

        self::assertTrue($result->hasMore());
        self::assertSame('partial', $envelope['status']);
        self::assertSame('partial', $envelope['coverage']['status']);
        self::assertSame(1, $envelope['coverage']['inspectedUnits']);
        self::assertSame(3, $envelope['coverage']['totalUnits']);
        self::assertCount(2, $envelope['coverage']['omittedUnitRefs']);
        self::assertCount(3, $envelope['nextSafeRefs']);
        self::assertNotSame('whole_corpus', $envelope['coverage']['claimScope']['kind']);
        $read = $service->readSelected($corpus->context(), $envelope['coverage']['omittedUnitRefs'][0])->localEnvelope();
        self::assertNotEmpty($read['facts']);
        self::assertSame('read_selected', $read['toolKind']);
    }

    public function testOtherMaterialSearchesAreNotRoutedToConcrete(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $service = new MaterialSearchService($corpus);
        $result = $service->search($corpus->context(), new MaterialSearchQuery('гидроизоляция'))->localEnvelope();
        self::assertCount(1, $result['nextSafeRefs']);
        self::assertSame('Гидроизоляция для бетона', $result['facts'][0]['value']['utf8Text']);
        $result = $service->search($corpus->context(), new MaterialSearchQuery('перемычка'))->localEnvelope();
        self::assertSame('Перемычка железобетонная', $result['facts'][0]['value']['utf8Text']);
    }

    public function testKilogramPricesAreNotConvertedToCubicMetres(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $service = new MaterialSearchService($corpus);
        $result = $service->search($corpus->context(), new MaterialSearchQuery('цемент'))->localEnvelope();
        $prices = array_values(array_filter($result['facts'], static fn (array $fact): bool => $fact['kind'] === 'price'));
        self::assertSame('kg', $prices[0]['perUnit']);
        $result = $service->search($corpus->context(), new MaterialSearchQuery('цемент за м³'))->localEnvelope();
        self::assertSame('no_data', $result['status']);
        self::assertSame([], $result['facts']);
    }

    public function testNoDataIsLimitedToInspectedSearchScope(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $result = (new MaterialSearchService($corpus))->search($corpus->context(), new MaterialSearchQuery('асфальт'))->localEnvelope();
        self::assertSame('no_data', $result['status']);
        self::assertSame('no_evidence', $result['reason']);
        self::assertSame('search_subset', $result['coverage']['claimScope']['kind']);
        self::assertSame(0, $result['coverage']['totalUnits']);
        self::assertSame([], $result['nextSafeRefs']);
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidAndUnboundedQueriesAreRejected(string $text, int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MaterialSearchQuery($text, $limit);
    }

    public static function invalidQueries(): array
    {
        return [['', 1], ['  ', 1], ['бетон', 0], ['бетон', 11], [str_repeat('x', 1025), 1], ["\xFF", 1]];
    }
}
