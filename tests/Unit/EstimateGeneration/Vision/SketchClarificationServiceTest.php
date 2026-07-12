<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchAssumption;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchClarificationData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchClarificationService;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchProvenanceData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchQuestionData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchValueData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SketchClarificationServiceTest extends TestCase
{
    #[Test]
    public function questions_have_exact_deterministic_business_order(): void
    {
        self::assertSame(SketchQuestionData::KEYS, array_map(
            static fn (SketchQuestionData $item): string => $item->key,
            (new SketchClarificationService)->missingQuestions(new SketchClarificationData([])),
        ));
    }

    #[Test]
    public function catalog_defaults_are_never_evidenced_and_require_confirmation(): void
    {
        $assumption = (new SketchClarificationService)->assumption(
            new SketchValueData('roof_type', 'gable'), self::catalogProvenance(), 0.8, false,
        );

        self::assertTrue($assumption->requiresConfirmation);
        self::assertFalse($assumption->evidenced);
        self::assertNull($assumption->evidenceId);
    }

    #[Test]
    public function confirmed_user_answer_has_typed_identity_and_immutable_evidence(): void
    {
        $assumption = new SketchAssumption(new SketchValueData('region', 77), self::userProvenance(), 1.0, true);

        self::assertTrue($assumption->evidenced);
        self::assertSame(7, $assumption->provenance->confirmedBy);
        self::assertSame('e1', $assumption->evidenceId);
    }

    #[Test]
    public function footprint_discriminant_and_closed_domains_are_strict(): void
    {
        self::assertSame(['kind' => 'area', 'square_meters' => 120.0], (new SketchValueData('footprint_or_area', ['kind' => 'area', 'square_meters' => 120.0]))->value);

        $this->expectException(InvalidArgumentException::class);
        new SketchValueData('wall_material', 'arbitrary');
    }

    #[Test]
    public function catalog_provenance_cannot_claim_evidence_or_confirmer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SketchProvenanceData('catalog_default', 7, 'e1', 'sha256:'.str_repeat('c', 64), 1, 'source:v1');
    }

    private static function catalogProvenance(): SketchProvenanceData
    {
        return new SketchProvenanceData('catalog_default', null, null, 'sha256:'.str_repeat('c', 64), 1, 'catalog:v1');
    }

    public static function userProvenance(): SketchProvenanceData
    {
        return new SketchProvenanceData('user', 7, 'e1', 'sha256:'.str_repeat('c', 64), 1, 'source:v1');
    }
}
