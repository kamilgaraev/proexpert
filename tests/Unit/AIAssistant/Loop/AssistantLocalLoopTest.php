<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Loop;

use PHPUnit\Framework\TestCase;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLocalLoop;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantModelAction;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;

final class AssistantLocalLoopTest extends TestCase
{
    public static function nativeItems(array $action, ?string $id = null): array
    {
        $id ??= bin2hex(random_bytes(8));
        if ($action['type'] === 'tool') {
            return [['type' => 'function_call', 'id' => 'fc_'.$id, 'status' => 'completed', 'call_id' => 'call_'.$id,
                'name' => $action['tool'] === 'material.search' ? 'material_search' : 'material_read_selected',
                'arguments' => GatewayModelRequest::canonicalJson($action['arguments'])]];
        }
        return [['type' => 'message', 'id' => 'msg_'.$id, 'status' => 'completed', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => GatewayModelRequest::canonicalJson($action), 'annotations' => []]]]];
    }

    public static function nativeLoop(OfflineLoopFixtures $fixture, ?\Closure $mutate = null): AssistantLocalLoop
    {
        $loop = $fixture->loop();
        $args = [];
        foreach ((new \ReflectionMethod($loop, '__construct'))->getParameters() as $parameter) {
            $args[$parameter->getName()] = (new \ReflectionProperty($loop, $parameter->getName()))->getValue($loop);
        }
        $driver = $args['modelDriver'];
        $args['modelDriver'] = static function (array $input) use ($driver, $mutate): array {
            $items = self::nativeItems($driver($input));
            return $mutate === null ? $items : $mutate($items, $input);
        };
        return new AssistantLocalLoop(...$args);
    }

    public function testNativeHistoryPreservesPrecedingMessageAndSameCallIdSafeResultWithoutPublishingIt(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction(), static fn (array $input): array => OfflineLoopFixtures::priceAnswer($input)];
        $loop = self::nativeLoop($fixture, static function (array $items, array $input): array {
            if ($input['nativeHistory'] === []) {
                return [...self::nativeItems(['type' => 'plan', 'plan' => 'Never publish preceding text.']), ...$items];
            }
            return $items;
        });
        $result = $loop->run('offline', $fixture->context->request());
        self::assertSame('READY', $result['status']);
        $history = $fixture->driverInputs[1]['nativeHistory'];
        self::assertSame(['message', 'function_call', 'function_call_output'], array_column($history, 'type'));
        self::assertSame($history[1]['call_id'], $history[2]['call_id']);
        self::assertNotSame($history[1]['call_id'], $result['trace'][1]['callRef'] ?? 'ref_internal');
        self::assertSame(GatewayModelRequest::canonicalJson($fixture->driverInputs[1]['toolReferences']), $history[2]['output']);
        self::assertStringNotContainsString('PRIVATE', $history[2]['output']);
        self::assertStringNotContainsString('Never publish', $result['reply']);
        self::assertSame([], $fixture->driverInputs[0]['nativeHistory']);
        self::assertTrue(count(array_filter($fixture->countedPayloads, static fn (string $json): bool => str_contains($json, 'function_call_output'))) > 0);
    }

    public function testNativeMalformedOrParallelCallsNeverExecute(): void
    {
        foreach (['parallel', 'duplicate-args', 'unknown', 'json-tool', 'plaintext-reasoning'] as $mode) {
            $fixture = new OfflineLoopFixtures(); $fixture->actions = [OfflineLoopFixtures::searchAction()];
            $loop = self::nativeLoop($fixture, static function (array $items) use ($mode): array {
                if ($mode === 'parallel') { $copy = $items[0]; $copy['id'] .= '_2'; $copy['call_id'] .= '_2'; $items[] = $copy; }
                if ($mode === 'duplicate-args') { $items[0]['arguments'] = '{"query":"бетон","query":"private","limit":1}'; }
                if ($mode === 'unknown') { $items[0]['name'] = 'delete_material'; }
                if ($mode === 'json-tool') { $items = self::nativeItems(['type' => 'plan', 'plan' => 'safe']); $items[0]['content'][0]['text'] = '{"type":"tool","tool":"material.search","arguments":{"query":"бетон","limit":1}}'; }
                if ($mode === 'plaintext-reasoning') { $items[] = ['id' => 'reason_1', 'type' => 'reasoning', 'summary' => ['private'], 'encrypted_content' => 'opaque']; }
                return $items;
            });
            self::assertSame('BLOCKED', $loop->run('offline', $fixture->context->request())['status'], $mode);
            self::assertSame([], $fixture->executed, $mode);
        }
    }

    public function testNativeDuplicateCallAndCurrentRevokeBlockBeforeSecondToolExecution(): void
    {
        foreach (['duplicate', 'revoke'] as $mode) {
            $fixture = new OfflineLoopFixtures(); $fixture->actions = [OfflineLoopFixtures::searchAction(), OfflineLoopFixtures::searchAction()];
            $loop = self::nativeLoop($fixture, static function (array $items, array $input) use ($fixture, $mode): array {
                if ($input['nativeHistory'] !== []) {
                    if ($mode === 'duplicate') { $items[0]['call_id'] = $input['nativeHistory'][0]['call_id']; }
                    else { $fixture->context->snapshot['authorized'] = false; }
                }
                return $items;
            });
            self::assertSame('BLOCKED', $loop->run('offline', $fixture->context->request())['status']);
            self::assertCount(1, $fixture->executed);
        }
    }

    public function testNativeReplayWireBudgetBlocksBeforeNextSendAndNewRunHasNoHistory(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction(), static fn (array $input): array => OfflineLoopFixtures::priceAnswer($input)];
        $fixture->onTokenize = static function (OfflineLoopFixtures $current, string $bytes, array $count): array {
            $body = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            if (isset($body['input']) && in_array('function_call_output', array_column($body['input'], 'type'), true)) {
                $count['tokens'] = $current->context->profile['contextWindow'];
            }
            return $count;
        };
        $loop = self::nativeLoop($fixture);
        self::assertSame('BLOCKED', $loop->run('offline', $fixture->context->request())['status']);
        self::assertSame(1, $fixture->driverCalls);
        self::assertCount(1, $fixture->executed);
        $fixture->onTokenize = null;
        $fixture->actions[1] = OfflineLoopFixtures::searchAction();
        $fixture->actions[2] = static fn (array $input): array => OfflineLoopFixtures::priceAnswer($input);
        self::assertSame('READY', $loop->run('offline', $fixture->context->request())['status']);
        self::assertSame([], $fixture->driverInputs[1]['nativeHistory']);
    }

    public function testModelChoosesSearchReadAndNaturalAnswerUsingTheDeclaredMaterialUniverse(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [
            ['type' => 'plan', 'plan' => 'Сначала найду нужный бетон, затем перечитаю выбранную запись.'],
            OfflineLoopFixtures::searchAction(),
            static fn (array $input): array => ['type' => 'tool', 'tool' => 'material.read_selected',
                'arguments' => ['ref' => $input['toolReferences']['selectionRefs'][0]]],
            static fn (array $input): array => OfflineLoopFixtures::priceAnswer($input),
        ];
        $result = self::nativeLoop($fixture)->run('offline', $fixture->context->request());
        self::assertSame('READY', $result['status']);
        self::assertSame('Бетон В25 стоит 7800.00 RUB за м³.', $result['reply']);
        self::assertFalse($result['transportAllowed']);
        self::assertSame(['material.search', 'material.read_selected'], array_column($fixture->executed, 'tool'));
        self::assertSame(4, $fixture->driverCalls);
        self::assertStringNotContainsString('4800', $result['reply']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('7800', json_encode($result['trace'], JSON_THROW_ON_ERROR));
    }

    public function testDriverMayRefineSearchWithoutServerKeywordRouting(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction(2, 'цемент кг'),
            OfflineLoopFixtures::searchAction(1, 'бетон в25 м3'),
            static fn (array $input): array => OfflineLoopFixtures::priceAnswer($input)];
        $result = self::nativeLoop($fixture)->run('offline', $fixture->context->request());
        self::assertSame('READY', $result['status']);
        self::assertSame('цемент кг', $fixture->executed[0]['arguments']['query']);
        self::assertSame('бетон в25 м3', $fixture->executed[1]['arguments']['query']);
    }

    public function testInvalidNarrativeCanBeRepairedByTheModelWithoutAFieldDump(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction(),
            static function (array $input): array {
                $action = OfflineLoopFixtures::priceAnswer($input);
                $action['text'] = 'Долг составляет 777000000 рублей.';

                return $action;
            },
            static fn (array $input): array => OfflineLoopFixtures::priceAnswer($input)];
        $result = self::nativeLoop($fixture)->run('offline', $fixture->context->request());
        self::assertSame('READY', $result['status']);
        self::assertSame('Бетон В25 стоит 7800.00 RUB за м³.', $result['reply']);
        self::assertSame('claims_invalid', $fixture->driverInputs[2]['repair']);
    }

    public function testActualNoEvidenceAllowsAnHonestScopedAnswerWithoutInventedFacts(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->actions = [OfflineLoopFixtures::searchAction(1, 'несуществующий материал'),
            static function (array $input): array {
                $sources = [];
                foreach ($input['context']['messages'] as $message) {
                    if ($message['role'] === 'tool') {
                        $sources = $message['sourceRefs'];
                    }
                }

                return ['type' => 'final', 'text' => 'В выбранном scope подходящих позиций нет.',
                    'claims' => [], 'sourceRefs' => $sources, 'claimScope' => $input['toolReferences']['claimScope']];
            }];
        $result = self::nativeLoop($fixture)->run('offline', $fixture->context->request());
        self::assertSame('READY', $result['status']);
        self::assertSame('no_data', $fixture->evidence[0]['status']);
        self::assertSame([], $fixture->evidence[0]['facts']);
        self::assertSame('В выбранном scope подходящих позиций нет.', $result['reply']);
    }
}
