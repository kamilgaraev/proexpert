<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionEvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionScaleCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VisionScaleInvariantTest extends TestCase
{
    #[Test]
    public function zero_candidates_require_exactly_scale_missing(): void
    {
        self::assertSame('floor_plan', $this->analysis([], ['scale_missing'])->sheetType);
        $this->assertInvalid([], []);
    }

    #[Test]
    public function one_or_near_equal_candidates_forbid_scale_conflict(): void
    {
        self::assertCount(1, $this->analysis([$this->scale(1.0)], [])->scaleCandidates);
        self::assertCount(2, $this->analysis([$this->scale(1.0), $this->scale(1.019)], [])->scaleCandidates);
        $this->assertInvalid([$this->scale(1.0)], ['scale_conflict']);
        $this->assertInvalid([$this->scale(1.0), $this->scale(1.019)], ['scale_conflict']);
    }

    #[Test]
    public function materially_distinct_candidates_require_scale_conflict_at_two_percent_tolerance(): void
    {
        self::assertCount(2, $this->analysis([$this->scale(1.0), $this->scale(1.021)], ['scale_conflict'])->scaleCandidates);
        $this->assertInvalid([$this->scale(1.0), $this->scale(1.021)], []);
    }

    /** @param list<VisionScaleCandidateData> $scales @param list<string> $warnings */
    private function analysis(array $scales, array $warnings): VisionAnalysisData
    {
        return new VisionAnalysisData(
            'floor_plan', [new VisionEvidenceData('page-1', [
                'page_id' => 17, 'page_number' => 2, 'processing_unit_id' => 19,
                'source_version' => 'sha256:'.str_repeat('a', 64), 'coordinate_space' => 'normalized_source_v1',
            ])], [], $scales, $warnings, 'timeweb', 'vision/model-v1', 'vision/model-v1', '2026-07-11',
            'unavailable', null, null,
        );
    }

    private function scale(float $value): VisionScaleCandidateData
    {
        return new VisionScaleCandidateData('dimension_text', $value, 0.8, 'page-1', 'visible_dimension');
    }

    /** @param list<VisionScaleCandidateData> $scales @param list<string> $warnings */
    private function assertInvalid(array $scales, array $warnings): void
    {
        try {
            $this->analysis($scales, $warnings);
            self::fail('Invalid scale warning state was accepted.');
        } catch (VisionContractException) {
            self::assertTrue(true);
        }
    }
}
