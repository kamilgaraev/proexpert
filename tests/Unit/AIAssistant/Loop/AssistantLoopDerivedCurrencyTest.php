<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Loop;

use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLoopResponseValidator;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantModelAction;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantContextReceipt;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantToolResult;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\SyntheticMaterialSearchCorpus;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreContextBindings;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantLoopDerivedCurrencyTest extends TestCase
{
    public function testCurrentQuantityAndSealedSourcePriceProduceVerifiedTotalWithoutDerivedPriceFact(): void
    {
        [$result, $fixture] = $this->runQuote('Рассчитай стоимость 12 м³ бетона В25.', '93600.00');
        self::assertSame('READY', $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertFalse($result['transportAllowed']);
        self::assertSame('7800.00', $fixture->corpus->records()[0]->decimal);
        self::assertStringNotContainsString('93600.00', json_encode($fixture->evidence, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('invalidQuantity')]
    public function testAmbiguousOrInvalidCurrentQuantityCannotAuthorizeTotal(string $text): void
    {
        [$result] = $this->runQuote($text, '93600.00');
        self::assertNotSame('READY', $result['status']);
    }

    public static function invalidQuantity(): array
    {
        return array_map(static fn (string $text): array => [$text], [
            'Бетон В25.', 'Нужно 0 м3 бетона.', 'Нужно -12 м3 бетона.', 'Нужно −12 м3 бетона.',
            'Нужно 1.2 м3 бетона.', 'Нужно 1,2 м3 бетона.', 'Нужно 1e1 м3 бетона.',
            'Нужно 12/2 м3 бетона.', 'Нужно 12-14 м3 бетона.', 'Нужно 12 м3 или 24 м3 бетона.',
            'Нужно 1000001 м3 бетона.', 'Нужно 12 кг бетона.',
        ]);
    }

    #[DataProvider('implicitQuantityExpression')]
    public function testImplicitUnitExpressionCannotAuthorizeItsLastOperand(string $text, string $amount): void
    {
        [$result, $fixture] = $this->runQuote($text, $amount);
        self::assertNotSame('READY', $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertFalse($result['transportAllowed']);
        self::assertStringNotContainsString($amount, json_encode($fixture->evidence, JSON_THROW_ON_ERROR));
    }

    public static function implicitQuantityExpression(): array
    {
        return [
            'en dash' => ['Нужно 12–14 м³ бетона В25.', '109200.00'],
            'em dash' => ['Нужно 12—14 м³ бетона В25.', '109200.00'],
            'unicode hyphen' => ['Нужно 12‐14 м³ бетона В25.', '109200.00'],
            'nonbreaking hyphen' => ['Нужно 12‑14 м³ бетона В25.', '109200.00'],
            'spaced ascii range' => ['Нужно 12 - 14 м³ бетона В25.', '109200.00'],
            'spaced unicode range' => ['Нужно 12 – 14 м³ бетона В25.', '109200.00'],
            'nonbreaking spaces' => ["Нужно 12\u{00a0}–\u{00a0}14 m³ бетона В25.", '109200.00'],
            'from to' => ['Нужно от 12 до 14 м³ бетона В25.', '109200.00'],
            'alternative' => ['Нужно 12 или 24 м³ бетона В25.', '187200.00'],
            'either alternative' => ['Нужно 12 либо 24 m3 бетона В25.', '187200.00'],
            'joined quantities' => ['Нужно 12 и 24 м3 бетона В25.', '187200.00'],
            'english alternative' => ['Need 12 or 24 m3 of B25 concrete.', '187200.00'],
            'addition' => ['Нужно 12 + 14 м3 бетона В25.', '109200.00'],
            'multiplication' => ['Нужно 12 × 14 м3 бетона В25.', '109200.00'],
            'ascii multiplication' => ['Нужно 12 * 14 м3 бетона В25.', '109200.00'],
            'division' => ['Нужно 12 / 14 м3 бетона В25.', '109200.00'],
            'ratio' => ['Нужно 12 : 14 м3 бетона В25.', '109200.00'],
            'plus minus' => ['Нужно 12 ± 14 м3 бетона В25.', '109200.00'],
            'exponent' => ['Нужно 12 ^ 14 м3 бетона В25.', '109200.00'],
            'equals' => ['Нужно 12 = 14 м3 бетона В25.', '109200.00'],
            'spaced negative' => ['Нужно - 12 м3 бетона В25.', '93600.00'],
            'spaced unicode negative' => ['Нужно − 12 м3 бетона В25.', '93600.00'],
            'spaced positive sign' => ['Нужно + 12 м3 бетона В25.', '93600.00'],
            'separated digits' => ['Нужно 1 12 м3 бетона В25.', '93600.00'],
            'trailing alternative' => ['Нужно 12 м3 или 24 бетона В25.', '93600.00'],
            'trailing range' => ['Нужно 12 м3 – 14 бетона В25.', '93600.00'],
            'trailing addition' => ['Нужно 12 м3 + 2 бетона В25.', '93600.00'],
            'trailing division' => ['Нужно 12 м3 / 2 бетона В25.', '93600.00'],
            'lower bound' => ['Нужно от 12 м3 бетона В25.', '93600.00'],
            'upper bound' => ['Нужно до 12 м3 бетона В25.', '93600.00'],
            'approximate' => ['Нужно около 12 м3 бетона В25.', '93600.00'],
            'approximate adverb' => ['Нужно приблизительно 12 м³ бетона В25.', '93600.00'],
            'uppercase approximate adverb' => ['Нужно ПРИБЛИЗИТЕЛЬНО 12 м³ бетона В25.', '93600.00'],
            'approximate before verb' => ['Приблизительно нужно 12 м³ бетона В25.', '93600.00'],
            'approximate adjective' => ['Ориентировочный объём: 12 м³ бетона В25.', '93600.00'],
            'approximate abbreviation' => ['Нужно прибл. 12 м³ бетона В25.', '93600.00'],
            'approximate suffix' => ['Нужно 12 м³ бетона В25 (примерно).', '93600.00'],
            'ascii approximation' => ['Нужно ~12 м³ бетона В25.', '93600.00'],
            'unicode approximation' => ['Нужно ≈ 12 м³ бетона В25.', '93600.00'],
            'unicode similar approximation' => ['Нужно ∼12 m³ бетона B25.', '93600.00'],
            'unicode asymptotic approximation' => ['Нужно ≃12 m3 бетона B25.', '93600.00'],
            'fullwidth approximation' => ['Нужно ～12 м³ бетона В25.', '93600.00'],
            'parenthesized approximation' => ['Нужно ≈ (12 м³) бетона В25.', '93600.00'],
            'english approximation' => ['Need roughly 12 m3 of B25 concrete.', '93600.00'],
            'latin letter multiplication' => ['Нужно 2 x 12 m3 бетона B25.', '93600.00'],
            'uppercase latin multiplication' => ['Нужно 2 X 12 m3 бетона B25.', '93600.00'],
            'cyrillic letter multiplication' => ['Нужно 2 х 12 м3 бетона В25.', '93600.00'],
            'uppercase cyrillic multiplication' => ['Нужно 2 Х 12 м3 бетона В25.', '93600.00'],
            'nonbreaking multiplication spaces' => ["Нужно 2\u{00a0}x\u{00a0}12 m3 бетона B25.", '93600.00'],
            'parenthesized multiplication' => ['Нужно 2 x (12 m3) бетона B25.', '93600.00'],
            'trailing letter multiplication' => ['Нужно 12 м3 x 2 бетона В25.', '93600.00'],
            'trailing cyrillic multiplication' => ['Нужно 12 м3 х 2 бетона В25.', '93600.00'],
        ];
    }

    #[DataProvider('singleCurrentQuantity')]
    public function testSingleQuantityPreservesUnitVariantsAndUnrelatedNumbers(string $text): void
    {
        [$result] = $this->runQuote($text, '93600.00');
        self::assertSame('READY', $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertFalse($result['transportAllowed']);
    }

    public static function singleCurrentQuantity(): array
    {
        return array_map(static fn (string $text): array => [$text], [
            'Нужно 12 m3 бетона B25.', 'Нужно 12 m³ бетона B25.',
            'Нужно 12 м3 бетона В25.', 'Нужно 12 м³ бетона В25.',
            'Бетон В25: нужно 12 м³.', 'В проекте 2026 нужно 12 м³ бетона В25.',
            'Проект №7, этаж 2: нужно 12 м³ бетона В25.',
            "Нужно 12\u{00a0}м³ бетона В25.", "Нужно 12\u{2009}m3 бетона B25.",
            'Для этапа 1–2 нужно 12 м³ бетона В25.',
        ]);
    }

    public function testUnitPriceCannotMasqueradeAsTotalAndValidSourcePriceStillWorks(): void
    {
        [$wrongTotal] = $this->runQuote('Нужно 12 м3 бетона В25.', '7800.00');
        self::assertNotSame('READY', $wrongTotal['status']);
        [$sourcePrice] = $this->runQuote('Нужно 12 м3 бетона В25.', '7800.00', 'm3');
        self::assertSame('READY', $sourcePrice['status']);
    }

    public function testIntegerBoundariesAndDifferentSourcePriceAreCalculatedWithoutFixtureOracle(): void
    {
        foreach ([['Нужно 1 m3 бетона В25.', '7800.00', 'бетон В25 м3'],
            ['Нужно 1000000 м³ бетона В25.', '7800000000.00', 'бетон В25 м3'],
            ['Проект №42, этап 2–4: нужно 1 m3 бетона B25.', '7800.00', 'бетон В25 м3'],
            ['Проект 2026, этаж 4: нужно 1000000 м³ бетона В25.', '7800000000.00', 'бетон В25 м3'],
            ['Нужно 7 m³ бетона В30.', '57753.50', 'бетон В30 м3']] as [$text, $amount, $query]) {
            [$result] = $this->runQuote($text, $amount, null, 1, null, null, $query);
            self::assertSame('READY', $result['status']);
        }
        [$overflow] = $this->runQuote('Нужно 12 м3 бетона В25.', '92233720368547758.08');
        self::assertNotSame('READY', $overflow['status']);
        [$wrongUnit] = $this->runQuote('Нужно 12 м3 бетона В25.', '93600.00', 'kg');
        self::assertNotSame('READY', $wrongUnit['status']);
    }

    public function testPrivateDerivedCallbackCannotHideRevocationFromFollowingFreshCheck(): void
    {
        $fixtureHolder = (object) ['fixture' => new OfflineLoopFixtures()];
        [$result] = $this->runQuote('Нужно 12 м3 бетона В25.', '93600.00', null, 1, null,
            static function (OfflineLoopFixtures $fixture) use ($fixtureHolder): void { $fixtureHolder->fixture = $fixture; },
            'бетон В25 м3', static function (array $claim, AssistantModelAction $action, AssistantContextReceipt $receipt,
                AssistantToolResult $latest) use ($fixtureHolder): bool {
                $proof = PublicCoreContextBindings::verifyDerivedCurrency($claim, $action, $receipt, $latest);
                $fixtureHolder->fixture->corpus->revokePrice();

                return $proof;
            });
        self::assertNotSame('READY', $result['status']);
    }

    public function testSamePriceDifferentProvenanceAndAlteredGenerationAreRejectedByPureVerifier(): void
    {
        foreach (['duplicate', 'generation', 'basis', 'overflow'] as $mutation) {
            $holder = (object) ['fixture' => new OfflineLoopFixtures()];
            [$result] = $this->runQuote('Нужно 12 м3 бетона В25.', '93600.00', null, 1, null,
                static function (OfflineLoopFixtures $fixture) use ($holder): void { $holder->fixture = $fixture; },
                'бетон В25 м3', static function (array $claim, AssistantModelAction $action, AssistantContextReceipt $receipt,
                    AssistantToolResult $latest) use ($holder, $mutation): bool {
                    $evidence = $latest->evidence();
                    $envelope = $evidence['envelope'];
                    foreach ($envelope['facts'] as $index => $fact) {
                        if ($fact['kind'] !== 'price') { continue; }
                        if ($mutation === 'duplicate') {
                            $copy = $fact;
                            $copy['provenance']['unitRef'] = $holder->fixture->corpus->records()[1]->ref;
                            $envelope['coverage']['claimScope']['unitRefs'][] = $copy['provenance']['unitRef'];
                            $envelope['facts'][] = $copy;
                        } elseif ($mutation === 'generation') {
                            $envelope['facts'][$index]['provenance']['sourceGenerationRef'] = 'ref_'.str_repeat('f', 32);
                        } elseif ($mutation === 'basis') {
                            $envelope['facts'][$index]['perUnit'] = 'kg';
                        } else {
                            $envelope['facts'][$index]['decimal'] = '92233720368547758.07';
                        }
                        break;
                    }
                    $call = $latest->privateCall();
                    $forged = AssistantToolResult::projected($envelope, $evidence['projection'], $evidence['callBinding'],
                        $call['tool'], $call['arguments'], $holder->fixture->corpus->context());
                    $value = $action->values();
                    $value['claimScope'] = $forged->modelMetadata()['claimScope'];

                    return PublicCoreContextBindings::verifyDerivedCurrency($claim, AssistantModelAction::parse($value), $receipt, $forged);
                });
            self::assertNotSame('READY', $result['status']);
        }
    }

    public function testModelQueryAndEarlierHistoryCannotSupplyCurrentQuantity(): void
    {
        [$result] = $this->runQuote('Сколько стоит бетон В25?', '93600.00', null, 1, null,
            static function (OfflineLoopFixtures $fixture): void {
                $fixture->context->addArtifact('history-1', 'user', 'Нужно 12 м3 бетона.');
            }, 'бетон В25 12 м3');
        self::assertNotSame('READY', $result['status']);
    }

    public function testNumericProofDoesNotBypassSemanticValidationAndUnknownVerifierIsRejected(): void
    {
        [$semanticDenied] = $this->runQuote('Нужно 12 м3 бетона В25.', '93600.00', null, 1,
            static fn (): array => ['status' => 'blocked', 'reason' => 'claims_invalid']);
        self::assertNotSame('READY', $semanticDenied['status']);
        foreach ([static fn (): string => 'true', static function (): never { throw new \LogicException('unavailable'); }] as $verifier) {
            [$unknown] = $this->runQuote('Нужно 12 м3 бетона В25.', '93600.00', null, 1, null, null,
                'бетон В25 м3', $verifier);
            self::assertNotSame('READY', $unknown['status']);
        }
    }

    public function testAmbiguousLatestPriceEvidenceAndDeniedFinanceCannotSupportTotal(): void
    {
        [$ambiguous] = $this->runQuote('Нужно 12 м3 бетона.', '93600.00', null, 2, null, null, 'бетон м3');
        self::assertNotSame('READY', $ambiguous['status']);
        [$denied] = $this->runQuote('Нужно 12 м3 бетона В25.', '93600.00', null, 1, null,
            static fn (OfflineLoopFixtures $fixture) => $fixture->corpus->revokePrice());
        self::assertNotSame('READY', $denied['status']);
    }

    private function runQuote(string $currentText, string $amount, ?string $unit = null, int $limit = 1,
        ?Closure $semantic = null, ?Closure $configure = null, string $query = 'бетон В25 м3', ?Closure $verifier = null): array
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->corpus = SyntheticMaterialSearchCorpus::registered('material-search-v1', 'public-material/1', 'quote-12m3');
        $fixture->search = new MaterialSearchService($fixture->corpus);
        $fixture->context->addArtifact($fixture->context->snapshot['conversation']['currentRef'], 'user', $currentText);
        $configure?->__invoke($fixture);
        $tokenizer = static fn (string $json, array $identity): array => $identity + ['tokens' => strlen($json)];
        $validator = new AssistantLoopResponseValidator($semantic ?? static fn (): array => ['status' => 'valid', 'reason' => 'none'],
            $verifier ?? Closure::fromCallable([PublicCoreContextBindings::class, 'verifyDerivedCurrency']));
        $loop = PublicCoreContextBindings::processorLoop($fixture->context->service(),
            static fn (?string $ref): array => $fixture->authority($ref), $tokenizer,
            static function (array $input) use ($fixture, $amount, $unit, $limit, $query): array {
                if ($fixture->driverCalls++ === 0) { return OfflineLoopFixtures::searchAction($limit, $query); }
                $answer = OfflineLoopFixtures::priceAnswer($input);
                $answer['text'] = 'Стоимость по проверенному каталогу: '.$amount.' RUB.';
                $answer['claims'][0]['value'] = $amount;
                $answer['claims'][0]['unit'] = $unit;

                return $answer;
            }, $fixture->adapter(), $validator, static fn (): int => $fixture->now,
            static function (array $binding, array $conditions, array $evidence) use ($fixture): ?array {
                if ($fixture->corpus->guard($fixture->corpus->context()) !== null || !$fixture->gateAllowed) { return null; }

                return ['authority' => $fixture->authority($binding['receipt']['contextRef']),
                    'now' => $fixture->now, 'privateContext' => $fixture->corpus->context()];
            });

        return [$loop->run('offline', $fixture->context->request()), $fixture];
    }
}
