<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\RasterPreprocessingException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\BoundedStorageReader;
use Illuminate\Contracts\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BoundedStorageReaderTest extends TestCase
{
    #[Test]
    public function metadata_oversize_is_rejected_before_any_content_read(): void
    {
        $disk = $this->createMock(Filesystem::class);
        $disk->expects(self::once())->method('size')->with('org-7/source.png')->willReturn(101);
        $disk->expects(self::never())->method('readStream');
        $disk->expects(self::never())->method('get');

        $this->expectException(RasterPreprocessingException::class);
        (new BoundedStorageReader)->read($disk, 'org-7/source.png', 100);
    }

    #[Test]
    public function stream_is_bounded_to_max_plus_one_even_when_metadata_lies(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, str_repeat('x', 1_000));
        rewind($stream);
        $disk = $this->createMock(Filesystem::class);
        $disk->expects(self::once())->method('size')->willReturn(100);
        $disk->expects(self::once())->method('readStream')->willReturn($stream);
        $disk->expects(self::never())->method('get');

        try {
            (new BoundedStorageReader)->read($disk, 'org-7/source.png', 100);
            self::fail('Oversized stream was accepted.');
        } catch (RasterPreprocessingException $exception) {
            self::assertSame('invalid_image_size', $exception->reason);
        }
    }

    #[Test]
    public function exact_metadata_and_stream_length_are_required(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'abc');
        rewind($stream);
        $disk = $this->createMock(Filesystem::class);
        $disk->method('size')->willReturn(3);
        $disk->method('readStream')->willReturn($stream);

        self::assertSame('abc', (new BoundedStorageReader)->read($disk, 'org-7/source.png', 3));
    }
}
