<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

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

        $repository->replaceUnderstanding(1, 2, 3, $source, [$link], [], [], [], 1);
        $repository->replaceUnderstanding(1, 2, 3, $source, [$link], [], [], [], 1);

        self::assertSame([$link], $repository->currentUnderstanding(1, 2, 3)['links']);
        self::assertNull($repository->currentUnderstanding(9, 2, 3));
        $repository->invalidateSourceVersion(1, 2, 3, $source, 'sha256:'.str_repeat('b', 64));
        self::assertNull($repository->currentUnderstanding(1, 2, 3));
    }
}
