<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Loop;

use PHPUnit\Framework\TestCase;

final class AssistantToolResultAdapterTest extends TestCase
{
    public function testMissingProjectionNeverPromotesTheLocalEnvelopeIntoModelInput(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->projectionAvailable = false;
        $fixture->actions = [OfflineLoopFixtures::searchAction()];
        $result = $fixture->loop()->run('offline', $fixture->context->request());
        self::assertSame('BLOCKED', $result['status']);
        self::assertSame(1, $fixture->driverCalls);
        self::assertCount(1, $fixture->executed);
        self::assertArrayNotHasKey('reply', $result);
    }

    public function testProjectionCannotForgeRequestOrProfileNamespaces(): void
    {
        foreach (['requestRef', 'profileRef', 'generationRef'] as $field) {
            $fixture = new OfflineLoopFixtures();
            $fixture->actions = [OfflineLoopFixtures::searchAction()];
            $fixture->onProject = static function (OfflineLoopFixtures $fixture, array $envelope, array $projection) use ($field): array {
                $projection[$field] = 'ref_'.str_repeat('a', 32);

                return $projection;
            };
            self::assertSame('BLOCKED', $fixture->loop()->run('offline', $fixture->context->request())['status']);
        }
    }

    public function testASelectedPartialAnswerDoesNotRequireWholeCorpusCompleteness(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction(1),
            static fn (array $input): array => OfflineLoopFixtures::priceAnswer($input)];
        $result = $fixture->loop()->run('offline', $fixture->context->request());
        self::assertSame('READY', $result['status']);
        self::assertSame('partial', $fixture->evidence[0]['coverage']['status']);
        self::assertSame('search_subset', $fixture->evidence[0]['coverage']['claimScope']['kind']);
    }

    public function testWholeCorpusClaimCannotBeAuthorizedBySelectedCompleteCoverage(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction(1, 'бетон в25 м3'),
            static function (array $input): array {
                $answer = OfflineLoopFixtures::priceAnswer($input);
                $answer['claimScope']['kind'] = 'whole_corpus';

                return $answer;
            }];
        self::assertSame('BLOCKED', $fixture->loop()->run('offline', $fixture->context->request())['status']);
        self::assertSame('complete', $fixture->evidence[0]['coverage']['status']);
        self::assertSame('search_subset', $fixture->evidence[0]['coverage']['claimScope']['kind']);
    }

    public function testWrongPriceUnitOrCurrencyDoesNotPassTheCanonicalFactGuard(): void
    {
        foreach ([['value' => '4800.00'], ['unit' => 'kg'], ['currency' => 'USD']] as $invalid) {
            $fixture = new OfflineLoopFixtures();
            $fixture->actions = [OfflineLoopFixtures::searchAction(),
                static function (array $input) use ($invalid): array {
                    $answer = OfflineLoopFixtures::priceAnswer($input);
                    $answer['claims'][0] = array_replace($answer['claims'][0], $invalid);

                    return $answer;
                }];
            self::assertSame('BLOCKED', $fixture->loop()->run('offline', $fixture->context->request())['status']);
            self::assertSame('7800.00', $fixture->evidence[0]['facts'][2]['decimal']);
        }
    }

    public function testPriceOrRecordAccessRevokedAfterSearchBlocksTheFinalAnswer(): void
    {
        foreach (['price', 'record'] as $revoke) {
            $fixture = new OfflineLoopFixtures();
            $fixture->actions = [OfflineLoopFixtures::searchAction(),
                static function (array $input, OfflineLoopFixtures $fixture) use ($revoke): array {
                    if ($revoke === 'price') {
                        $fixture->corpus->revokePrice();
                    } else {
                        $fixture->corpus->revokeRecord($fixture->corpus->records()[0]->ref);
                    }

                    return OfflineLoopFixtures::priceAnswer($input);
                }];
            self::assertSame('BLOCKED', $fixture->loop()->run('offline', $fixture->context->request())['status']);
        }
    }

    public function testProjectionMustRegisterTheActualIssuerArtifactAndAllSelectionsOnce(): void
    {
        foreach (['artifact', 'selection'] as $case) {
            $fixture = new OfflineLoopFixtures();
            $fixture->actions = [OfflineLoopFixtures::searchAction()];
            $fixture->onProject = static function (OfflineLoopFixtures $fixture, array $envelope, array $projection) use ($case): array {
                if ($case === 'artifact') {
                    $projection['artifactRef'] = 'current';
                } else {
                    $projection['referenceMap'] = [];
                }

                return $projection;
            };
            self::assertSame('BLOCKED', $fixture->loop()->run('offline', $fixture->context->request())['status']);
        }
    }

    public function testDriverNeverReceivesTheUnsealedToolEnvelopeOrPrivateCallReceipt(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction(), static fn (array $input): array => OfflineLoopFixtures::priceAnswer($input)];
        self::assertSame('READY', $fixture->loop()->run('offline', $fixture->context->request())['status']);
        foreach ($fixture->driverInputs as $input) {
            $json = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString('safe-tool-result/1', $json);
            self::assertStringNotContainsString('PRIVATE_', $json);
            self::assertStringNotContainsString('receiptDigest', $json);
            self::assertArrayNotHasKey('envelope', $input);
        }
    }
}
