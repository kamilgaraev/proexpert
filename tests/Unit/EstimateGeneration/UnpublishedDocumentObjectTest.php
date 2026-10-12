<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UnpublishedDocumentObject;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UnpublishedDocumentObjectBatch;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnpublishedDocumentObjectTest extends TestCase
{
    private const FILE = '123e4567-e89b-42d3-a456-426614174000.pdf';

    public function test_batch_retains_each_destination_and_deduplicates_the_same_immutable_identity(): void
    {
        $batch = new UnpublishedDocumentObjectBatch(10, 20, 30);
        $path = 'org-10/estimate-generation/sessions/30/documents/'.self::FILE;
        $batch->capture($path, str_repeat('a', 64), 100);
        $batch->capture($path, str_repeat('a', 64), 100);
        self::assertCount(1, $batch->objects());
        self::assertSame($path, $batch->objects()[0]->path);
        $this->expectException(InvalidArgumentException::class);
        $batch->capture($path, str_repeat('b', 64), 100);
    }

    #[DataProvider('unsafePaths')]
    public function test_cleanup_target_cannot_be_a_foreign_source_or_user_named_file(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UnpublishedDocumentObject(10, 20, 30, $path, str_repeat('a', 64), 100);
    }

    public static function unsafePaths(): array
    {
        return [['org-11/estimate-generation/sessions/30/documents/'.self::FILE],
            ['org-10/estimate-generation/sessions/31/documents/'.self::FILE],
            ['org-10/estimate-generation/sessions/30/documents/user-plan.pdf'],
            ['org-10/estimate-generation/sessions/30/documents/../'.self::FILE],
            ['org-10/estimate-generation/sessions/30/derivatives/'.self::FILE],
            ['org-10/estimate-generation/sessions/30/documents/folder/'.self::FILE]];
    }
}
