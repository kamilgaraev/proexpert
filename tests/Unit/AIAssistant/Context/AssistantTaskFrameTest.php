<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Context;

use PHPUnit\Framework\TestCase;

final class AssistantTaskFrameTest extends TestCase
{
    public function testPhotoTranscriptEntityFilterAndTopicHaveExplicitOpaqueBindings(): void
    {
        $fixture = new OfflineContextFixtures();
        $fixture->addPhotoFrame();
        $result = $fixture->service()->prepare('offline', $fixture->request());

        self::assertSame('READY', $result['status']);
        self::assertCount(1, $result['payload']['taskFrame']['mediaRefs']);
        self::assertCount(1, $result['payload']['taskFrame']['transcriptRefs']);
        self::assertCount(1, $result['payload']['taskFrame']['entityRefs']);
        self::assertCount(1, $result['payload']['taskFrame']['filterRefs']);
        self::assertMatchesRegularExpression('/^ref_[a-f0-9]{32}$/', $result['payload']['taskFrame']['topicRef']);
        $wire = OfflineContextFixtures::json($result['payload']);
        self::assertStringContainsString('Синтетическое фото:', $wire);
        self::assertStringContainsString('Объяснение выбранной фотографии.', $wire);
        self::assertStringNotContainsString('PRIVATE_', $wire);
        foreach ($result['payload']['messages'] as $message) {
            if (str_contains($message['content'], 'Синтетическое фото:')) {
                self::assertSame('user', $message['role']);
            }
        }
    }

    public function testTwoFollowupsKeepThePinnedMediaAndTranscript(): void
    {
        $fixture = new OfflineContextFixtures();
        $fixture->addPhotoFrame();
        foreach (['Поясни второй пункт.', 'Я про картинку, что значит эта строка?'] as $question) {
            $fixture->addArtifact('current', 'user', $question);
            $result = $fixture->service()->prepare('offline', $fixture->request());
            self::assertSame('READY', $result['status']);
            self::assertStringContainsString($question, OfflineContextFixtures::json($result['payload']));
            self::assertCount(1, $result['payload']['taskFrame']['mediaRefs']);
            self::assertStringContainsString('Синтетическое фото:', OfflineContextFixtures::json($result['payload']));
        }
    }

    public function testAcceptedModelTopicSwitchUsesBoundProposalWithoutKeywordRouting(): void
    {
        $fixture = new OfflineContextFixtures();
        $fixture->addArtifact('current', 'user', 'Что это значит?');
        $fixture->addArtifact('new-topic', 'topic', 'Новая выбранная тема: синтетическая запись склада.');
        $covered = ['current', 'new-topic'];
        $fixture->addArtifact('frame', 'frame', 'Model-proposed selected topic.', [
            'topicRef' => 'new-topic', 'entityRefs' => [], 'filterRefs' => [],
            'mediaRefs' => [], 'transcriptRefs' => [], 'coveredArtifactRefs' => $covered,
        ], $fixture->combinedBindings($covered));
        $result = $fixture->service()->prepare('offline', $fixture->request());
        self::assertSame('READY', $result['status']);
        self::assertStringContainsString('Новая выбранная тема:', OfflineContextFixtures::json($result['payload']));
        self::assertSame([], $result['payload']['taskFrame']['mediaRefs']);
    }

    public function testMissingOrNonReciprocalTranscriptFailsClosed(): void
    {
        $fixture = new OfflineContextFixtures();
        $fixture->addPhotoFrame();
        unset($fixture->artifacts['transcript']);
        self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);

