<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Learning;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\Models\Estimate;
use App\Models\ImportSession;

final class EstimateGenerationLearningRecorder
{
    public function __construct(
        private readonly EstimateLearningExampleExtractor $extractor,
    ) {}

    public function recordImportedEstimate(Estimate $estimate, ?ImportSession $importSession = null): int
    {
        $created = 0;

        foreach ($this->extractor->extractFromImportedEstimate($estimate, $importSession) as $example) {
            $created += $this->record($example);
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function record(array $attributes): int
    {
        $example = EstimateGenerationLearningExample::query()->firstOrNew([
            'source_type' => (string) $attributes['source_type'],
            'source_entity_type' => $attributes['source_entity_type'] ?? null,
            'source_entity_id' => $attributes['source_entity_id'] ?? null,
            'norm_code' => (string) $attributes['norm_code'],
        ]);

        if ($example->exists && !$this->canReplace($example, $attributes)) {
            return 0;
        }

        $wasNew = !$example->exists;
        $example->fill($attributes);
        $example->save();

        return $wasNew ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function canReplace(EstimateGenerationLearningExample $example, array $attributes): bool
    {
        $currentQuality = (float) ($example->source_quality_score ?? 0);
        $nextQuality = (float) ($attributes['source_quality_score'] ?? 0);

        if ($example->is_positive && $currentQuality > $nextQuality) {
            return false;
        }

        return true;
    }
}
