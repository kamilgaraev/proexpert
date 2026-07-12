<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchAssumption;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchQuestionData;

final readonly class VisionBuildingModelAssemblyResult
{
    public function __construct(
        public NormalizedBuildingModelData $model,
        public array $sourceGeometry,
        public array $sketchAssumptions,
        public array $questions,
        public array $clarifications,
    ) {}

    public function toArray(): array
    {
        return [
            'model' => $this->model->toArray(),
            'source_geometry' => $this->sourceGeometry,
            'sketch_assumptions' => array_map(static fn (SketchAssumption $item): array => [
                'key' => $item->key,
                'value' => $item->value,
                'source' => $item->source,
                'confidence' => $item->confidence,
                'evidence_id' => $item->evidenceId,
                'requires_confirmation' => $item->requiresConfirmation,
                'evidenced' => $item->evidenced,
                'provenance' => $item->provenance->toArray(),
            ], $this->sketchAssumptions),
            'questions' => array_map(static fn (SketchQuestionData $item): array => $item->toArray(), $this->questions),
            'clarifications' => array_map(static fn (VisionClarificationData $item): array => $item->toArray(), $this->clarifications),
        ];
    }
}
