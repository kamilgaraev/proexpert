<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Learning;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EstimateGenerationLearningEvidenceService
{
    private const MAX_EXAMPLES = 400;
    private const MAX_SOURCES = 6;
    private const MAX_KNOWLEDGE_VALIDATION_PAIRS = 8;

    public function __construct(
        private readonly WorkIntentClassifier $workIntentClassifier,
        private readonly EquipmentCountKnowledgeValidationService $equipmentKnowledge,
        private readonly ComposerSourceClaimValidationService $sourceClaims,
        private readonly ?EstimateGenerationLearningRecorder $recorder = null,
    ) {}

    public function sourceClaimReferences(int $organizationId, int $projectId, int $sessionId,
        ProjectModelSnapshot $snapshot, array $decisions, array $input): array
    {
        if (min($organizationId, $projectId, $sessionId) < 1 || count($snapshot->facts) > 10000) {
            return [];
        }
        $scope = [$organizationId, $projectId, $sessionId];
        $session = EstimateGenerationSession::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->whereKey($sessionId)->whereHas('project', static fn (Builder $query) => $query->where('organization_id', $organizationId))->first();
        $serverInput = static fn (array $value): array => ['evaluation_mode' => $value['evaluation_mode'] ?? 'legacy',
            'profile_id' => $value['profile_id'] ?? 'construction', 'selected_sections' => $value['selected_sections'] ?? [],
            'description' => is_string($value['description'] ?? null) ? trim($value['description']) : null];
        if ($session === null || CanonicalPipelineJson::encode($serverInput($input))
            !== CanonicalPipelineJson::encode($serverInput($session->input_payload ?? []))) {
            return [];
        }
        $answerHashes = $answerIds = $documentIds = $checked = [];
        $entities = array_column($snapshot->entities, null, 'id');
        $facts = $snapshot->facts;
        usort($facts, static fn (Fact $left, Fact $right): int => strcmp($left->id, $right->id));
        foreach ($facts as $fact) {
            if ([$fact->organizationId, $fact->projectId, $fact->sessionId] !== $scope) {
                return [];
            }
            if ($fact->type === 'equipment_specification' && $fact->origin === 'document' && $fact->status === 'confirmed'
                && $fact->unit === null && isset($entities[$fact->entityId]) && GeometryInventory::type($entities[$fact->entityId]) === 'equipment') {
                $documentIds[] = $fact->id;
            }
            if ($fact->type !== 'evaluation_answer' || ! is_array($fact->value) || ($fact->value['kind'] ?? null) !== 'composer_answer'
                || count($answerIds) >= ComposerSourceClaimValidationService::MAX_CLAIMS) {
                continue;
            }
            $context = ResolveCurrentEstimateClarification::composerAnswerContext($fact, $snapshot, $decisions, $input, $checked);
            if ($context !== null && in_array('equipment', $context['work_sections'], true)) {
                $answerIds[] = $fact->id;
                $answerHashes[] = hash('sha256', CanonicalPipelineJson::encode($fact->value));
            }
        }
        if ($answerIds === [] || $documentIds === []) {
            return [];
        }
        $table = 'estimate_generation_learning_examples';
        $query = EstimateGenerationLearningExample::query()->select($table.'.*')
            ->join('estimate_generation_ai_role_runs as receipt', 'receipt.id', '=', $table.'.source_entity_id')
            ->join('estimate_generation_vision_physical_attempts as physical', 'physical.attempt_id', '=', 'receipt.physical_attempt_id')
            ->join('estimate_generation_credit_attempts as credit', 'credit.attempt_id', '=', 'physical.attempt_id')
            ->join('estimate_generation_credit_operations as operation', 'operation.operation_id', '=', 'physical.credit_operation_id')
            ->where($table.'.organization_id', $organizationId)->where($table.'.project_id', $projectId)->where($table.'.generation_session_id', $sessionId)
            ->where($table.'.source_type', 'composer_source_claim')->where($table.'.source_entity_type', 'ai_role_run')
            ->where($table.'.is_positive', false)->where($table.'.decision_status', 'proposed')->whereNull($table.'.accepted_at')
            ->whereNull($table.'.norm_code')->whereNull($table.'.estimate_norm_id')->whereNull($table.'.normative_dataset_version_id')
            ->where($table.'.context_payload->source_claim_validation->identity->predicate', ComposerSourceClaimValidationService::PREDICATE)
            ->whereIn($table.'.context_payload->candidate->source_answer_fact_id', $answerIds)
            ->whereIn($table.'.context_payload->source_claim_validation->identity->answer->value_hash', $answerHashes)
            ->whereIn(DB::raw($table.".context_payload #>> '{candidate,document_fact_ids,0}'"), $documentIds)
            ->where('receipt.status', 'completed')->where('receipt.role', 'estimate_composer')
            ->where('receipt.subject_type', 'estimate_session')->where('receipt.subject_id', (string) $sessionId)
            ->whereRaw("((receipt.prompt_contract_version IN ('estimate-composer:answers:v2','estimate-composer:legacy-answers:v2') AND receipt.result_payload->'schema_version' = '2'::jsonb)
                OR (receipt.prompt_contract_version IN ('estimate-composer:answer-cells:v1','estimate-composer:legacy-answer-cells:v1') AND receipt.result_payload->'schema_version' = '3'::jsonb))")
            ->whereRaw('receipt.result_payload->\'claim_candidates\' @> jsonb_build_array('.$table.'.context_payload->\'candidate\')')
            ->whereColumn('physical.request_fingerprint', 'receipt.input_fingerprint')
            ->whereColumn('physical.logical_request_fingerprint', 'receipt.input_fingerprint')
            ->whereColumn('credit.operation_id', 'physical.credit_operation_id')
            ->where('physical.credit_role', 'estimate_composition')->where('credit.role', 'estimate_composition')
            ->whereIn('credit.status', ['recorded', 'unavailable'])->whereNotNull('credit.usage_transferred_at')
            ->where('operation.kind', 'generation')->where('operation.status', 'settled')->whereNotNull('operation.receipt');
        foreach (['receipt', 'physical', 'credit', 'operation'] as $alias) {
            $query->where($alias.'.organization_id', $organizationId)->where($alias.'.project_id', $projectId)->where($alias.'.session_id', $sessionId);
        }
        $rows = $query->orderBy($table.'.id')->limit(self::MAX_EXAMPLES + 1)->get();
        if ($rows->count() > self::MAX_EXAMPLES) {
            return [];
        }
        $anchors = GeometryInventory::currentDocumentVersions($snapshot, false);
        $grouped = [];
        foreach ($rows as $row) {
            $candidate = $row->context_payload['candidate'] ?? null;
            if (! ComposerSourceClaimValidationService::isCandidate($candidate)) {
                continue;
            }
            $candidateKey = hash('sha256', CanonicalPipelineJson::encode($candidate));
            $grouped[$candidate['source_answer_fact_id']][$candidateKey]['candidate'] = $candidate;
            $grouped[$candidate['source_answer_fact_id']][$candidateKey]['rows'][] = $row;
        }
        $result = [];
        foreach ($grouped as $matches) {
            if (count($matches) !== 1) {
                continue;
            }
            $group = array_values($matches)[0];
            $candidate = $group['candidate'];
            $fresh = $this->sourceClaims->capture($scope, $snapshot, $decisions, $input, $candidate, $anchors, $checked);
            if ($fresh === null) {
                continue;
            }
            foreach ($group['rows'] as $row) {
                if ($fresh['source_claim_key'] !== $row->source_claim_key) {
                    continue;
                }
                $result[] = ['learning_example_id' => (int) $row->id, 'entity_id' => $candidate['entity_id'], 'fact_type' => $candidate['fact_type'],
                    'value' => $candidate['value'], 'unit' => null, 'document_fact_ids' => $candidate['document_fact_ids'],
                    'interpretation_status' => 'proposed', 'source_correspondence' => 'passed', 'engineering_applicability' => 'unresolved',
                    'source_answer_fact_id' => $candidate['source_answer_fact_id']];
                break;
            }
        }

        return $result;
    }

    public function cellReviewContext(array $scope, ProjectModelSnapshot $snapshot, array $decisions, Fact $source): ?array
    {
        return $this->sourceClaims->captureCellReview($scope, $snapshot, $decisions, $source);
    }

    public function cellReviewReferences(array $scope, ProjectModelSnapshot $snapshot, array $decisions, array $contexts = []): array
    {
        if ($this->recorder === null) {
            return [];
        }
        $result = [];
        $entities = array_column($snapshot->entities, null, 'id');
        $session = EstimateGenerationSession::query()->where('organization_id', $scope[0])->where('project_id', $scope[1])->find($scope[2]);
        if ($session === null) {
            return [];
        }
        $receipts = [];
        foreach ($snapshot->facts as $source) {
            $entity = $entities[$source->entityId] ?? null;
            if (! isset($entity?->attributes['properties']['document_cell_review_anchor'])) {
                continue;
            }
            $current = array_key_exists($source->id, $contexts) ? $contexts[$source->id]
                : $this->cellReviewContext($scope, $snapshot, $decisions, $source);
            if ($current === null) {
                continue;
            }
            $row = EstimateGenerationLearningExample::query()->where('organization_id', $scope[0])->where('project_id', $scope[1])
                ->where('generation_session_id', $scope[2])->where('source_type', 'composer_source_claim')->where('source_entity_type', 'ai_role_run')
                ->where('source_claim_key', $current['source_claim_key'])->where('decision_status', 'proposed')->where('is_positive', false)
                ->whereNull('accepted_at')->whereNull('norm_code')->whereNull('estimate_norm_id')->whereNull('normative_dataset_version_id')
                ->where('context_payload->origin', 'document_cell_review:v1')->first();
            if ($row === null || CanonicalPipelineJson::encode($row->context_payload['source_claim_validation']['identity'] ?? null)
                !== CanonicalPipelineJson::encode($current['identity'])) {
                continue;
            }
            $operationId = (string) ($row->source_refs['credit_operation_id'] ?? '');
            $receipt = $receipts[$operationId] ??= $this->recorder->cellReviewProposalsForOperation($session, $operationId);
            $original = array_values(array_filter($receipt, static fn (array $item): bool => $item['run_id'] === (int) $row->source_entity_id
                && $item['proposal_hash'] === ($row->context_payload['proposal_hash'] ?? null)));
            if (count($original) !== 1) {
                continue;
            }
            $result[] = ['learning_example_id' => (int) $row->id, ...$current['candidate'],
                'interpretation_status' => 'proposed', 'source_correspondence' => 'passed', 'engineering_applicability' => 'unresolved'];
            if (count($result) === ComposerSourceClaimValidationService::MAX_CLAIMS) {
                break;
            }
        }

        return $result;
    }

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
