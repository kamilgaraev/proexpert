<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Integrations\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\Models\Estimate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class EstimateGenerationLearningBootstrapService
{
    private const DEFAULT_CHUNK_SIZE = 100;

    private const DEFAULT_MIN_QUALITY = 0.85;

    public function __construct(
        private readonly EstimateLearningExampleExtractor $extractor,
        private readonly EstimateGenerationLearningRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     dry_run: bool,
     *     processed_estimates: int,
     *     candidate_examples: int,
     *     passed_quality_gate: int,
     *     skipped_low_quality: int,
     *     existing_examples: int,
     *     would_create_examples: int,
     *     created_examples: int
     * }
     */
    public function bootstrap(array $options = []): array
    {
        $write = (bool) ($options['write'] ?? false);
        $limit = $this->positiveInt($options['limit'] ?? null);
        $chunkSize = $this->positiveInt($options['chunk'] ?? null) ?? self::DEFAULT_CHUNK_SIZE;
        $minQuality = $this->minQuality($options['min_quality'] ?? null);
        $requireUnitCompatible = (bool) ($options['require_unit_compatible'] ?? true);

        $stats = [
            'dry_run' => ! $write,
            'processed_estimates' => 0,
            'candidate_examples' => 0,
            'passed_quality_gate' => 0,
            'skipped_low_quality' => 0,
            'existing_examples' => 0,
            'would_create_examples' => 0,
            'created_examples' => 0,
        ];

        $this->query($options)
            ->chunkById($chunkSize, function (Collection $estimates) use (
                &$stats,
                $limit,
                $write,
                $minQuality,
                $requireUnitCompatible
            ): bool {
                foreach ($estimates as $estimate) {
                    if ($limit !== null && $stats['processed_estimates'] >= $limit) {
                        return false;
                    }

                    if (! $estimate instanceof Estimate) {
                        continue;
                    }

                    $stats['processed_estimates']++;
                    $this->processEstimate($estimate, $stats, $write, $minQuality, $requireUnitCompatible);
                }

                return true;
            });

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return Builder<Estimate>
     */
    private function query(array $options): Builder
    {
        $organizationId = $this->positiveInt($options['organization_id'] ?? null);
        $projectId = $this->positiveInt($options['project_id'] ?? null);
        $estimateId = $this->positiveInt($options['estimate_id'] ?? null);
        $includeDemo = (bool) ($options['include_demo'] ?? false);

        return Estimate::query()
            ->with(['items.section', 'items.measurementUnit'])
            ->when($organizationId !== null, static fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
            ->when($projectId !== null, static fn (Builder $query): Builder => $query->where('project_id', $projectId))
            ->when($estimateId !== null, static fn (Builder $query): Builder => $query->whereKey($estimateId))
            ->when(! $includeDemo, static function (Builder $query): void {
                $query->where(static function (Builder $query): void {
                    $query->whereNull('is_onboarding_demo')
                        ->orWhere('is_onboarding_demo', false);
                });
            })
            ->whereHas('items', static function (Builder $query): void {
                $query
                    ->whereNotNull('normative_rate_code')
                    ->where('normative_rate_code', '<>', '');
            })
            ->orderBy('id');
    }

    /**
     * @param array{
     *     dry_run: bool,
     *     processed_estimates: int,
     *     candidate_examples: int,
     *     passed_quality_gate: int,
     *     skipped_low_quality: int,
     *     existing_examples: int,
     *     would_create_examples: int,
     *     created_examples: int
     * } $stats
     */
    private function processEstimate(
        Estimate $estimate,
        array &$stats,
        bool $write,
        float $minQuality,
        bool $requireUnitCompatible
    ): void {
        foreach ($this->extractor->extractFromImportedEstimate($estimate) as $example) {
            $stats['candidate_examples']++;

            if (! $this->passesQualityGate($example, $minQuality, $requireUnitCompatible)) {
                $stats['skipped_low_quality']++;

                continue;
            }

            $stats['passed_quality_gate']++;

            if ($this->alreadyRecorded($example)) {
                $stats['existing_examples']++;

                if ($write) {
                    $this->recorder->record($example);
                }

                continue;
            }

            if (! $write) {
                $stats['would_create_examples']++;

                continue;
            }

            $stats['created_examples'] += $this->recorder->record($example);
        }
    }

    /**
     * @param  array<string, mixed>  $example
     */
    private function passesQualityGate(array $example, float $minQuality, bool $requireUnitCompatible): bool
    {
        $flags = array_map('strval', is_array($example['quality_flags'] ?? null) ? $example['quality_flags'] : []);

        if (array_intersect($flags, ['do_not_index', 'unindexable', 'low_quality', 'unit_mismatch']) !== []) {
            return false;
        }

        if ($requireUnitCompatible && ! in_array('unit_compatible', $flags, true)) {
            return false;
        }

        if ((float) ($example['source_quality_score'] ?? 0) < $minQuality) {
            return false;
        }

        return trim((string) ($example['work_name'] ?? '')) !== ''
            && trim((string) ($example['norm_code'] ?? '')) !== ''
            && (bool) ($example['is_positive'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $example
     */
    private function alreadyRecorded(array $example): bool
    {
        return EstimateGenerationLearningExample::query()
            ->where('source_type', (string) ($example['source_type'] ?? ''))
            ->where('source_entity_type', $example['source_entity_type'] ?? null)
            ->where('source_entity_id', $example['source_entity_id'] ?? null)
            ->where('norm_code', (string) ($example['norm_code'] ?? ''))
            ->exists();
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function minQuality(mixed $value): float
    {
        if (! is_numeric($value)) {
            return self::DEFAULT_MIN_QUALITY;
        }

        return max(0.0, min(1.0, (float) $value));
    }
}
