<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Learning;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class EstimateGenerationLearningEvidenceService
{
    private const MAX_EXAMPLES = 400;
    private const MAX_SOURCES = 6;
    private const MAX_KNOWLEDGE_VALIDATION_PAIRS = 8;

    public function __construct(
        private readonly WorkIntentClassifier $workIntentClassifier,
        private readonly EquipmentCountKnowledgeValidationService $equipmentKnowledge,
    ) {}

    /**
     * @param Collection<int, EstimateNorm> $candidates
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function summarizeForCandidates(Collection $candidates, array $workItem, array $context = []): array
    {
        $summaries = [];

        foreach ($candidates as $candidate) {
            $summaries[(int) $candidate->id] = $this->emptySummary();
        }

        $organizationId = $this->nullableInt($context['organization_id'] ?? null);
        if ($organizationId === null || $candidates->isEmpty()) {
            return $summaries;
        }

        (new EloquentCollection($candidates->all()))->loadMissing('collection');
        $candidatesByVersion = [];
        foreach ($candidates as $candidate) {
            $versionId = $candidate->collection?->dataset_version_id;
            if ($versionId === null) {
                continue;
            }
            $candidatesByVersion[(int) $versionId]['ids'][] = (int) $candidate->id;
            $code = $this->normalizeCode((string) $candidate->code);
            if ($code !== '') {
                $candidatesByVersion[(int) $versionId]['codes'][] = $code;
            }
        }
        if ($candidatesByVersion === []) {
            return $summaries;
        }

        $examples = $this->examples($organizationId, $this->nullableInt($context['project_id'] ?? null), $candidatesByVersion);
        if ($examples->isEmpty()) {
            return $summaries;
        }

        $classifiedIntent = $this->workIntentClassifier->classify($workItem, $context);
        $intent = [
            'scope' => $classifiedIntent->scope,
            'action' => $classifiedIntent->action,
            'object' => $classifiedIntent->object,
            'material' => $classifiedIntent->material,
            'system' => $classifiedIntent->system,
        ];
        $workTokens = $this->tokens($this->workText($workItem, $context));
        $currentAssociations = [];
        $currentSources = [];
        $validationPairs = [];

        foreach ($examples as $example) {
            if (!$this->indexable($example)) {
                continue;
            }
            $marked = array_key_exists('knowledge_validation', is_array($example->context_payload) ? $example->context_payload : []);
            if ($marked) {
                if ($example->estimate_norm_id === null || ! EquipmentCountKnowledgeValidationService::hasPassingValidationMetadata($example)) {
                    continue;
                }
                $knowledge = $example->context_payload['knowledge_validation'];
                $claimKey = CanonicalPipelineJson::encode([$example->organization_id, $example->project_id,
                    $example->generation_session_id, data_get($knowledge, 'schema_version'), data_get($knowledge, 'producer'),
                    data_get($knowledge, 'work_item_key'), data_get($knowledge, 'claim'),
                    data_get($knowledge, 'count_source_check.outcome'), data_get($knowledge, 'normative_association_check.outcome')]);
            }

            foreach ($this->matchingCandidates($example, $candidates) as $candidate) {
                if ($marked && (int) $candidate->id !== (int) $example->estimate_norm_id) {
                    continue;
                }
                $evidence = $this->scoreEvidence($example, $candidate, $workItem, $context, $intent, $workTokens);
                if ($evidence === null) {
                    continue;
                }
                if ($marked) {
                    $pairKey = CanonicalPipelineJson::encode([$claimKey, (int) $candidate->id]);
                    if (! isset($validationPairs[$pairKey])) {
                        if (count($validationPairs) >= self::MAX_KNOWLEDGE_VALIDATION_PAIRS) {
                            continue;
                        }
                        $validationPairs[$pairKey] = true;
                    }
                    $currentSources[$claimKey] ??= $this->equipmentKnowledge->permitsSourceReuse($example);
                    if (! $currentSources[$claimKey]) {
                        continue;
                    }
                    $currentAssociations[(int) $candidate->id] ??= $this->equipmentKnowledge->currentAssociationPasses($workItem, (int) $candidate->id, $context);
                    if (! $currentAssociations[(int) $candidate->id]) {
                        continue;
                    }
                }
                $candidateId = (int) $candidate->id;
                $summaries[$candidateId]['learning_score'] += $evidence['score'];

                if ((bool) $example->is_positive) {
                    $summaries[$candidateId]['learning_positive_count']++;
                } else {
                    $summaries[$candidateId]['learning_negative_count']++;
                }

                $summaries[$candidateId]['learning_sources'][] = $evidence['source'];
            }
        }

        foreach ($summaries as $candidateId => $summary) {
            usort(
                $summary['learning_sources'],
                static fn (array $left, array $right): int => abs((float) $right['score']) <=> abs((float) $left['score'])
            );

            $summary['learning_score'] = round((float) $summary['learning_score'], 2);
            $summary['learning_sources'] = array_slice($summary['learning_sources'], 0, self::MAX_SOURCES);
            $summaries[$candidateId] = $summary;
        }

        return $summaries;
    }

    /**
     * @param array<int, array{ids: array<int, int>, codes?: array<int, string>}> $candidatesByVersion
     * @return Collection<int, EstimateGenerationLearningExample>
     */
    private function examples(int $organizationId, ?int $projectId, array $candidatesByVersion): Collection
    {
        return EstimateGenerationLearningExample::query()
            ->where('organization_id', $organizationId)
            ->whereIn('source_type', EstimateGenerationLearningSourceTrustPolicy::trustedSourceTypes())
            ->when($projectId !== null, static function (Builder $query) use ($projectId): void {
                $query->where(static function (Builder $query) use ($projectId): void {
                    $query->where('project_id', $projectId)
                        ->orWhereNull('project_id');
                });
            }, static function (Builder $query): void {
                $query->whereNull('project_id');
            })
            ->where(static function (Builder $query) use ($candidatesByVersion): void {
                foreach ($candidatesByVersion as $versionId => $identities) {
                    $query->orWhere(static function (Builder $query) use ($versionId, $identities): void {
                        $query->where('normative_dataset_version_id', $versionId)
                            ->where(static function (Builder $query) use ($identities): void {
                                $query->whereIn('estimate_norm_id', $identities['ids']);
                                if (($identities['codes'] ?? []) !== []) {
                                    $query->orWhereIn('norm_code', $identities['codes']);
                                }
                            });
                    });
                }
            })
            ->orderByRaw('CASE WHEN project_id IS NULL THEN 1 ELSE 0 END')
            ->latest('accepted_at')
            ->latest('id')
            ->limit(self::MAX_EXAMPLES)
            ->get();
    }

    /**
     * @param Collection<int, EstimateNorm> $candidates
     * @return array<int, EstimateNorm>
     */
    private function matchingCandidates(EstimateGenerationLearningExample $example, Collection $candidates): array
    {
        $exampleNormId = $example->estimate_norm_id !== null ? (int) $example->estimate_norm_id : null;
        $exampleCode = $this->normalizeCode((string) $example->norm_code);

        return $candidates
            ->filter(function (EstimateNorm $candidate) use ($exampleNormId, $exampleCode): bool {
                return ($exampleNormId !== null && (int) $candidate->id === $exampleNormId)
                    || $this->normalizeCode((string) $candidate->code) === $exampleCode;
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
     * @param array<string, mixed> $intent
     * @param array<int, string> $workTokens
     * @return array{score: float, source: array<string, mixed>}|null
     */
    private function scoreEvidence(
        EstimateGenerationLearningExample $example,
        EstimateNorm $candidate,
        array $workItem,
        array $context,
        array $intent,
        array $workTokens
    ): ?array {
        $exampleVersion = $example->normative_dataset_version_id;
        $candidateVersion = $candidate->collection?->dataset_version_id;
        if ($exampleVersion === null || $candidateVersion === null || (int) $exampleVersion !== (int) $candidateVersion) {
            return null;
        }

        $workUnit = (string) ($workItem['unit'] ?? '');
        $candidateUnit = (string) ($candidate->unit ?? '');
        $exampleWorkUnit = (string) ($example->work_unit ?? '');
        $exampleNormativeUnit = (string) ($example->normative_unit ?? '');
        foreach ([$workUnit, $candidateUnit, $exampleWorkUnit, $exampleNormativeUnit] as $unit) {
            if (! NormativeUnitNormalizer::parseDetailed($unit)->isKnown()) {
                return null;
            }
        }
        if (! NormativeUnitNormalizer::compatible($workUnit, $candidateUnit)
            || ! NormativeUnitNormalizer::compatible($workUnit, $exampleWorkUnit)
            || ! NormativeUnitNormalizer::compatible($candidateUnit, $exampleNormativeUnit)) {
            return null;
        }

        $exampleIntent = is_array($example->work_intent) ? $example->work_intent : [];
        if (!$this->intentCompatible($intent, $exampleIntent)) {
            return null;
        }

        $exampleTokens = $this->tokens($this->exampleText($example));
        $lexicalOverlap = count(array_intersect($workTokens, $exampleTokens));
        $intentOverlap = $this->intentOverlap($intent, $exampleIntent);

        if ($lexicalOverlap === 0 && $intentOverlap === 0) {
            return null;
        }

        $quality = $this->qualityScore($example);
        $sourceWeight = $this->sourceWeight((string) $example->source_type, (bool) $example->is_positive);
        $projectWeight = $this->projectWeight($example, $this->nullableInt($context['project_id'] ?? null));
        $score = (
            4.0
            + min($lexicalOverlap, 6) * 0.85
            + $intentOverlap * 1.25
            + $projectWeight
        ) * $quality * $sourceWeight;

        if (!(bool) $example->is_positive) {
            $score *= -1.15;
        }

        return [
            'score' => round($score, 2),
            'source' => [
                'example_id' => (int) $example->id,
                'source_type' => (string) $example->source_type,
                'decision_status' => (string) $example->decision_status,
                'normative_code' => (string) $example->norm_code,
                'work_name' => (string) $example->work_name,
                'is_positive' => (bool) $example->is_positive,
                'score' => round($score, 2),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $exampleIntent
     */
    private function intentCompatible(array $intent, array $exampleIntent): bool
    {
        foreach (['scope', 'action', 'system'] as $field) {
            $left = (string) ($intent[$field] ?? '');
            $right = (string) ($exampleIntent[$field] ?? '');

            if ($left === '' || $right === '' || in_array($left, ['general', 'general_work'], true)) {
                continue;
            }

            if ($left !== $right) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $exampleIntent
     */
    private function intentOverlap(array $intent, array $exampleIntent): int
    {
        $score = 0;

        foreach (['scope', 'action', 'system', 'object', 'material'] as $field) {
            $left = (string) ($intent[$field] ?? '');
            $right = (string) ($exampleIntent[$field] ?? '');

            if ($left !== '' && $right !== '' && $left === $right) {
                $score++;
            }
        }

        return $score;
    }

    private function workText(array $workItem, array $context): string
    {
        return trim(implode(' ', [
            (string) ($workItem['name'] ?? ''),
            (string) ($workItem['description'] ?? ''),
            (string) ($workItem['work_category'] ?? ''),
            (string) ($context['section_title'] ?? ''),
            (string) ($context['local_estimate_title'] ?? ''),
        ]));
    }

    private function exampleText(EstimateGenerationLearningExample $example): string
    {
        $context = is_array($example->context_payload) ? $example->context_payload : [];

        return trim(implode(' ', [
            (string) $example->work_name,
            (string) ($context['section_name'] ?? ''),
            (string) ($context['section_title'] ?? ''),
            (string) $example->normative_name,
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $text = str_replace('ё', 'е', mb_strtolower($text));
        preg_match_all('/[\p{L}\p{N}.-]+/u', $text, $matches);
        $stopWords = [
            'работа',
            'работы',
            'устройство',
            'монтаж',
            'для',
            'при',
            'под',
            'над',
        ];
        $tokens = [];

        foreach ($matches[0] ?? [] as $token) {
            $token = trim($token, '.- ');

            if (mb_strlen($token) < 4 || in_array($token, $stopWords, true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    private function projectWeight(EstimateGenerationLearningExample $example, ?int $projectId): float
    {
        if ($projectId !== null && $example->project_id !== null && (int) $example->project_id === $projectId) {
            return 2.0;
        }

        return 0.4;
    }

    private function sourceWeight(string $sourceType, bool $positive): float
    {
        if ($sourceType === 'manual_review_choice') {
            return $positive ? 1.45 : 1.0;
        }

        if ($sourceType === 'manual_review_rejection') {
            return $positive ? 1.0 : 1.55;
        }

        if ($sourceType === 'user_selection') {
            return 1.35;
        }

        if ($sourceType === 'user_rejection') {
            return $positive ? 1.0 : 1.45;
        }

        return 1.0;
    }

    private function qualityScore(EstimateGenerationLearningExample $example): float
    {
        $score = $example->source_quality_score !== null ? (float) $example->source_quality_score : 0.75;

        return min(1.0, max(0.15, $score));
    }

    private function indexable(EstimateGenerationLearningExample $example): bool
    {
        return EstimateGenerationLearningSourceTrustPolicy::isIndexable($example);
    }

    /**
     * @return array{learning_positive_count: int, learning_negative_count: int, learning_score: float, learning_sources: array<int, array<string, mixed>>}
     */
    private function emptySummary(): array
    {
        return [
            'learning_positive_count' => 0,
            'learning_negative_count' => 0,
            'learning_score' => 0.0,
            'learning_sources' => [],
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeCode(string $code): string
    {
        return trim($code);
    }
}
