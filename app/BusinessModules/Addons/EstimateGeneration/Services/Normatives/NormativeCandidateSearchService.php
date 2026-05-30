<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class NormativeCandidateSearchService
{
    private const MIN_POOL_SIZE = 300;

    public function __construct(
        private readonly WorkIntentClassifier $workIntentClassifier,
    ) {}

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
     * @param array<int, string> $tokens
     * @return Collection<int, EstimateNorm>
     */
    public function search(
        EstimateDatasetVersion $version,
        array $workItem,
        array $context,
        array $tokens,
        int $limit
    ): Collection {
        $intent = $this->workIntentClassifier->classify($workItem, $context);
        $pool = $this->queryPool($version, $tokens, $intent, max($limit * 6, self::MIN_POOL_SIZE));

        if ($pool->isEmpty() && $intent->forbiddenSectionPrefixes !== []) {
            $pool = $this->queryPool(
                $version,
                $tokens,
                new WorkIntentData(
                    scope: $intent->scope,
                    action: $intent->action,
                    object: $intent->object,
                    material: $intent->material,
                    system: $intent->system,
                    expectedDimensions: $intent->expectedDimensions,
                    preferredNormTypes: $intent->preferredNormTypes,
                    forbiddenNormTypes: [],
                    preferredSectionPrefixes: $intent->preferredSectionPrefixes,
                    forbiddenSectionPrefixes: [],
                    confidence: $intent->confidence,
                    signals: $intent->signals,
                ),
                max($limit * 6, self::MIN_POOL_SIZE)
            );
        }

        $ranked = $pool
            ->sortByDesc(fn (EstimateNorm $norm): float => $this->scorePoolCandidate($norm, $workItem, $tokens, $intent))
            ->values();

        $compatible = $this->compatibleCandidates($ranked, (string) ($workItem['unit'] ?? ''));
        if ($compatible->isNotEmpty()) {
            $ranked = $compatible;
        }

        return $ranked
            ->take($limit);
    }

    /**
     * @param array<int, string> $tokens
     * @return Collection<int, EstimateNorm>
     */
    private function queryPool(EstimateDatasetVersion $version, array $tokens, WorkIntentData $intent, int $poolLimit): Collection
    {
        $tokens = $this->expandedTokens($tokens, $intent);
        $query = EstimateNorm::query()
            ->with(['collection', 'section'])
            ->whereHas('collection', static function (Builder $query) use ($version): void {
                $query->where('dataset_version_id', $version->id);
            });

        if ($tokens !== []) {
            $query->where(function (Builder $query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%' . mb_strtolower($token) . '%';
                    $query->orWhereRaw('LOWER(code) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(section_name, \'\')) LIKE ?', [$like]);
                }
            });
        }

        foreach ($intent->forbiddenSectionPrefixes as $prefix) {
            $query->where(function (Builder $query) use ($prefix): void {
                $query->whereNull('section_code')
                    ->orWhere('section_code', 'not like', $prefix . '%');
            });
        }

        return $query
            ->orderBy('code')
            ->limit($poolLimit)
            ->get();
    }

    /**
     * @param array<int, string> $tokens
     * @return array<int, string>
     */
    private function expandedTokens(array $tokens, WorkIntentData $intent): array
    {
        $expanded = $tokens;

        foreach ($tokens as $token) {
            foreach ([
                'кабел',
                'проклад',
                'труб',
                'отопл',
                'кров',
                'утепл',
                'фундамент',
                'бетон',
                'арматур',
                'опалуб',
                'грунт',
            ] as $stem) {
                if (str_contains(mb_strtolower($token), $stem)) {
                    $expanded[] = $stem;
                }
            }
        }

        if ($intent->action === 'cable_installation') {
            $expanded[] = 'кабел';
            $expanded[] = 'проклад';
        }

        if ($intent->action === 'pipe_layout') {
            $expanded[] = 'труб';
            $expanded[] = 'проклад';
        }

        if ($intent->action === 'insulation') {
            $expanded[] = 'утепл';
        }

        return array_values(array_unique(array_filter(
            $expanded,
            static fn (string $token): bool => mb_strlen($token) >= 3
        )));
    }

    /**
     * @param array<string, mixed> $workItem
     * @param array<int, string> $tokens
     */
    private function scorePoolCandidate(EstimateNorm $norm, array $workItem, array $tokens, WorkIntentData $intent): float
    {
        $score = 0.0;
        $haystack = mb_strtolower(implode(' ', [
            $norm->code,
            $norm->name,
            $norm->section_name,
            implode(' ', $norm->work_composition ?? []),
        ]));

        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($haystack, mb_strtolower($token))) {
                $score += 4.0;
            }
        }

        $normUnit = (string) ($norm->unit ?? '');
        $workUnit = (string) ($workItem['unit'] ?? '');
        if ($workUnit !== '' && $normUnit !== '' && NormativeUnitNormalizer::compatible($workUnit, $normUnit)) {
            $score += 50.0;
        } elseif ($workUnit !== '' && $normUnit !== '') {
            $score -= 35.0;
        }

        $sectionCode = (string) ($norm->section_code ?? $norm->section?->code ?? '');
        foreach ($intent->preferredSectionPrefixes as $prefix) {
            if ($sectionCode !== '' && str_starts_with($sectionCode, $prefix)) {
                $score += 20.0;
            }
        }

        foreach ($intent->forbiddenSectionPrefixes as $prefix) {
            if ($sectionCode !== '' && str_starts_with($sectionCode, $prefix)) {
                $score -= 100.0;
            }
        }

        $normType = (string) ($norm->collection?->norm_type?->value ?? $norm->collection?->norm_type ?? '');
        if ($normType !== '' && in_array($normType, $intent->preferredNormTypes, true)) {
            $score += 10.0;
        }

        if ($normType !== '' && in_array($normType, $intent->forbiddenNormTypes, true)) {
            $score -= 50.0;
        }

        return $score;
    }

    /**
     * @param Collection<int, EstimateNorm> $candidates
     * @return Collection<int, EstimateNorm>
     */
    private function compatibleCandidates(Collection $candidates, string $workUnit): Collection
    {
        if ($workUnit === '') {
            return collect();
        }

        return $candidates
            ->filter(static function (EstimateNorm $norm) use ($workUnit): bool {
                $normUnit = (string) ($norm->unit ?? '');

                return $normUnit !== '' && NormativeUnitNormalizer::compatible($workUnit, $normUnit);
            })
            ->values();
    }
}
