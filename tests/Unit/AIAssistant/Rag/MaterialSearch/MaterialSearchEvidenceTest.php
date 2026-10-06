<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag\MaterialSearch;

use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchQuery;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\SyntheticMaterialSearchCorpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaterialSearchEvidenceTest extends TestCase
{
    public function testExactClosedEnvelopeAndProvenance(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $result = (new MaterialSearchService($corpus))->search($corpus->context(), new MaterialSearchQuery('бетон В25'))->localEnvelope();
        $keys = array_keys($result);
        sort($keys);
        $expected = ['schemaVersion', 'requestRef', 'profileRef', 'profileVersion', 'toolKind', 'status',
            'reason', 'facts', 'coverage', 'nextSafeRefs', 'resultGenerationRef'];
        sort($expected);
        self::assertSame($expected, $keys);
        self::assertArrayNotHasKey('has_more', $result);
        self::assertSame('safe-tool-result/1', $result['schemaVersion']);
        self::assertSame('verified', $result['status']);
        self::assertSame('complete', $result['coverage']['status']);

        foreach ($result['facts'] as $fact) {
            $provenance = $fact['provenance'];
            self::assertSame('safe-provenance/1', $provenance['schemaVersion']);
            self::assertSame($result['resultGenerationRef'], $provenance['sourceGenerationRef']);
            self::assertContains($provenance['unitRef'], $result['coverage']['claimScope']['unitRefs']);
            self::assertMatchesRegularExpression('~^ref_[a-f0-9]{32}$~D', $provenance['evidenceRef']);
            self::assertMatchesRegularExpression('~^ref_[a-f0-9]{32}$~D', $provenance['fragmentRef']);
            self::assertMatchesRegularExpression('~^[a-f0-9]{64}$~D', $provenance['contentDigest']);
            self::assertSame('most-ai-material-evidence/1', $provenance['validationVersion']);
        }

        $json = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('actorId', $json);
        self::assertStringNotContainsString('organizationId', $json);
        self::assertStringNotContainsString('projectId', $json);
        self::assertStringNotContainsString('is_safe', $json);
    }

    public function testPriceDigestBindsExactCanonicalDecimalUnitAndCurrency(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $service = new MaterialSearchService($corpus);
        $search = $service->search($corpus->context(), new MaterialSearchQuery('бетон В25'))->localEnvelope();
        $read = $service->readSelected($corpus->context(), $search['nextSafeRefs'][0])->localEnvelope();
        $prices = array_values(array_filter($read['facts'], static fn (array $fact): bool => $fact['kind'] === 'price'));
        self::assertCount(1, $prices);
        self::assertSame('7800.00', $prices[0]['decimal']);
        self::assertSame('RUB', $prices[0]['currency']);
        self::assertSame('m3', $prices[0]['perUnit']);
        self::assertNull($prices[0]['period']);
        $source = ['decimal' => '7800.00', 'currency' => 'RUB', 'perUnit' => 'm3', 'period' => null];
        self::assertSame(hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)), $prices[0]['provenance']['contentDigest']);
        self::assertSame($search['facts'], $read['facts']);
        $vat = array_values(array_filter($read['facts'], static fn (array $fact): bool => $fact['kind'] === 'text'
            && str_contains($fact['provenance']['fragmentVersion'], '/vat/')));
        self::assertSame('20%', $vat[0]['value']['utf8Text']);
    }

    public function testUnknownCurrencyAndBasisDoNotLeakUnqualifiedAmount(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $result = (new MaterialSearchService($corpus))->search($corpus->context(), new MaterialSearchQuery('бетон В15'))->localEnvelope();
        self::assertSame('partial', $result['status']);
        self::assertNotEmpty($result['facts']);
        self::assertSame([], array_values(array_filter($result['facts'], static fn (array $fact): bool => $fact['kind'] === 'price')));
        self::assertStringNotContainsString('6500', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testFinancePermissionSuppressesPriceVatAndBasisButKeepsPermittedFacts(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $corpus->revokePrice();
        $result = (new MaterialSearchService($corpus))->search($corpus->context(), new MaterialSearchQuery('бетон В25'))->localEnvelope();
        self::assertSame('partial', $result['status']);
        self::assertCount(2, $result['facts']);
        self::assertSame('Бетон товарный В25 М350', $result['facts'][0]['value']['utf8Text']);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('7800', $json);
        self::assertStringNotContainsString('/vat/', $json);
        self::assertStringNotContainsString('/price_basis/', $json);
    }

    public function testPriceRevokedAfterProjectionIsRemovedBeforeReturn(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $corpus->invalidateAtCheck(4, 'price');
        $result = (new MaterialSearchService($corpus))->search($corpus->context(), new MaterialSearchQuery('бетон В25'))->localEnvelope();
        self::assertSame('partial', $result['status']);
        self::assertCount(2, $result['facts']);
        self::assertStringNotContainsString('7800', json_encode($result, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('unknownPriceFixtures')]
    public function testUnknownOrIncompatibleUnitDoesNotCreateNumericPrice(string $fixture): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named($fixture);
        $result = (new MaterialSearchService($corpus))->search($corpus->context(), new MaterialSearchQuery('бетон'))->localEnvelope();
        self::assertSame('partial', $result['status']);
        self::assertNotEmpty($result['facts']);
        self::assertSame([], array_values(array_filter($result['facts'], static fn (array $fact): bool => $fact['kind'] === 'price')));
        self::assertStringNotContainsString('7800', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public static function unknownPriceFixtures(): array
    {
        return [['material-search-unknown-unit-v1'], ['material-search-mismatched-price-v1']];
    }

    public function testExplicitZeroPriceIsPreservedAndUnknownVatIsNotInferred(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-zero-price-v1');
        $result = (new MaterialSearchService($corpus))->search($corpus->context(), new MaterialSearchQuery('цемент'))->localEnvelope();
        $prices = array_values(array_filter($result['facts'], static fn (array $fact): bool => $fact['kind'] === 'price'));
        self::assertSame('0.00', $prices[0]['decimal']);
        self::assertSame('kg', $prices[0]['perUnit']);
        self::assertStringNotContainsString('/vat/', json_encode($result, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('unitAliases')]
    public function testUnitNormalizationDoesNotAssumeConversions(string $raw, ?string $canonical): void
    {
        self::assertSame($canonical, MaterialSearchQuery::normalizeUnit($raw));
    }

    public static function unitAliases(): array
    {
        return [['м³', 'm3'], ['м3', 'm3'], ['M3', 'm3'], ['куб. м', 'm3'], ['кг', 'kg'],
            ['т', 't'], ['шт.', 'item'], ['м²', 'm2'], ['литр', null], ['мешок', null]];
    }
}
