<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisRoutingResult;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetRole;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetRoleClassification;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\TargetedSheetEvidence;
use App\BusinessModules\Addons\EstimateGeneration\Vision\TargetedSheetRecheckPlanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TargetedSheetRecheckPlannerTest extends TestCase
{
    #[Test]
    public function role_conflict_creates_a_real_two_sheet_scope_with_the_peer_image(): void
    {
        $peer = $this->peer();
        $plan = (new TargetedSheetRecheckPlanner)->plan(
            13,
            17,
            $this->routing('sheet_role_conflict'),
            $this->analysis('room-1'),
            $peer,
        );

        self::assertNotNull($plan);
        self::assertSame(['document:13/sheet:17', 'document:14/sheet:18'], $plan->scope->sourceSet);
        self::assertNull($plan->scope->entityKey);
        self::assertSame($peer, $plan->supplementalEvidence);
    }

    #[Test]
    public function insufficient_evidence_targets_an_entity_that_exists_in_the_analysis(): void
    {
        $plan = (new TargetedSheetRecheckPlanner)->plan(
            13,
            17,
            $this->routing('sheet_role_insufficient_evidence'),
            $this->analysis('room-42', false),
            null,
        );

        self::assertNotNull($plan);
        self::assertSame('room-42', $plan->scope->entityKey);
        self::assertSame(['document:13/sheet:17'], $plan->scope->sourceSet);
        self::assertNull($plan->supplementalEvidence);
    }

    private function routing(string $reason): SheetAnalysisRoutingResult
    {
        return new SheetAnalysisRoutingResult(
            new SheetRoleClassification(SheetRole::Plan, 'plan', 1.0, 'provider_sheet_role', $reason),
            64,
            96,
            2048,
        );
    }

    private function analysis(string $entityKey, bool $withFact = true): VisionAnalysisData
    {
        return VisionAnalysisData::fromProviderArray([
            'schema_version' => 3,
            'sheet_type' => 'floor_plan',
            'evidence' => [['key' => 'page-1', 'locator' => [
                'page_id' => 17, 'page_number' => 2, 'processing_unit_id' => 19,
                'source_version' => 'sha256:'.str_repeat('a', 64), 'coordinate_space' => 'normalized_derivative_v1',
            ]]],
            'elements' => [[
                'key' => $entityKey, 'type' => 'room', 'label' => null,
                'polygon' => [[0.1, 0.1], [0.9, 0.1], [0.9, 0.9]],
                'confidence' => 0.5, 'evidence_ref' => 'page-1',
            ]],
            'scale_candidates' => [],
            'warnings' => ['scale_missing'],
            'visual_attributes' => ['roof_type' => ['value' => 'unknown', 'confidence' => 0.0, 'evidence_ref' => 'page-1']],
            'project_sheet_analysis' => [
                'contractVersion' => 'sheet-analysis:v2', 'role' => 'plan',
                'facts' => $withFact ? [[
                    'entityKey' => $entityKey, 'factType' => 'room',
                    'value' => ['type' => 'unknown', 'data' => null], 'unit' => null,
                    'evidenceRef' => 'page-1', 'sourcePolygonOrNativeRef' => [[0.1, 0.1], [0.9, 0.9]],
                    'confidence' => 0.5, 'contractVersion' => 'sheet-analysis:v2',
                ]] : [],
            ],
        ], 'test', 'model', 'model', 'v1', 'unavailable', null, null, 10);
    }

    private function peer(): TargetedSheetEvidence
    {
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($image);

        return new TargetedSheetEvidence(
            7, 9, 11, 14, 18, 3, 20,
            'sha256:'.str_repeat('b', 64),
            'sha256:'.hash('sha256', $image),
            'image/png',
            $image,
        );
    }
}
