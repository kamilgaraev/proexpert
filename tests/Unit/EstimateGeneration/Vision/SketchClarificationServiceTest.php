<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchClarificationData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchClarificationService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SketchClarificationServiceTest extends TestCase
{
    #[Test]
    public function questions_have_deterministic_business_order(): void
    {
        self::assertSame([
            'footprint_or_area', 'floor_count', 'floor_height', 'wall_material',
            'foundation_type', 'roof_type', 'finish_level', 'region',
        ], array_column((new SketchClarificationService)->missingQuestions(new SketchClarificationData([])), 'key'));
    }

    #[Test]
    public function catalog_defaults_are_never_evidenced_and_require_confirmation(): void
    {
        $assumption = (new SketchClarificationService)->assumption('roof_type', 'gable', 'catalog_default', 0.8, null, false);

        self::assertTrue($assumption->requiresConfirmation);
        self::assertFalse($assumption->evidenced);
    }

    #[Test]
    public function confirmed_user_answer_requires_evidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SketchClarificationService)->assumption('floor_count', 2, 'user', 1.0, null, true);
    }

    #[Test]
    public function values_are_strictly_validated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SketchClarificationData(['floor_count' => 0]);
    }
}
