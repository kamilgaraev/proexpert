<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\RasterPreprocessInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\RasterPreprocessingException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\RasterPreprocessor;
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
        $files = $this->files();
        $this->preprocessor = new RasterPreprocessor($files, reader: new BoundedVersionedS3ObjectReader($files));
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
        $files = $this->createMock(FileService::class);
        $files->expects(self::never())->method('disk');
        $this->expectException(RasterPreprocessingException::class);
        (new RasterPreprocessor($files, reader: new BoundedVersionedS3ObjectReader($files)))->preprocess($this->input(storageKey: 'org-8/uploads/source.png'));
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
            self::assertSame('invalid_image_container', $exception->reason);
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
        $channels = imagecolorsforindex($derivative, $pixel);
        self::assertSame(0, $channels['alpha']);
    }

    #[Test]
    public function it_composites_semitransparent_rgb_onto_white_before_normalization(): void
    {
        $image = imagecreatetruecolor(30, 20);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 0, 0, 63));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        Storage::disk('s3')->put('org-7/uploads/source.png', is_string($bytes) ? $bytes : '');

        $result = $this->preprocessor->preprocess($this->input());
        $derivative = imagecreatefromstring(Storage::disk('s3')->get($result->derivativeStorageKey));
        $pixel = imagecolorat($derivative, 0, 0);
        $channels = imagecolorsforindex($derivative, $pixel);

        self::assertSame(0, $channels['alpha']);
        self::assertGreaterThan(100, $channels['red']);
        self::assertLessThan(245, $channels['red']);
    }

    #[Test]
    public function it_detects_a_tampered_derivative_as_terminal_integrity_failure(): void
    {
        Storage::disk('s3')->put('org-7/uploads/source.png', $this->png(40, 40, [100, 100, 100]));
        $result = $this->preprocessor->preprocess($this->input());
        Storage::disk('s3')->put($result->derivativeStorageKey, 'tampered');
        try {
            $this->preprocessor->preprocess($this->input());
            self::fail('Tampered derivative was accepted.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectLocatorException $exception) {
            self::assertSame('estimate_generation_derivative_integrity_failed', $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_animated_raster_sources(): void
    {
        $animated = $this->png(10, 10, [0, 0, 0]).'acTL';
        Storage::disk('s3')->put('org-7/uploads/source.png', $animated);
        $this->expectException(RasterPreprocessingException::class);
        $this->preprocessor->preprocess($this->input());
    }

    #[Test]
    public function quality_warnings_are_driven_by_real_blank_blur_and_low_contrast_pixels(): void
    {
        Storage::disk('s3')->put('org-7/uploads/source.png', $this->png(120, 80, [255, 255, 255]));
        $blank = $this->preprocessor->preprocess($this->input());
        self::assertContains('image_blurred', $blank->warnings);
        self::assertContains('low_contrast', $blank->warnings);
        self::assertContains('mostly_blank', $blank->warnings);

        Storage::disk('s3')->put('org-7/uploads/source.png', $this->lowContrastGrid());
        $lowContrast = $this->preprocessor->preprocess($this->input());
        self::assertContains('low_contrast', $lowContrast->warnings);
        self::assertNotContains('mostly_blank', $lowContrast->warnings);
    }

    #[Test]
    public function preprocessing_cannot_silently_return_the_original_color_raster(): void
    {
        $image = imagecreatetruecolor(100, 50);
        imagefilledrectangle($image, 0, 0, 49, 49, imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, 50, 0, 99, 49, imagecolorallocate($image, 0, 0, 255));
        ob_start();
        imagepng($image);
        $source = ob_get_clean();
        $source = is_string($source) ? $source : '';
        Storage::disk('s3')->put('org-7/uploads/source.png', $source);

        $result = $this->preprocessor->preprocess($this->input());
        $outputBytes = Storage::disk('s3')->get($result->derivativeStorageKey);
        $output = imagecreatefromstring($outputBytes);
        $color = imagecolorsforindex($output, imagecolorat($output, 20, 20));

        self::assertNotSame(hash('sha256', $source), substr($result->derivativeHash, 7));
        self::assertSame($color['red'], $color['green']);
        self::assertSame($color['green'], $color['blue']);
        self::assertSame(0, $color['alpha']);
    }

    #[Test]
    public function real_trapezoid_grid_maps_colored_corners_and_preserves_rectified_aspect(): void
    {
        $quad = [[0.10, 0.15], [0.90, 0.05], [0.80, 0.90], [0.20, 0.85]];
        Storage::disk('s3')->put('org-7/uploads/source.png', $this->trapezoidGrid(400, 300, $quad));

        $result = $this->preprocessor->preprocess($this->input($quad));
        $output = imagecreatefromstring(Storage::disk('s3')->get($result->derivativeStorageKey));
        $samples = [
            imagecolorsforindex($output, imagecolorat($output, 2, 2))['red'],
            imagecolorsforindex($output, imagecolorat($output, $result->outputWidth - 3, 2))['red'],
            imagecolorsforindex($output, imagecolorat($output, $result->outputWidth - 3, $result->outputHeight - 3))['red'],
            imagecolorsforindex($output, imagecolorat($output, 2, $result->outputHeight - 3))['red'],
        ];

        self::assertSame('corrected', $result->perspectiveStatus);
        self::assertLessThan($samples[1], $samples[0]);
        self::assertLessThan($samples[2], $samples[1]);
        self::assertLessThan($samples[3], $samples[2]);
        self::assertGreaterThan(1.1, $result->outputWidth / $result->outputHeight);
        self::assertLessThan(1.6, $result->outputWidth / $result->outputHeight);
        foreach ($quad as $corner) {
            self::assertEqualsWithDelta($corner, $result->transform->toSource($result->transform->toDerivative($corner)), 0.00001);
        }
    }

    #[Test]
    public function composed_orientation_perspective_and_final_scale_remain_invertible(): void
    {
        $quad = [[0.10, 0.10], [0.90, 0.15], [0.85, 0.90], [0.15, 0.85]];
        Storage::disk('s3')->put('org-7/uploads/source.jpg', $this->jpegWithOrientation(900, 600, 6));

        $result = $this->preprocessor->preprocess($this->input($quad, storageKey: 'org-7/uploads/source.jpg', maxPixels: 1_000_000, contentType: 'image/jpeg'));

        self::assertLessThanOrEqual(256, max($result->outputWidth, $result->outputHeight));
        foreach ([[0.2, 0.2], [0.5, 0.5], [0.8, 0.8]] as $point) {
            self::assertEqualsWithDelta($point, $result->transform->toSource($result->transform->toDerivative($point)), 0.00001);
        }
    }

    #[Test]
    public function near_production_perspective_is_deterministically_capped_under_time_and_memory_budget(): void
    {
        Storage::disk('s3')->put('org-7/uploads/source.png', $this->png(2200, 1800, [210, 210, 210]));
        $quad = [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]];
        $started = hrtime(true);
        $memory = memory_get_usage(true);

        $result = $this->preprocessor->preprocess($this->input($quad, maxPixels: 5_000_000, maxDimension: 5000));
        $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;

        self::assertLessThanOrEqual(4_000_000, $result->outputWidth * $result->outputHeight);
        self::assertLessThan(20.0, $elapsedSeconds);
        self::assertLessThan(300 * 1024 * 1024, memory_get_peak_usage(true) - $memory);
    }

    /** @param array<int, array{0: float, 1: float}>|null $quad */
    private function input(?array $quad = null, bool $perspectiveRequired = false, string $storageKey = 'org-7/uploads/source.png', int $maxPixels = 1_000_000, string $contentType = 'image/png', int $maxDimension = 256): RasterPreprocessInput
    {
        $content = Storage::disk('s3')->exists($storageKey) ? Storage::disk('s3')->get($storageKey) : '';

        $sha = 'sha256:'.hash('sha256', $content);

        return new RasterPreprocessInput(7, 11, 13, 1, $sha, $storageKey, $contentType, max(1, strlen($content)), $sha, 'test-version', $quad, $perspectiveRequired, 20_000_000, $maxPixels, $maxDimension);
    }

    private function files(): FileService
    {
        return new class extends FileService
        {
            public function __construct() {}

            public function describeVersion(string $path, ?string $versionId, int $maxBytes = 64_000_000): array
            {
                $body = Storage::disk('s3')->get($path);

                return ['path' => $path, 'body' => $body, 'size' => strlen($body), 'sha256' => hash('sha256', $body),
                    'etag' => null, 'version_id' => 'test-version', 'content_type' => 'image/png'];
            }

            public function putImmutable(string $path, string $body, string $contentType): array
            {
                $created = ! Storage::disk('s3')->exists($path);
                if ($created) {
                    Storage::disk('s3')->put($path, $body);
                }
                $stored = Storage::disk('s3')->get($path);

                return ['path' => $path, 'body' => $stored, 'size' => strlen($stored), 'sha256' => hash('sha256', $stored),
                    'etag' => null, 'version_id' => 'derivative-version', 'content_type' => $contentType, 'created' => $created];
            }
        };
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

    private function lowContrastGrid(): string
    {
        $image = imagecreatetruecolor(120, 80);
        for ($y = 0; $y < 80; $y++) {
            for ($x = 0; $x < 120; $x++) {
                $value = (($x + $y) % 2 === 0) ? 120 : 126;
                imagesetpixel($image, $x, $y, imagecolorallocate($image, $value, $value, $value));
            }
        }
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();

        return is_string($bytes) ? $bytes : '';
    }

    /** @param array<int, array{0: float, 1: float}> $quad */
    private function trapezoidGrid(int $width, int $height, array $quad): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $points = array_map(static fn (array $point): array => [(int) round($point[0] * ($width - 1)), (int) round($point[1] * ($height - 1))], $quad);
        foreach ([20, 80, 150, 225] as $index => $value) {
            [$x, $y] = $points[$index];
            imagefilledellipse($image, $x, $y, 24, 24, imagecolorallocate($image, $value, $value, $value));
        }
        foreach (range(1, 4) as $step) {
            $t = $step / 5;
            $top = [$points[0][0] + ($points[1][0] - $points[0][0]) * $t, $points[0][1] + ($points[1][1] - $points[0][1]) * $t];
            $bottom = [$points[3][0] + ($points[2][0] - $points[3][0]) * $t, $points[3][1] + ($points[2][1] - $points[3][1]) * $t];
            imageline($image, (int) $top[0], (int) $top[1], (int) $bottom[0], (int) $bottom[1], imagecolorallocate($image, 40, 40, 40));
        }
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();

        return is_string($bytes) ? $bytes : '';
    }
}