        $fixture = new OfflineContextFixtures();
        $fixture->addPhotoFrame();
        $fixture->addArtifact('transcript', 'transcript', 'Synthetic transcript.', ['mediaRef' => 'other-photo']);
        self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);
    }

    public function testFrameCannotPromoteAnUnknownEntityOrCarryPrivateMediaURLs(): void
    {
        $fixture = new OfflineContextFixtures();
        $fixture->addPhotoFrame();
        $frame = $fixture->artifacts['frame'];
        $frame['metadata']['entityRefs'] = ['unknown-entity'];
        $fixture->addArtifact('frame', 'frame', $frame['text'], $frame['metadata'], $frame['bindings']);
        self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);

        $fixture = new OfflineContextFixtures();
        $fixture->addPhotoFrame();
        $fixture->addArtifact('photo', 'media', '', [
            'mediaRef' => 'photo', 'transcriptRef' => 'transcript', 'url' => 'https://PRIVATE/storage',
        ]);
        self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);
    }

    public function testCurrentAndContextRefsAreMandatoryWithoutATaskFrameAndCountedInTheExactPayload(): void
    {
        $fixture = new OfflineContextFixtures();
        $result = $fixture->service()->prepare('offline', $fixture->request());
        self::assertSame('READY', $result['status']);
        $payload = $result['payload'];
        self::assertSame('assistant-context/2', $payload['schemaVersion']);
        self::assertNull($payload['taskFrame']);
        foreach (['currentRef', 'contextRef'] as $key) {
            self::assertMatchesRegularExpression('/^ref_[a-f0-9]{32}$/', $payload[$key]);
        }
        self::assertSame(strlen(OfflineContextFixtures::json($payload)), $result['tokenCount']);
        $receipt = $fixture->receipts[$payload['contextRef']]['receipt'];
        self::assertSame('current', $receipt['aliases'][$payload['currentRef']]['artifactRef']);
        self::assertSame(hash('sha256', OfflineContextFixtures::json($payload)), $receipt['payloadDigest']);
        self::assertSame('PRIVATE_REQUEST/1', $receipt['lineage']['requestRef']);
        self::assertStringNotContainsString('PRIVATE', OfflineContextFixtures::json($result));
        self::assertArrayNotHasKey('aliases', $payload);
        self::assertArrayNotHasKey('scopeHash', $payload);
    }

    public function testIdenticalTextsResolveByActualIssuedAliasesInsteadOfPositionOrText(): void
    {
        $fixture = new OfflineContextFixtures(2);
        $fixture->addArtifact('history-1', 'user', 'Одинаковый синтетический текст.');
        $fixture->addArtifact('current', 'user', 'Одинаковый синтетический текст.');
        $result = $fixture->service()->prepare('offline', $fixture->request());
        self::assertSame('READY', $result['status']);
        $payload = $result['payload'];
        $refs = [];
        foreach ($payload['messages'] as $message) {
            if ($message['content'] === 'Одинаковый синтетический текст.') {
                $refs[] = $fixture->resolve($payload['contextRef'], $message['ref'], producerResult: $result)['artifactRef'];
            }
        }
        self::assertEqualsCanonicalizing(['history-1', 'current'], $refs);
        self::assertSame('current', $fixture->resolve($payload['contextRef'], $payload['currentRef'], producerResult: $result)['artifactRef']);
        foreach ($payload['messages'] as $message) {
            $private = $fixture->resolve($payload['contextRef'], $message['ref'], producerResult: $result);
            $receipt = $fixture->receipts[$payload['contextRef']]['receipt'];
            foreach ($message['sourceRefs'] as $sourceAlias) {
                self::assertArrayHasKey($receipt['sources'][$sourceAlias]['sourceRef'], $private['fields']);
            }
        }
    }

    public function testBudgetRejectionIncludesNewMetadataAndPreventsPublication(): void
    {
        $fixture = new OfflineContextFixtures();
        $countedBodies = [];
        $fixture->onCount = static function (OfflineContextFixtures $fixture, string $payload, array $count) use (&$countedBodies): array {
            $body = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $countedBodies[] = $body;
            $reserve = $fixture->profile['answerReserve'] + $fixture->profile['toolReserve'];
            $count['tokens'] = $fixture->profile['contextWindow'] - $reserve + 1;

            return $count;
        };
        self::assertSame('BLOCKED', $fixture->service()->prepare('offline', $fixture->request())['status']);
        self::assertNotEmpty($countedBodies);
        foreach ($countedBodies as $body) {
            self::assertArrayHasKey('currentRef', $body);
            self::assertArrayHasKey('contextRef', $body);
        }
        self::assertSame([], $fixture->receipts);
        self::assertNotContains('stage', $fixture->publicationEvents);
    }
}
