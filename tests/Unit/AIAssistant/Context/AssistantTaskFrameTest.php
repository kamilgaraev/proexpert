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
}
