<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Context;

use PHPUnit\Framework\TestCase;

final class AssistantContextBudgetTest extends TestCase
{
    public function testOutputAndToolReservationsAreSubtractedFromThePinnedWindow(): void
    {
        $fixture = new OfflineContextFixtures();
        $fixture->profile['contextWindow'] = 12000;
        $fixture->profile['answerReserve'] = 2048;
        $fixture->profile['toolReserve'] = 3072;
        $result = $fixture->service()->prepare('offline', $fixture->request());

        self::assertSame('READY', $result['status']);
        self::assertSame(6880, $result['inputBudget']);
        self::assertLessThanOrEqual($result['inputBudget'], $result['tokenCount']);
    }

    public function testBudgetExhaustionWithoutAValidatedSummaryFailsClosed(): void
    {
        $fixture = new OfflineContextFixtures();
        $fixture->onCount = static fn (OfflineContextFixtures $fixture, string $payload, array $count): array =>
            array_replace($count, ['tokens' => $fixture->profile['contextWindow']]);
        self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);
    }

    public function testOnlyAnExactFreshSummaryCanReplaceDroppedOlderTurns(): void
    {
        $fixture = new OfflineContextFixtures(10);
        $covered = array_slice($fixture->snapshot['conversation']['historyRefs'], 0, 8);
        $fixture->addSummary($covered);
        $fixture->profile['contextWindow'] = 2000;
        $fixture->profile['maxOutputTokens'] = 100;
        $fixture->profile['answerReserve'] = 100;
        $fixture->profile['toolReserve'] = 100;
        $fixture->onCount = static function (OfflineContextFixtures $fixture, string $payload, array $count): array {
            $isCompact = str_contains($payload, 'Синтетическая сводка:')
                && !str_contains($payload, 'Synthetic turn 1:')
                && !str_contains($payload, 'Synthetic turn 8:');

            return array_replace($count, ['tokens' => $isCompact ? 900 : 5000]);
        };
        $result = $fixture->service()->prepare('offline', $fixture->request());
        self::assertSame('READY', $result['status']);
        $wire = OfflineContextFixtures::json($result['payload']);
        self::assertStringContainsString('Синтетическая сводка:', $wire);
        self::assertStringContainsString('Synthetic turn 9:', $wire);
        self::assertStringContainsString('Synthetic turn 10:', $wire);
        self::assertStringNotContainsString('Synthetic turn 1:', $wire);
    }

    public function testWrongTokenizerIdentityAndNegativeCountsAreRejected(): void
    {
        foreach ([['tokenizerRevision' => 'other'], ['modelRevision' => 'other'], ['tokens' => -1], ['tokens' => '1']] as $invalid) {
            $fixture = new OfflineContextFixtures();
            $fixture->onCount = static fn (OfflineContextFixtures $fixture, string $payload, array $count): array =>
                array_replace($count, $invalid);
            self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);
        }
    }

    public function testUnavailableOrUnqualifiedModelProfileCannotUseGuessedLimits(): void
    {
        $fixture = new OfflineContextFixtures();
        $fixture->profileAvailable = false;
        self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);
        $fixture->profileAvailable = true;
        $fixture->profile['qualification'] = 'production';
        self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);
    }

    public function testReservesCannotConsumeTheWholeWindowOrUnderstateOutputLimit(): void
    {
        $fixture = new OfflineContextFixtures();
        $fixture->profile['contextWindow'] = 3072;
        self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);
        $fixture->profile['contextWindow'] = 100000;
        $fixture->profile['answerReserve'] = 512;
        self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);
    }

    public function testSummaryCannotClaimTheCurrentTurnOrAnUncoveredPrefix(): void
    {
        $fixture = new OfflineContextFixtures();
        $fixture->addSummary(['history-1', 'history-2', 'current']);
        $fixture->onCount = static fn (OfflineContextFixtures $fixture, string $payload, array $count): array =>
            array_replace($count, ['tokens' => str_contains($payload, 'Синтетическая сводка:') ? 500 : 100000]);
        self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);

        $fixture = new OfflineContextFixtures();
        $fixture->addSummary(['history-2', 'history-3']);
        $fixture->onCount = static fn (OfflineContextFixtures $fixture, string $payload, array $count): array =>
            array_replace($count, ['tokens' => str_contains($payload, 'Синтетическая сводка:') ? 500 : 100000]);
        self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);
    }

    public function testProfileChangesDuringTokenCountingInvalidateTheCandidate(): void
    {
        $fixture = new OfflineContextFixtures();
        $fixture->onCount = static function (OfflineContextFixtures $fixture, string $payload, array $count): array {
            $fixture->profile['modelRevision'] = '2';

            return $count;
        };
        self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);
    }

    public function testReferencedProfileValuesCannotMutateThePinnedIdentity(): void
    {
        foreach (['modelRevision' => '2', 'contextWindow' => 200000] as $field => $replacement) {
            $fixture = new OfflineContextFixtures();
            $value = $fixture->profile[$field];
            $fixture->profile[$field] = &$value;
            $fixture->onCount = static function (OfflineContextFixtures $fixture, string $payload, array $count) use (&$value, $replacement): array {
                $value = $replacement;

                return $count;
            };
            self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);
        }
    }
}
