<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Loop;

use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLocalLoop;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLoopLimits;
use PHPUnit\Framework\TestCase;

final class AssistantLoopSafetyTest extends TestCase
{
    public function testDefaultConstructorIsDormantAndNeverCallsTheDriver(): void
    {
        $result = (new AssistantLocalLoop())->run('unknown', []);
        self::assertSame('BLOCKED', $result['status']);
        self::assertFalse($result['transportAllowed']);
    }

    public function testMutationOrForgedPrincipalArgumentsNeverInvokeAnyEffect(): void
    {
        foreach ([['type' => 'tool', 'tool' => 'approve_payment', 'arguments' => []],
            ['type' => 'tool', 'tool' => 'material.search', 'arguments' => ['query' => 'бетон', 'limit' => 1, 'tenant' => 99]]] as $action) {
            $fixture = new OfflineLoopFixtures();
            $fixture->actions = [$action];
            self::assertSame('BLOCKED', $fixture->loop()->run('offline', $fixture->context->request())['status']);
            self::assertSame([], $fixture->executed);
        }
    }

    public function testStepCallAndCumulativeTokenLimitsStopTheLoop(): void
    {
        $fixture = new OfflineLoopFixtures();
        self::assertSame('BLOCKED', $fixture->loop(new AssistantLoopLimits(steps: 2))->run('offline', $fixture->context->request())['status']);
        self::assertSame(2, $fixture->driverCalls);

        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction(), OfflineLoopFixtures::searchAction()];
        self::assertSame('BLOCKED', $fixture->loop(new AssistantLoopLimits(toolCalls: 1))->run('offline', $fixture->context->request())['status']);
        self::assertCount(1, $fixture->executed);

        $fixture = new OfflineLoopFixtures();
        self::assertSame('BLOCKED', $fixture->loop(new AssistantLoopLimits(totalTokens: 1))->run('offline', $fixture->context->request())['status']);
        self::assertSame(0, $fixture->driverCalls);
    }

    public function testElapsedDeadlineDuringToolExecutionStopsBeforeProjection(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction()];
        $fixture->onExecute = static function (OfflineLoopFixtures $fixture): void {
            $fixture->now += 30000;
        };
        self::assertSame('BLOCKED', $fixture->loop()->run('offline', $fixture->context->request())['status']);
        self::assertSame(0, $fixture->projected);
    }

    public function testFullInputIncludesToolDefinitionsAndAllDriverOutputsAreCounted(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction(),
            static fn (array $input): array => OfflineLoopFixtures::priceAnswer($input)];
        self::assertSame('READY', $fixture->loop()->run('offline', $fixture->context->request())['status']);
        self::assertStringContainsString('material.search', $fixture->countedPayloads[0]);
        self::assertStringContainsString('contextRef', $fixture->countedPayloads[0]);
        self::assertNotEmpty(array_filter($fixture->countedPayloads, static fn (string $json): bool => str_contains($json, '"output"')));
        self::assertStringContainsString('7800.00', $fixture->countedPayloads[array_key_last($fixture->countedPayloads)]);
    }

    public function testPIIOrFinalGuardRevocationNeverPublishesBufferedText(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction(), static function (array $input): array {
            $reply = OfflineLoopFixtures::priceAnswer($input);
            $reply['text'] = 'PRIVATE contact /private/storage';

            return $reply;
        }];
        $result = $fixture->loop()->run('offline', $fixture->context->request());
        self::assertSame('BLOCKED', $result['status']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));

        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction(), static fn (array $input): array => OfflineLoopFixtures::priceAnswer($input)];
        $fixture->onFinalGuard = static function (OfflineLoopFixtures $fixture): void {
            $fixture->context->lineage['requestRevision'] = 'revoked';
        };
        $result = $fixture->loop()->run('offline', $fixture->context->request());
        self::assertSame('BLOCKED', $result['status']);
        self::assertArrayNotHasKey('reply', $result);
    }

    public function testIncorrectTokenizerIdentityAndExcessiveOutputAreRejectedBeforeAnotherStep(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->onTokenize = static fn (OfflineLoopFixtures $fixture, string $json, array $count): array =>
            array_replace($count, ['tokenizerRevision' => 'unknown']);
        self::assertSame('BLOCKED', $fixture->loop()->run('offline', $fixture->context->request())['status']);
        self::assertSame(0, $fixture->driverCalls);

        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [['type' => 'plan', 'plan' => str_repeat('x', 4000)]];
        self::assertSame('BLOCKED', $fixture->loop()->run('offline', $fixture->context->request())['status']);
        self::assertSame(1, $fixture->driverCalls);
        self::assertSame([], $fixture->executed);
    }

    public function testRepairLimitCountsInvalidRepliesWithoutInventingFallbackContent(): void
    {
        $fixture = new OfflineLoopFixtures();
        $invalid = static function (array $input): array {
            $reply = OfflineLoopFixtures::priceAnswer($input);
            $reply['text'] = 'Неподтверждённая цена 4800.00 RUB.';

            return $reply;
        };
        $fixture->actions = [OfflineLoopFixtures::searchAction(), $invalid, $invalid];
        $result = $fixture->loop(new AssistantLoopLimits(repairs: 1))->run('offline', $fixture->context->request());
        self::assertSame('BLOCKED', $result['status']);
        self::assertSame(3, $fixture->driverCalls);
        self::assertArrayNotHasKey('reply', $result);
    }

    public function testUnknownReadOutcomeAndPrivateExceptionAreQuarantinedWithoutAnotherCall(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction()];
        $fixture->onExecute = static function (): void {
            throw new \RuntimeException('PRIVATE unknown outcome after local read');
        };
        $result = $fixture->loop()->run('offline', $fixture->context->request());
        self::assertSame('BLOCKED', $result['status']);
        self::assertSame(1, $fixture->driverCalls);
        self::assertCount(1, $fixture->executed);
        self::assertSame(0, $fixture->projected);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
    }
}
