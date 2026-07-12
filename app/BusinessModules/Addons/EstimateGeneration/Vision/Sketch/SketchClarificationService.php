<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch;

final class SketchClarificationService
{
    public function missingQuestions(SketchClarificationData $input): array
    {
        $answered = array_fill_keys(array_map(static fn (SketchAssumption $item): string => $item->key, $input->answers), true);

        return array_values(array_map(
            static fn (string $key): SketchQuestionData => new SketchQuestionData($key),
            array_filter(SketchQuestionData::KEYS, static fn (string $key): bool => ! isset($answered[$key])),
        ));
    }

    public function assumption(SketchValueData $value, SketchProvenanceData $provenance, float $confidence, bool $confirmed): SketchAssumption
    {
        return new SketchAssumption($value, $provenance, $confidence, $confirmed);
    }
}
