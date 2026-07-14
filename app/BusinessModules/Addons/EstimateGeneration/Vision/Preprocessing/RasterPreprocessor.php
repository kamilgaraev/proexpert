<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing;

use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectLocatorException;
use App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectTransportException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\ProjectiveTransformData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\RasterPreprocessInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\RasterPreprocessResult;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\RasterPreprocessingException;
use App\Services\Storage\FileService;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Throwable;

final readonly class RasterPreprocessor
{
    public const VERSION = 'raster-preprocessor:v1';

    private const MAX_PERSPECTIVE_OUTPUT_PIXELS = 4_000_000;

    public function __construct(
        private FileService $files,
        private BoundedVersionedS3ObjectReader $reader,
        private ProjectiveTransformFactory $transforms = new ProjectiveTransformFactory,
        private RasterAnimationInspector $animation = new RasterAnimationInspector,
    ) {}

    public function preprocess(RasterPreprocessInput $input): RasterPreprocessResult
    {
        $this->assertTenantKey($input);
        $bytes = $this->reader->read($input->organizationId, $input->storageKey, $input->maxBytes, $input->sourceBytes, $input->sourceSha256, $input->sourceVersionId)->body;
        $this->animation->assertSingleFrame($bytes, $input->contentType);
        $dimensions = @getimagesizefromstring($bytes);
        $mime = is_array($dimensions) ? ($dimensions['mime'] ?? null) : null;
        if (! is_array($dimensions) || ! is_string($mime) || $mime !== $input->contentType) {
            throw new RasterPreprocessingException('invalid_image_content');
        }
        [$sourceWidth, $sourceHeight] = [$dimensions[0], $dimensions[1]];
        if ($sourceWidth < 1 || $sourceHeight < 1 || $sourceWidth > 50_000 || $sourceHeight > 50_000
            || $sourceWidth * $sourceHeight > $input->maxPixels) {
            throw new RasterPreprocessingException('unsafe_image_dimensions');
        }

        try {
            $manager = ImageManager::withDriver(GdDriver::class, autoOrientation: false);
            $image = $manager->read($bytes);
            $orientation = $this->exifOrientation($bytes, $mime);
            $orientationTransform = $this->orientationTransform($orientation);
            $image = match ($orientation) {
                2 => $image->flop(),
                3 => $image->rotate(180),
                4 => $image->rotate(180)->flop(),
                5 => $image->rotate(270)->flop(),
                6 => $image->rotate(270),
                7 => $image->rotate(90)->flop(),
                8 => $image->rotate(90),
                default => $image,
            };
            $orientedWidth = $image->width();
            $orientedHeight = $image->height();
            $preScale = min(1.0, $input->maxDimension * 2 / max($orientedWidth, $orientedHeight));
            if ($preScale < 1.0) {
                $image->scaleDown((int) floor($orientedWidth * $preScale), (int) floor($orientedHeight * $preScale));
            }
            $normalized = (string) $image->toPng(indexed: false, interlaced: false);
        } catch (Throwable $exception) {
            throw new RasterPreprocessingException('image_decode_failed');
        }

        $warnings = [];
        $perspectiveStatus = 'not_required';
        $skewDegrees = null;
        $transform = $orientationTransform;
        if ($input->perspectiveQuadrilateral !== null) {
            $orientedQuad = array_map($orientationTransform->toDerivative(...), $input->perspectiveQuadrilateral);
            $rect = [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]];
            $perspective = $this->transforms->between($orientedQuad, $rect);
            $transform = $this->compose($orientationTransform, $perspective);
            $normalized = $this->rectify($normalized, $perspective, $input->maxDimension, min($input->maxPixels, self::MAX_PERSPECTIVE_OUTPUT_PIXELS));
            $skewDegrees = round(rad2deg(atan2(
                ($orientedQuad[1][1] - $orientedQuad[0][1]) * $orientedHeight,
                ($orientedQuad[1][0] - $orientedQuad[0][0]) * $orientedWidth,
            )), 6);
            $perspectiveStatus = 'corrected';
        } elseif ($input->perspectiveRequired) {
            $perspectiveStatus = 'confirmation_required';
            $warnings[] = 'perspective_confirmation_required';
        }

        $normalized = $this->flattenToOpaqueWhite($normalized);
        $output = $manager->read($normalized)->greyscale()->contrast(12)->scaleDown($input->maxDimension, $input->maxDimension);
        $outputBytes = (string) $output->toPng(indexed: false, interlaced: false);
        $hash = hash('sha256', $outputBytes);
        $directory = "estimate-generation/{$input->sessionId}/vision/v1";
        $filename = "{$hash}.png";
        $key = "org-{$input->organizationId}/{$directory}/{$filename}";
        try {
            $stored = $this->files->putImmutable($key, $outputBytes, 'image/png');
        } catch (Throwable $exception) {
            throw new S3ObjectTransportException('estimate_generation_derivative_storage_unavailable', 0, $exception);
        }
        if ($stored['size'] !== strlen($outputBytes) || ! hash_equals($hash, $stored['sha256'])) {
            throw new S3ObjectLocatorException('estimate_generation_derivative_integrity_failed');
        }
        [$sharpness, $dynamicRange, $blankRatio, $clippingRatio] = $this->quality($outputBytes);
        if ($sharpness < 0.015) {
            $warnings[] = 'image_blurred';
        }
        if ($dynamicRange < 0.08) {
            $warnings[] = 'low_contrast';
        }
        if ($blankRatio > 0.98) {
            $warnings[] = 'mostly_blank';
        }

        return new RasterPreprocessResult(
            $key, "sha256:{$hash}", self::VERSION, $stored['size'], (string) $stored['version_id'], $sourceWidth, $sourceHeight,
            $output->width(), $output->height(), $sharpness, $dynamicRange, $blankRatio, $clippingRatio,
            $skewDegrees, $perspectiveStatus, $transform, array_values(array_unique($warnings)),
        );
    }

    private function assertTenantKey(RasterPreprocessInput $input): void
    {
        if (! str_starts_with($input->storageKey, "org-{$input->organizationId}/")
            || str_contains($input->storageKey, '..') || str_contains($input->storageKey, "\0")) {
            throw new RasterPreprocessingException('cross_tenant_source');
        }
    }

    private function exifOrientation(string $bytes, string $mime): int
    {
        if ($mime !== 'image/jpeg') {
            return 1;
        }
        $position = strpos($bytes, "Exif\0\0");
        if ($position === false || $position + 14 > strlen($bytes)) {
            return 1;
        }
        $tiff = $position + 6;
        $byteOrder = substr($bytes, $tiff, 2);
        if (! in_array($byteOrder, ['II', 'MM'], true)) {
            return 1;
        }
        $littleEndian = $byteOrder === 'II';
        $read16 = static function (string $data, int $offset) use ($littleEndian): ?int {
            if ($offset < 0 || $offset + 2 > strlen($data)) {
                return null;
            }
            $value = unpack($littleEndian ? 'vvalue' : 'nvalue', substr($data, $offset, 2));

            return is_array($value) ? (int) $value['value'] : null;
        };
        $read32 = static function (string $data, int $offset) use ($littleEndian): ?int {
            if ($offset < 0 || $offset + 4 > strlen($data)) {
                return null;
            }
            $value = unpack($littleEndian ? 'Vvalue' : 'Nvalue', substr($data, $offset, 4));

            return is_array($value) ? (int) $value['value'] : null;
        };
        if ($read16($bytes, $tiff + 2) !== 42) {
            return 1;
        }
        $ifdOffset = $read32($bytes, $tiff + 4);
        if ($ifdOffset === null || $ifdOffset < 8 || $ifdOffset > 65_536) {
            return 1;
        }
        $ifd = $tiff + $ifdOffset;
        $entryCount = $read16($bytes, $ifd);
        if ($entryCount === null || $entryCount > 256) {
            return 1;
        }
        for ($entry = 0; $entry < $entryCount; $entry++) {
            $offset = $ifd + 2 + $entry * 12;
            if ($read16($bytes, $offset) !== 0x0112 || $read16($bytes, $offset + 2) !== 3 || $read32($bytes, $offset + 4) !== 1) {
                continue;
            }
            $orientation = $read16($bytes, $offset + 8);

            return $orientation !== null && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
        }

        return 1;
    }

    private function orientationTransform(int $orientation): ProjectiveTransformData
    {
        $source = [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]];
        $destinations = [
            2 => [[1.0, 0.0], [0.0, 0.0], [0.0, 1.0], [1.0, 1.0]],
            3 => [[1.0, 1.0], [0.0, 1.0], [0.0, 0.0], [1.0, 0.0]],
            4 => [[0.0, 1.0], [1.0, 1.0], [1.0, 0.0], [0.0, 0.0]],
            5 => [[0.0, 0.0], [0.0, 1.0], [1.0, 1.0], [1.0, 0.0]],
            6 => [[1.0, 0.0], [1.0, 1.0], [0.0, 1.0], [0.0, 0.0]],
            7 => [[1.0, 1.0], [1.0, 0.0], [0.0, 0.0], [0.0, 1.0]],
            8 => [[0.0, 1.0], [0.0, 0.0], [1.0, 0.0], [1.0, 1.0]],
        ];

        return isset($destinations[$orientation])
            ? $this->transforms->between($source, $destinations[$orientation])
            : $this->transforms->identity();
    }

    private function flattenToOpaqueWhite(string $bytes): string
    {
        $source = imagecreatefromstring($bytes);
        if (! $source instanceof \GdImage) {
            throw new RasterPreprocessingException('image_decode_failed');
        }
        $target = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagealphablending($target, true);
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        imagealphablending($source, true);
        imagecopy($target, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
        ob_start();
        imagepng($target, null, 6);
        $result = ob_get_clean();

        return is_string($result) ? $result : throw new RasterPreprocessingException('image_encode_failed');
    }

    private function compose(ProjectiveTransformData $first, ProjectiveTransformData $second): ProjectiveTransformData
    {
        $forward = $this->multiply($second->sourceToDerivative, $first->sourceToDerivative);
        $inverse = $this->multiply($first->derivativeToSource, $second->derivativeToSource);
        $det = $this->determinant($forward);
        $condition = $this->norm($forward) * $this->norm($inverse);

        return new ProjectiveTransformData($forward, $inverse, $det, $condition);
    }

    private function rectify(string $bytes, ProjectiveTransformData $transform, int $maxDimension, int $maxPixels): string
    {
        $source = imagecreatefromstring($bytes);
        if (! $source instanceof \GdImage) {
            throw new RasterPreprocessingException('perspective_decode_failed');
        }
        $corners = [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]];
        $sourceCorners = array_map($transform->toSource(...), $corners);
        $distance = static fn (array $a, array $b): float => hypot(
            ($b[0] - $a[0]) * imagesx($source),
            ($b[1] - $a[1]) * imagesy($source),
        );
        $desiredWidth = max(1.0, ($distance($sourceCorners[0], $sourceCorners[1]) + $distance($sourceCorners[3], $sourceCorners[2])) / 2.0);
        $desiredHeight = max(1.0, ($distance($sourceCorners[0], $sourceCorners[3]) + $distance($sourceCorners[1], $sourceCorners[2])) / 2.0);
        $scale = min(1.0, $maxDimension / max($desiredWidth, $desiredHeight), sqrt($maxPixels / ($desiredWidth * $desiredHeight)));
        $width = max(1, (int) round($desiredWidth * $scale));
        $height = max(1, (int) round($desiredHeight * $scale));
        $target = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                [$sx, $sy] = $transform->toSource([$x / max(1, $width - 1), $y / max(1, $height - 1)]);
                $sourceX = (int) round($sx * (imagesx($source) - 1));
                $sourceY = (int) round($sy * (imagesy($source) - 1));
                if ($sourceX >= 0 && $sourceY >= 0 && $sourceX < imagesx($source) && $sourceY < imagesy($source)) {
                    imagesetpixel($target, $x, $y, imagecolorat($source, $sourceX, $sourceY));
                }
            }
        }
        ob_start();
        imagepng($target, null, 6);
        $result = ob_get_clean();

        return is_string($result) ? $result : throw new RasterPreprocessingException('perspective_encode_failed');
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private function quality(string $bytes): array
    {
        $image = imagecreatefromstring($bytes);
        if (! $image instanceof \GdImage) {
            throw new RasterPreprocessingException('quality_decode_failed');
        }
        $step = max(1, (int) ceil(sqrt(imagesx($image) * imagesy($image) / 10_000)));
        $values = [];
        $edges = 0.0;
        $edgeCount = 0;
        $blank = $clipped = 0;
        for ($y = 0; $y < imagesy($image); $y += $step) {
            $previous = null;
            for ($x = 0; $x < imagesx($image); $x += $step) {
                $rgb = imagecolorat($image, $x, $y);
                $value = ((($rgb >> 16) & 255) + (($rgb >> 8) & 255) + ($rgb & 255)) / 765;
                $values[] = $value;
                $blank += $value > 0.97 ? 1 : 0;
                $clipped += ($value < 0.01 || $value > 0.99) ? 1 : 0;
                if ($previous !== null) {
                    $edges += abs($value - $previous);
                    $edgeCount++;
                }
                $previous = $value;
            }
        }
        sort($values);
        $count = count($values);
        $low = $values[(int) floor(($count - 1) * 0.05)] ?? 0.0;
        $high = $values[(int) floor(($count - 1) * 0.95)] ?? 0.0;

        return [round($edges / max(1, $edgeCount), 6), round($high - $low, 6), round($blank / max(1, $count), 6), round($clipped / max(1, $count), 6)];
    }

    /** @param array<int, array<int, float>> $a @param array<int, array<int, float>> $b @return array<int, array<int, float>> */
    private function multiply(array $a, array $b): array
    {
        $result = array_fill(0, 3, array_fill(0, 3, 0.0));
        for ($row = 0; $row < 3; $row++) {
            for ($column = 0; $column < 3; $column++) {
                for ($i = 0; $i < 3; $i++) {
                    $result[$row][$column] += $a[$row][$i] * $b[$i][$column];
                }
            }
        }

        return $result;
    }

    /** @param array<int, array<int, float>> $m */
    private function determinant(array $m): float
    {
        return $m[0][0] * ($m[1][1] * $m[2][2] - $m[1][2] * $m[2][1]) - $m[0][1] * ($m[1][0] * $m[2][2] - $m[1][2] * $m[2][0]) + $m[0][2] * ($m[1][0] * $m[2][1] - $m[1][1] * $m[2][0]);
    }

    /** @param array<int, array<int, float>> $m */
    private function norm(array $m): float
    {
        return sqrt(array_sum(array_map(static fn (array $row): float => array_sum(array_map(static fn (float $v): float => $v * $v, $row)), $m)));
    }
}
