<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\RasterPreprocessResult;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\RasterPreprocessingException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\ProjectiveTransformFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RasterPreprocessResultTest extends TestCase
{
    #[Test]
    public function result_rejects_unknown_status_warning_nonfinite_metrics_and_bad_hash_dimensions(): void
    {
        foreach ([
            ['perspectiveStatus' => 'silent_noop'],
            ['warnings' => ['unknown_warning']],
            ['sharpness' => NAN],
            ['derivativeHash' => 'bad'],
            ['outputWidth' => 0],
            ['perspectiveStatus' => 'confirmation_required', 'warnings' => []],
        ] as $override) {
            try {
                $this->makeResult($override);
                self::fail('Invalid preprocess result was accepted.');
            } catch (RasterPreprocessingException $exception) {
                self::assertSame('invalid_preprocess_result', $exception->reason);
            }
        }
    }

    /** @param array<string, mixed> $override */
    private function makeResult(array $override): RasterPreprocessResult
    {
        $data = array_replace([
            'derivativeStorageKey' => 'org-7/estimate-generation/11/vision/v1/'.str_repeat('a', 64).'.png',
            'derivativeHash' => 'sha256:'.str_repeat('a', 64), 'derivativeVersion' => 'raster-preprocessor:v1',
            'derivativeBytes' => 100, 'derivativeVersionId' => 'version-1',
            'sourceWidth' => 100, 'sourceHeight' => 80, 'outputWidth' => 100, 'outputHeight' => 80,
            'sharpness' => 0.2, 'dynamicRange' => 0.8, 'blankRatio' => 0.1, 'clippingRatio' => 0.1,
            'skewDegrees' => null, 'perspectiveStatus' => 'not_required', 'warnings' => [],
        ], $override);

        return new RasterPreprocessResult(
            $data['derivativeStorageKey'], $data['derivativeHash'], $data['derivativeVersion'],
            $data['derivativeBytes'], $data['derivativeVersionId'],
            $data['sourceWidth'], $data['sourceHeight'], $data['outputWidth'], $data['outputHeight'],
            $data['sharpness'], $data['dynamicRange'], $data['blankRatio'], $data['clippingRatio'],
            $data['skewDegrees'], $data['perspectiveStatus'], (new ProjectiveTransformFactory)->identity(), $data['warnings'],
        );
    }
}
