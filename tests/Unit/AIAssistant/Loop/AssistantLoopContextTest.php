<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Loop;

use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLoopLimits;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    #[DataProvider('recallKinds')]
    public function testAttestedHistoryAndCompactedSummaryReachSemanticValidationWithoutSourceSubstitution(string $kind): void
    {
        $fixture = self::recallFixture($kind);
        $semanticCalls = 0;
        $actualSources = [];
        $reply = 'Ранее обсуждали выбранное фото и объём бетона.';
        $fixture->onValidate = static function (OfflineLoopFixtures $fixture, array $action) use (&$semanticCalls, $reply): array {
            $semanticCalls++;

            return $action['text'] === $reply ? ['status' => 'valid', 'reason' => 'none']
                : ['status' => 'repair', 'reason' => 'claims_invalid'];
        };
        $fixture->actions = [static function (array $input, OfflineLoopFixtures $fixture) use ($kind, $reply, &$actualSources): array {
            $message = null;
            foreach ($input['context']['messages'] as $candidate) {
                if (($kind === 'history' && str_starts_with($candidate['content'], 'Synthetic turn 1:'))
                    || ($kind === 'summary' && str_starts_with($candidate['content'], 'Синтетическая сводка:'))) {
                    $message = $candidate;
                }
            }
            self::assertNotNull($message);
            $receipt = $fixture->context->receipts[$input['context']['contextRef']]['receipt'];
            self::assertSame($kind === 'history' ? 'history-1' : 'summary', $receipt['aliases'][$message['ref']]['artifactRef']);
            self::assertNotSame($receipt['aliases'][$receipt['currentRef']]['sourceRefs'], $message['sourceRefs']);
            $actualSources = $message['sourceRefs'];

            return ['type' => 'final', 'text' => $reply, 'claims' => [],
                'sourceRefs' => $message['sourceRefs'], 'claimScope' => $input['contextScope']];
        }];
        $result = $fixture->loop()->run('offline', $fixture->context->request());
        self::assertSame('READY', $result['status']);
        self::assertSame($reply, $result['reply']);
        self::assertSame(1, $semanticCalls);
        self::assertSame($actualSources, $fixture->validatedActions[0]['sourceRefs']);
        self::assertSame([], $fixture->executed);
    }

    public static function recallKinds(): array
    {
        return [['history'], ['summary']];
    }

    #[DataProvider('excludedSourceKinds')]
    public function testSystemRawForeignAndDroppedHistorySourcesCannotReachSemanticValidation(string $kind): void
    {
        $fixture = new OfflineLoopFixtures();
        $excluded = [];
        if ($kind === 'foreign' || $kind === 'dropped') {
            $issuer = $kind === 'foreign' ? new OfflineLoopFixtures() : $fixture;
            $prepared = $issuer->context->service()->prepare('offline', $issuer->context->request());
            self::assertSame('READY', $prepared['status']);
            $excluded = $prepared['payload']['messages'][1]['sourceRefs'];
        }
        if ($kind === 'dropped') {
            self::compactHistory($fixture);
        }
        $fixture->actions = [static function (array $input, OfflineLoopFixtures $fixture) use ($kind, $excluded): array {
            $receipt = $fixture->context->receipts[$input['context']['contextRef']]['receipt'];
            if ($kind === 'system') {
                $system = $input['context']['messages'][0];
                self::assertSame('system', $system['role']);
                self::assertNotContains($system['ref'], $input['contextScope']['unitRefs']);
                $excluded = $system['sourceRefs'];
            } elseif ($kind === 'raw') {
                $excluded = ['source-history-1'];
            } elseif ($kind === 'dropped') {
                self::assertNotContains('history-1', array_column($receipt['aliases'], 'artifactRef'));
                self::assertContains('summary', array_column($receipt['aliases'], 'artifactRef'));
            }
            $allowedSources = [];
            foreach ($input['contextScope']['unitRefs'] as $ref) {
                $allowedSources = [...$allowedSources, ...$receipt['aliases'][$ref]['sourceRefs']];
            }
            self::assertNotEmpty(array_diff($excluded, $allowedSources));

            return ['type' => 'final', 'text' => 'По выбранной фотографии поясняю второй пункт.',
                'claims' => [], 'sourceRefs' => $excluded, 'claimScope' => $input['contextScope']];
        }];
        $result = $fixture->loop(new AssistantLoopLimits(repairs: 0))
            ->run('offline', $fixture->context->request());
        self::assertSame('BLOCKED', $result['status']);
        self::assertArrayNotHasKey('reply', $result);
        self::assertSame([], $fixture->validatedActions);
        self::assertSame([], $fixture->executed);
    }

    public static function excludedSourceKinds(): array
    {
        return [['system'], ['raw'], ['foreign'], ['dropped']];
    }

    #[DataProvider('invalidContextClaims')]
    public function testContextScopeCannotBeWidenedToFrameOrCorpusOrAuthorizeNumericClaims(string $kind): void
    {
        $fixture = new OfflineLoopFixtures();
        $fixture->context->addPhotoFrame();
        $fixture->actions = [static function (array $input) use ($kind): array {
            $action = OfflineLoopFixtures::contextAnswer($input, 'По выбранной фотографии поясняю второй пункт.');
            $proposal = $input['context']['taskFrame']['proposalRef'];
            self::assertNotContains($proposal, $input['contextScope']['unitRefs']);
            if ($kind === 'frame') {
                $action['claimScope']['unitRefs'][] = $proposal;
            } elseif ($kind === 'whole_corpus') {
                $action['claimScope']['kind'] = 'whole_corpus';
            } else {
                $action['claims'] = [['value' => '7800.00', 'unit' => 'm3', 'currency' => 'RUB',
                    'sourceRefs' => $action['sourceRefs']]];
            }

            return $action;
        }];
        $result = $fixture->loop(new AssistantLoopLimits(repairs: 0))->run('offline', $fixture->context->request());
        self::assertSame('BLOCKED', $result['status']);
        self::assertArrayNotHasKey('reply', $result);
        self::assertSame([], $fixture->validatedActions);
        self::assertSame([], $fixture->executed);
    }

    public static function invalidContextClaims(): array
    {
        return [['frame'], ['whole_corpus'], ['price']];
    }

    #[DataProvider('recallRevocations')]
    public function testHistoryAndSummarySourceFieldAclAndLifetimeRevocationStopsBeforeSemanticValidation(string $kind, string $revocation): void
    {
        $fixture = self::recallFixture($kind);
        $fixture->actions = [static function (array $input, OfflineLoopFixtures $fixture) use ($kind, $revocation): array {
            $receipt = $fixture->context->receipts[$input['context']['contextRef']]['receipt'];
            $artifact = $kind === 'history' ? 'history-1' : 'summary';
            $sources = null;
            foreach ($receipt['aliases'] as $alias) {
                if ($alias['artifactRef'] === $artifact) {
                    $sources = $alias['sourceRefs'];
                }
            }
            self::assertNotNull($sources);
            match ($revocation) {
                'source' => $fixture->context->snapshot['sources']['source-history-1']['version'] = 'source/2',
                'field' => $fixture->context->snapshot['sources']['source-history-1']['fields']['field-history-1']['hash'] = hash('sha256', 'changed'),
                'acl' => $fixture->context->snapshot['sources']['source-history-1']['scope']['acl'] = 'revoked',
                'lifetime' => $fixture->context->lineage['now'] = $fixture->context->lineage['expiresAt'],
                default => throw new \InvalidArgumentException('unsupported_fixture_revocation'),
            };

            return ['type' => 'final', 'text' => 'Ранее обсуждали выбранное фото и объём бетона.',
                'claims' => [], 'sourceRefs' => $sources, 'claimScope' => $input['contextScope']];
        }];
        $result = $fixture->loop()->run('offline', $fixture->context->request());
        self::assertSame('BLOCKED', $result['status']);
        self::assertArrayNotHasKey('reply', $result);
        self::assertSame(1, $fixture->driverCalls);
        self::assertSame([], $fixture->validatedActions);
        self::assertSame([], $fixture->executed);
    }

    public static function recallRevocations(): array
    {
        $cases = [];
        foreach (['history', 'summary'] as $kind) {
            foreach (['source', 'field', 'acl', 'lifetime'] as $revocation) {
                $cases[] = [$kind, $revocation];
            }
        }

        return $cases;
    }

    private static function recallFixture(string $kind): OfflineLoopFixtures
    {
        $fixture = new OfflineLoopFixtures();
        if ($kind === 'summary') {
            self::compactHistory($fixture);
        }
        $fixture->context->addArtifact('current', 'user', 'Напомни, что мы обсуждали раньше.');

        return $fixture;
    }

    private static function compactHistory(OfflineLoopFixtures $fixture): void
    {
        foreach ($fixture->context->snapshot['conversation']['historyRefs'] as $ref) {
            $fixture->context->addArtifact($ref, $fixture->context->artifacts[$ref]['kind'],
                str_repeat('Ранее обсуждали выбранное фото и объём бетона. ', 80));
        }
        $fixture->context->addSummary(array_slice($fixture->context->snapshot['conversation']['historyRefs'], 0, 6));
        $fixture->context->profile['contextWindow'] = 23000;
    }
}
