<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\GeometryFusionResult;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\ScaleResolutionData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchAssumption;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch\SketchQuestionData;
use InvalidArgumentException;

final readonly class VisionBuildingModelInputData
{
    public function __construct(
        public ScaleResolutionData $scale,
        public GeometryFusionResult $geometry,
        public array $sketchAssumptions,
        public array $questions,
        public array $evidenceIdsByRef,
        public string $producerVersion,
        public string $floorKey,
    ) {
        foreach ($sketchAssumptions as $assumption) {
            if (! $assumption instanceof SketchAssumption) {
                throw new InvalidArgumentException('Sketch assumption is invalid.');
            }
        }
        foreach ($questions as $question) {
            if (! $question instanceof SketchQuestionData) {
                throw new InvalidArgumentException('Sketch clarification question is invalid.');
            }
        }
        if (preg_match('/^[a-z][a-z0-9-]{1,63}:v[1-9][0-9]*$/', $producerVersion) !== 1) {
            throw new InvalidArgumentException('Vision building model producer is invalid.');
        }
        BuildingModelSchema::key($floorKey, 'Vision floor');
        $refs = $scale->evidenceRefs;
        foreach ($geometry->sourceElements as $element) {
            $refs = [...$refs, ...$element->evidenceRefs()];
        }
        foreach ($geometry->issues as $issue) {
            $refs = [...$refs, ...($issue['evidence_refs'] ?? [])];
        }
        foreach ($sketchAssumptions as $assumption) {
            if ($assumption->evidenceId !== null) {
                $refs[] = $assumption->evidenceId;
            }
        }
        foreach (array_unique($refs) as $ref) {
            if (! is_string($ref) || ! isset($evidenceIdsByRef[$ref]) || ! is_int($evidenceIdsByRef[$ref]) || $evidenceIdsByRef[$ref] < 1) {
                throw new InvalidArgumentException('Vision evidence mapping is incomplete.');
            }
        }
        if (count($evidenceIdsByRef) !== count(array_unique($evidenceIdsByRef))) {
            throw new InvalidArgumentException('Vision evidence mapping must be unique.');
        }
    }
}
