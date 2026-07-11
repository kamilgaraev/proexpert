<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\RasterPreprocessingException;

final readonly class RasterPreprocessResult
{
    /** @param list<string> $warnings */
    public function __construct(
        public string $derivativeStorageKey,
        public string $derivativeHash,
        public string $derivativeVersion,
        public int $sourceWidth,
        public int $sourceHeight,
        public int $outputWidth,
        public int $outputHeight,
        public float $sharpness,
        public float $dynamicRange,
        public float $blankRatio,
        public float $clippingRatio,
        public ?float $skewDegrees,
        public string $perspectiveStatus,
        public ProjectiveTransformData $transform,
        public array $warnings,
    ) {
        $allowedWarnings = ['perspective_confirmation_required', 'image_blurred', 'low_contrast', 'mostly_blank'];
        if (preg_match('#^org-[1-9][0-9]*/estimate-generation/[1-9][0-9]*/vision/v1/[a-f0-9]{64}\.png$#', $derivativeStorageKey) !== 1
            || preg_match('/^sha256:[a-f0-9]{64}$/', $derivativeHash) !== 1
            || ! str_ends_with($derivativeStorageKey, substr($derivativeHash, 7).'.png')
            || $derivativeVersion !== 'raster-preprocessor:v1'
            || min($sourceWidth, $sourceHeight, $outputWidth, $outputHeight) < 1
            || max($sourceWidth, $sourceHeight) > 50_000 || max($outputWidth, $outputHeight) > 8192
            || ! is_finite($sharpness) || $sharpness < 0.0 || $sharpness > 1.0
            || ! is_finite($dynamicRange) || $dynamicRange < 0.0 || $dynamicRange > 1.0
            || ! is_finite($blankRatio) || $blankRatio < 0.0 || $blankRatio > 1.0
            || ! is_finite($clippingRatio) || $clippingRatio < 0.0 || $clippingRatio > 1.0
            || ($skewDegrees !== null && (! is_finite($skewDegrees) || $skewDegrees < -180.0 || $skewDegrees > 180.0))
            || ! in_array($perspectiveStatus, ['not_required', 'corrected', 'confirmation_required'], true)
            || ($perspectiveStatus === 'corrected') !== ($skewDegrees !== null)
            || array_diff($warnings, $allowedWarnings) !== [] || count($warnings) !== count(array_unique($warnings))
            || ($perspectiveStatus === 'confirmation_required') !== in_array('perspective_confirmation_required', $warnings, true)) {
            throw new RasterPreprocessingException('invalid_preprocess_result');
        }
    }
}
