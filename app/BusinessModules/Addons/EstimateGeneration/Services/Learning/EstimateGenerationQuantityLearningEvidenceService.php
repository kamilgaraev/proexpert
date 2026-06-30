<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Learning;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationQuantityKeyResolver;

final class EstimateGenerationQuantityLearningEvidenceService
{
    private const SOURCE_TYPE = 'manual_quantity_confirmation';
    private const MIN_SOURCE_QUALITY = 0.7;
    private const MAX_EXAMPLES_PER_ANALYSIS = 200;

    /**
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    public function enrichAnalysis(EstimateGenerationSession $session, array $analysis): array
    {
        $hints = $this->hintsForAnalysis(
            (int) $session->organization_id,
            $session->project_id !== null ? (int) $session->project_id : null,
            $analysis
        );

        if ($hints === []) {
            return $analysis;
        }

        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $documentContext['quantity_learning_hints'] = $hints;
        $analysis['document_context'] = $documentContext;

        return $analysis;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<string, array<string, mixed>>
     */
    public function hintsForAnalysis(int $organizationId, ?int $projectId, array $analysis): array
    {
        $quantityKeys = $this->quantityKeysFromAnalysis($analysis);

        if ($quantityKeys === []) {
            return [];
        }

        $learningCodes = [];
        $quantityKeyByCode = [];

        foreach ($quantityKeys as $quantityKey) {
            $learningCode = EstimateGenerationQuantityLearningKey::fromQuantityKey($quantityKey);
            $learningCodes[] = $learningCode;
            $quantityKeyByCode[$learningCode] = $quantityKey;
        }

        $query = EstimateGenerationLearningExample::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', self::SOURCE_TYPE)
            ->where('is_positive', true)
            ->whereIn('norm_code', array_values(array_unique($learningCodes)))
            ->where('source_quality_score', '>=', self::MIN_SOURCE_QUALITY)
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->limit(self::MAX_EXAMPLES_PER_ANALYSIS);

        if ($projectId !== null) {
            $query->where(static function ($query) use ($projectId): void {
                $query->where('project_id', $projectId)
                    ->orWhereNull('project_id');
            });
        } else {
            $query->whereNull('project_id');
        }

        $groups = [];

        foreach ($query->get() as $example) {
            if (!EstimateGenerationLearningSourceTrustPolicy::isIndexable($example)) {
                continue;
            }

            $quantityKey = $this->quantityKeyFromExample($example, $quantityKeyByCode);

            if ($quantityKey === null || !in_array($quantityKey, $quantityKeys, true)) {
                continue;
            }

            $hint = $this->hintFromExample($example, $quantityKey, $projectId);

            if ($hint !== null) {
                $groups[$quantityKey][] = $hint;
            }
        }

        $hints = [];

        foreach ($groups as $quantityKey => $group) {
            usort($group, static function (array $left, array $right): int {
                return [
                    (int) ($right['same_project'] ?? false),
                    (string) ($right['accepted_at'] ?? ''),
                    (float) ($right['source_quality_score'] ?? 0),
                ] <=> [
                    (int) ($left['same_project'] ?? false),
                    (string) ($left['accepted_at'] ?? ''),
                    (float) ($left['source_quality_score'] ?? 0),
                ];
            });

            $selected = $group[0];
            $selected['examples_count'] = count($group);
            $hints[$quantityKey] = $selected;
        }

        ksort($hints);

        return $hints;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<int, string>
     */
    private function quantityKeysFromAnalysis(array $analysis): array
    {
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $takeoffs = is_array($documentContext['quantity_takeoffs'] ?? null) ? $documentContext['quantity_takeoffs'] : [];
        $quantityKeys = [];

        foreach ($takeoffs as $takeoff) {
            if (!is_array($takeoff)) {
                continue;
            }

            $quantityKey = EstimateGenerationQuantityKeyResolver::fromTakeoff($takeoff);
            $quantity = $this->positiveFloat($takeoff['quantity'] ?? $takeoff['value'] ?? $takeoff['value_number'] ?? null);

            if ($quantityKey !== '' && $quantity !== null) {
                $quantityKeys[] = $quantityKey;
            }
        }

        return array_values(array_unique($quantityKeys));
    }

    /**
     * @param array<string, string> $quantityKeyByCode
     */
    private function quantityKeyFromExample(EstimateGenerationLearningExample $example, array $quantityKeyByCode): ?string
    {
        $payload = is_array($example->context_payload) ? $example->context_payload : [];
        $quantityKey = $this->nullableString(data_get($payload, 'quantity_key'));

        if ($quantityKey !== null) {
            return $quantityKey;
        }

        return $quantityKeyByCode[(string) $example->norm_code] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function hintFromExample(
        EstimateGenerationLearningExample $example,
        string $quantityKey,
        ?int $projectId
    ): ?array {
        $quantity = $this->positiveFloat($example->work_quantity);
        $unit = $this->nullableString($example->work_unit);

        if ($quantity === null || $unit === null) {
            return null;
        }

        $payload = is_array($example->context_payload) ? $example->context_payload : [];

        return array_filter([
            'quantity_key' => $quantityKey,
            'learning_example_id' => (int) $example->id,
            'source_type' => (string) $example->source_type,
            'source_quality_score' => (float) $example->source_quality_score,
            'confidence' => (float) ($example->confidence ?? 1.0),
            'quantity' => $quantity,
            'unit' => $unit,
            'quantity_basis' => $this->nullableString(data_get($payload, 'quantity_basis') ?? data_get($payload, 'quantity_snapshot.quantity_basis')),
            'calculation_basis' => $this->nullableString(data_get($payload, 'calculation_basis')),
            'work_name' => $this->nullableString($example->work_name),
            'section_title' => $this->nullableString(data_get($payload, 'section_title')),
            'same_project' => $projectId !== null && (int) $example->project_id === $projectId,
            'accepted_at' => $example->accepted_at?->toIso8601String(),
            'source_refs' => is_array($example->source_refs)
                ? array_values(array_filter($example->source_refs, 'is_array'))
                : [],
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(str_replace(',', '.', $value)))) {
            $number = (float) str_replace(',', '.', (string) $value);

            return $number > 0 ? $number : null;
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
