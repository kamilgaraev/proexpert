<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\RasterPreprocessInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\RasterPreprocessingException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\RasterPreprocessor;
use App\Services\Logging\LoggingService;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DatabaseLessTestCase;

final class RasterPreprocessorTest extends DatabaseLessTestCase
{
    private RasterPreprocessor $preprocessor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->preprocessor = new RasterPreprocessor(new FileService($this->createMock(LoggingService::class)));
    }

    #[Test]
    public function it_normalizes_and_stores_a_private_content_addressed_derivative(): void
    {
        $source = $this->png(640, 320, [240, 240, 240]);
        Storage::disk('s3')->put('org-7/uploads/source.png', $source);

        $result = $this->preprocessor->preprocess($this->input());

        self::assertSame(640, $result->sourceWidth);
        self::assertSame(320, $result->sourceHeight);
        self::assertLessThanOrEqual(256, max($result->outputWidth, $result->outputHeight));
        self::assertMatchesRegularExpression('#^org-7/estimate-generation/11/vision/v1/[a-f0-9]{64}\.png$#', $result->derivativeStorageKey);
        self::assertSame('sha256:'.hash('sha256', Storage::disk('s3')->get($result->derivativeStorageKey)), $result->derivativeHash);
        self::assertSame('not_required', $result->perspectiveStatus);
        self::assertEqualsWithDelta([0.25, 0.75], $result->transform->toSource($result->transform->toDerivative([0.25, 0.75])), 0.000001);
    }

    #[Test]
    public function it_performs_real_projective_rectification_and_preserves_round_trip_mapping(): void
    {
        Storage::disk('s3')->put('org-7/uploads/source.png', $this->png(400, 300, [220, 220, 220]));
        $quad = [[0.10, 0.15], [0.90, 0.05], [0.80, 0.90], [0.20, 0.85]];

        $result = $this->preprocessor->preprocess($this->input($quad));

        self::assertSame('corrected', $result->perspectiveStatus);
        foreach ($quad as $point) {
            self::assertEqualsWithDelta($point, $result->transform->toSource($result->transform->toDerivative($point)), 0.00001);
        }
        self::assertNotEquals(1.0, $result->transform->determinant);
    }

    #[Test]
    public function it_rejects_cross_tenant_invalid_and_animated_sources(): void
    {
        Storage::disk('s3')->put('org-8/uploads/source.png', $this->png(10, 10, [0, 0, 0]));
        $this->expectException(RasterPreprocessingException::class);
        $this->preprocessor->preprocess($this->input(storageKey: 'org-8/uploads/source.png'));
    }

    #[Test]
    public function it_rejects_self_crossing_singular_repeated_and_outside_quadrilaterals(): void
    {
        Storage::disk('s3')->put('org-7/uploads/source.png', $this->png(40, 40, [0, 0, 0]));

        foreach ([
            [[0.1, 0.1], [0.9, 0.9], [0.9, 0.1], [0.1, 0.9]],
            [[0.1, 0.1], [0.1, 0.1], [0.9, 0.9], [0.1, 0.9]],
            [[0.1, 0.1], [0.2, 0.1], [0.3, 0.1], [0.4, 0.1]],
            [[-0.1, 0.1], [0.9, 0.1], [0.9, 0.9], [0.1, 0.9]],
        ] as $quad) {
            try {
                $this->preprocessor->preprocess($this->input($quad));
                self::fail('Invalid quadrilateral was accepted.');
            } catch (RasterPreprocessingException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function it_marks_missing_corners_for_review_when_perspective_is_required(): void
    {
        Storage::disk('s3')->put('org-7/uploads/source.png', $this->png(40, 40, [250, 250, 250]));

        $result = $this->preprocessor->preprocess($this->input(perspectiveRequired: true));

        self::assertSame('confirmation_required', $result->perspectiveStatus);
        self::assertContains('perspective_confirmation_required', $result->warnings);
    }

    #[Test]
    public function it_rejects_magic_mismatch_and_pixel_bombs_before_decode(): void
    {
        Storage::disk('s3')->put('org-7/uploads/source.png', 'not an image');
        try {
            $this->preprocessor->preprocess($this->input());
            self::fail('Invalid magic was accepted.');
        } catch (RasterPreprocessingException $exception) {
            self::assertSame('invalid_image_content', $exception->reason);
        }

        Storage::disk('s3')->put('org-7/uploads/source.png', $this->png(300, 300, [0, 0, 0]));
        $this->expectException(RasterPreprocessingException::class);
        $this->preprocessor->preprocess($this->input(maxPixels: 10_000));
    }

    #[Test]
    public function it_applies_all_exif_orientations_and_keeps_transform_invertible(): void
    {
        foreach (range(1, 8) as $orientation) {
            $key = "org-7/uploads/orientation-{$orientation}.jpg";
            Storage::disk('s3')->put($key, $this->jpegWithOrientation(80, 40, $orientation));
            $result = $this->preprocessor->preprocess($this->input(storageKey: $key, contentType: 'image/jpeg'));
            self::assertEqualsWithDelta([0.2, 0.7], $result->transform->toSource($result->transform->toDerivative([0.2, 0.7])), 0.000001);
            self::assertSame(in_array($orientation, [5, 6, 7, 8], true), $result->outputHeight > $result->outputWidth);
            $derivative = imagecreatefromstring(Storage::disk('s3')->get($result->derivativeStorageKey));
            [$markerX, $markerY] = $result->transform->toDerivative([0.1, 0.1]);
            $pixel = imagecolorat(
                $derivative,
                (int) round($markerX * ($result->outputWidth - 1)),
                (int) round($markerY * ($result->outputHeight - 1)),
            );
            self::assertLessThan(80, ($pixel >> 16) & 255, "Orientation {$orientation} transform does not match raster pixels.");
        }
    }

    #[Test]
    public function it_flattens_transparency_to_white_and_is_deterministic(): void
    {
        $image = imagecreatetruecolor(30, 20);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        Storage::disk('s3')->put('org-7/uploads/source.png', is_string($bytes) ? $bytes : '');

        $first = $this->preprocessor->preprocess($this->input());
        $second = $this->preprocessor->preprocess($this->input());
        $derivative = imagecreatefromstring(Storage::disk('s3')->get($first->derivativeStorageKey));
        $pixel = imagecolorat($derivative, 0, 0);

        self::assertSame($first->derivativeHash, $second->derivativeHash);
        self::assertGreaterThan(245, ($pixel >> 16) & 255);
        self::assertGreaterThan(245, ($pixel >> 8) & 255);
        self::assertGreaterThan(245, $pixel & 255);
    }

    #[Test]
    public function it_detects_tampered_derivative_and_animation(): void
    {
        Storage::disk('s3')->put('org-7/uploads/source.png', $this->png(40, 40, [100, 100, 100]));
        $result = $this->preprocessor->preprocess($this->input());
        Storage::disk('s3')->put($result->derivativeStorageKey, 'tampered');
        try {
            $this->preprocessor->preprocess($this->input());
            self::fail('Tampered derivative was accepted.');
        } catch (RasterPreprocessingException $exception) {
            self::assertSame('derivative_hash_collision', $exception->reason);
        }

        $animated = $this->png(10, 10, [0, 0, 0]).'acTL';
        Storage::disk('s3')->put('org-7/uploads/source.png', $animated);
        $this->expectException(RasterPreprocessingException::class);
        $this->preprocessor->preprocess($this->input());
    }

    /** @param array<int, array{0: float, 1: float}>|null $quad */
    private function input(?array $quad = null, bool $perspectiveRequired = false, string $storageKey = 'org-7/uploads/source.png', int $maxPixels = 1_000_000, string $contentType = 'image/png'): RasterPreprocessInput
    {
        $content = Storage::disk('s3')->exists($storageKey) ? Storage::disk('s3')->get($storageKey) : '';

        return new RasterPreprocessInput(7, 11, 13, 1, 'sha256:'.hash('sha256', $content), $storageKey, $contentType, $quad, $perspectiveRequired, 2_000_000, $maxPixels, 256);
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private function png(int $width, int $height, array $rgb): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        ob_start();
        imagepng($image, null, 6);
        $content = ob_get_clean();

        return is_string($content) ? $content : '';
    }

    private function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, (int) floor($width / 4), (int) floor($height / 4), imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();
        $tiff = "II\x2a\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00".pack('v', $orientation)."\x00\x00\x00\x00\x00\x00";
        $exif = "Exif\x00\x00".$tiff;
        $segment = "\xff\xe1".pack('n', strlen($exif) + 2).$exif;

        return substr((string) $jpeg, 0, 2).$segment.substr((string) $jpeg, 2);
    }
}
