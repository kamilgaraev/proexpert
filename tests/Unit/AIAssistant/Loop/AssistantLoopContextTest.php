<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Loop;

use PHPUnit\Framework\TestCase;

final class AssistantLoopContextTest extends TestCase
{
    public function testPhotoAndTwoFollowupsDoNotForceMaterialSearch(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->context->addPhotoFrame();
        foreach (['Объясни выбранное фото.', 'Поясни второй пункт.', 'Я про картинку, что значит эта строка?'] as $turn => $question) {
            $fixture->context->addArtifact('current', 'user', $question);
            $fixture->context->lineage['requestRevision'] = 'request/'.$turn;
            $fixture->driverCalls = 0;
            $fixture->actions = [static fn (array $input): array => OfflineLoopFixtures::contextAnswer($input,
                'По выбранной фотографии поясняю второй пункт.')];
            $result = $fixture->loop()->run('offline', $fixture->context->request());
            self::assertSame('READY', $result['status']);
            self::assertSame('По выбранной фотографии поясняю второй пункт.', $result['reply']);
            self::assertSame([], $fixture->executed);
            self::assertStringContainsString('Синтетическое фото:', json_encode($fixture->driverInputs[array_key_last($fixture->driverInputs)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
    }

    public function testNewTopicIsSelectedFromValidatedFrameWithoutLexicalRouting(): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->context->addArtifact('new-topic', 'topic', 'Выбранная запись склада.');
        $covered = ['current', 'new-topic'];
        $fixture->context->addArtifact('frame', 'frame', 'New selected topic.', [
            'topicRef' => 'new-topic', 'entityRefs' => [], 'filterRefs' => [], 'mediaRefs' => [],
            'transcriptRefs' => [], 'coveredArtifactRefs' => $covered,
        ], $fixture->context->combinedBindings($covered));
        $fixture->actions = [static fn (array $input): array => OfflineLoopFixtures::contextAnswer($input,
            'Новая тема: выбранная запись склада.')];
        $result = $fixture->loop()->run('offline', $fixture->context->request());
        self::assertSame('READY', $result['status']);
        self::assertSame('Новая тема: выбранная запись склада.', $result['reply']);
        self::assertSame([], $fixture->executed);
    }

    public function testDriverCannotConsumeATamperedOrNonCommittedIssuerMap(): void
    {
        foreach (['status', 'map'] as $case) {
            $fixture = new OfflineLoopFixtures();
            $fixture->onAuthority = static function (OfflineLoopFixtures $fixture, ?string $contextRef) use ($case): void {
                if ($contextRef !== null && isset($fixture->context->receipts[$contextRef])) {
                    if ($case === 'status') {
                        $fixture->context->receipts[$contextRef]['status'] = 'staged';
                    } else {
                        $receipt = &$fixture->context->receipts[$contextRef]['receipt'];
                        $receipt['aliases'][$receipt['currentRef']]['artifactRef'] = 'history-1';
                    }
                }
            };
            $result = $fixture->loop()->run('offline', $fixture->context->request());
            self::assertSame('BLOCKED', $result['status']);
            self::assertSame(0, $fixture->driverCalls);
        }
    }

    public function testRevocationOrNewRequestDuringDriverCallbackStopsBeforeTools(): void
    {
        foreach (['request', 'consent', 'material'] as $case) {
            $fixture = new OfflineLoopFixtures();
            $fixture->actions = [OfflineLoopFixtures::searchAction()];
            $fixture->onDriver = static function (OfflineLoopFixtures $fixture) use ($case): void {
                match ($case) {
                    'request' => $fixture->context->lineage['requestRevision'] = 'other-request',
                    'consent' => $fixture->context->snapshot['scope']['consent'] = 'revoked',
                    'material' => $fixture->corpus->revokeScope(),
                };
            };
            self::assertSame('BLOCKED', $fixture->loop()->run('offline', $fixture->context->request())['status']);
            self::assertSame([], $fixture->executed);
        }
    }

    public function testOldSelectionReferenceCannotBeReplayedAfterTheResultGenerationChanges(): void
    {
        $fixture = new OfflineLoopFixtures();
        $oldSelection = null;
        $fixture->actions = [OfflineLoopFixtures::searchAction(),
            static function (array $input) use (&$oldSelection): array {
                $oldSelection = $input['toolReferences']['selectionRefs'][0];

                return OfflineLoopFixtures::searchAction(1, 'бетон в30 м3');
            },
            static fn (array $input): array => ['type' => 'tool', 'tool' => 'material.read_selected',
                'arguments' => ['ref' => $oldSelection]],
        ];
        $result = $fixture->loop()->run('offline', $fixture->context->request());
        self::assertSame('BLOCKED', $result['status']);
        self::assertCount(2, $fixture->executed);
    }
}
