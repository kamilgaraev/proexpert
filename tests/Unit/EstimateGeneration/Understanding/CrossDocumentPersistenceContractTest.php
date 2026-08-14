<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class CrossDocumentPersistenceContractTest extends TestCase
{
    #[Test]
    public function understanding_replay_is_idempotent_scoped_and_source_invalidation_removes_only_current_state(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $source = 'sha256:'.str_repeat('a', 64);
        $link = ['id' => 'link:1', 'status' => 'suggested'];
        $token = $repository->snapshotForUnderstanding(1, 2, 3, 1)['token'];

        $repository->replaceUnderstanding(1, 2, 3, $source, $token, $token, [$link], [], [], [], 1);
        $repository->replaceUnderstanding(1, 2, 3, $source, $token, $token, [$link], [], [], [], 1);

        self::assertSame([$link], $repository->currentUnderstanding(1, 2, 3)['links']);
        self::assertNull($repository->currentUnderstanding(9, 2, 3));
        $repository->invalidateSourceVersion(1, 2, 3, $source, 'sha256:'.str_repeat('b', 64));
        self::assertNull($repository->currentUnderstanding(1, 2, 3));

        $restored = $repository->replayUnderstanding(1, 2, 3, $source, $token, $token);
        self::assertNotNull($restored);
        self::assertSame([$link], $repository->currentUnderstanding(1, 2, 3)['links']);
    }

    #[Test]
    public function matching_fingerprint_with_different_immutable_payload_fails_fast(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $source = 'sha256:'.str_repeat('a', 64);
        $link = ['id' => 'link:1', 'status' => 'suggested'];
        $token = $repository->snapshotForUnderstanding(1, 2, 3, 1)['token'];

        $repository->replaceUnderstanding(1, 2, 3, $source, $token, $token, [$link], [], [], [], 1);
        $key = array_key_first($repository->understanding);
        self::assertIsString($key);
        $repository->understanding[$key]['links'] = [['id' => 'link:collision', 'status' => 'unresolved']];

        $this->expectException(InvalidArgumentException::class);
        $repository->replaceUnderstanding(1, 2, 3, $source, $token, $token, [$link], [], [], [], 1);
    }
}
