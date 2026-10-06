<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Loop;

use PHPUnit\Framework\TestCase;

final class AssistantLocalLoopTest extends TestCase
{
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
        $result = $fixture->loop()->run('offline', $fixture->context->request());
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
        $result = $fixture->loop()->run('offline', $fixture->context->request());
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
        $result = $fixture->loop()->run('offline', $fixture->context->request());
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
        $result = $fixture->loop()->run('offline', $fixture->context->request());
        self::assertSame('READY', $result['status']);
        self::assertSame('no_data', $fixture->evidence[0]['status']);
        self::assertSame([], $fixture->evidence[0]['facts']);
        self::assertSame('В выбранном scope подходящих позиций нет.', $result['reply']);
    }
}
