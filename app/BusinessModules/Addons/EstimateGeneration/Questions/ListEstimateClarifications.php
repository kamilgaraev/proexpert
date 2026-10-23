<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningEvidenceService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

final readonly class ListEstimateClarifications
{
    public function __construct(
        private EstimateClarificationCatalog $catalog,
        private EstimateClarificationAnswerRegistry $answers,
        private ?ProjectModelRepository $models = null,
        private ?EstimateGenerationLearningEvidenceService $evidence = null,
        private ?EstimateGenerationActionAuthorization $authorization = null,
        private ?DatabaseManager $database = null,
        private int $maxFacts = 10001,
    ) {}

    /** @return list<array<string,mixed>> */
    public function handle(int $organizationId, int $projectId, int $sessionId, ?\App\Models\User $actor = null): array
    {
        $answered = array_fill_keys($this->answers->answeredKeys($organizationId, $projectId, $sessionId), true);
        $result = [];
        $canAnswerWork = null;
        [$questions, $checks] = $this->currentWithSourceChecks($organizationId, $projectId, $sessionId, $actor);
        foreach ($questions as $current) {
            if (($current->question->sourceLocator['kind'] ?? null) === 'composer_work_technology') {
                $answer = $current->workAnswer;
                $canAnswerWork ??= $actor !== null && (int) $actor->current_organization_id === $organizationId
                    && \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()
                        ->where('organization_id', $organizationId)->where('project_id', $projectId)->whereKey($sessionId)
                        ->where('status', \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus::EstimateReviewRequired->value)->exists()
                    && app(\App\Domain\Authorization\Services\AuthorizationService::class)->can($actor, 'estimate_generation.review',
                        ['organization_id' => $organizationId, 'project_id' => $projectId]);
                $result[] = [...$current->question->toArray(), 'source_version' => $current->sourceVersion,
                    'answer_fingerprint' => $current->answerFingerprint,
                    'status' => $answer === null ? 'unanswered' : match ($answer['response']) {
                        'other' => 'answered', 'revoke' => 'revoked', default => 'left_unresolved',
                    },
                    'answer' => $answer, 'source_checks' => $checks[$current->question->code] ?? [],
                    'actions' => ! $canAnswerWork ? [] : ($answer === null ? [($current->composerContext['previous_fact_id'] ?? null) === null ? 'answer' : 'reanswer']
                        : ($answer['response'] === 'other' ? ['reanswer', 'revoke'] : ['reanswer']))];
                continue;
            }
            if (isset($answered[$current->question->code])) {
                continue;
            }
            $result[] = [
                ...$current->question->toArray(),
                'source_version' => $current->sourceVersion,
                'answer_fingerprint' => $current->answerFingerprint,
                'status' => 'unanswered',
                ...($current->targetOptions === [] ? [] : ['target_options' => $current->targetOptions]),
            ];
        }

        return $result;
    }

    private function currentWithSourceChecks(int $organizationId, int $projectId, int $sessionId, ?User $actor): array
    {
        $fallback = fn (): array => [$this->catalog->allCurrent($organizationId, $projectId, $sessionId), []];
        if ($actor === null || $this->models === null || $this->evidence === null || $this->authorization === null
            || $this->database === null || $this->maxFacts < 1 || $this->maxFacts > 10001) {
            return $fallback();
        }
        $connection = $this->database->connection();
        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() !== 0) {
            return $fallback();
        }
        $session = EstimateGenerationSession::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->find($sessionId);
        if ($session === null) {
            return $fallback();
        }
        try {
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');
        } catch (AuthorizationException) {
            return $fallback();
        }
        try {
            return $connection->transaction(function () use ($connection, $organizationId, $projectId, $sessionId): array {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                $questions = $this->catalog->allCurrent($organizationId, $projectId, $sessionId);
                if (array_filter($questions, static fn (CurrentEstimateClarification $current): bool =>
                    ($current->question->sourceLocator['kind'] ?? null) === 'composer_work_technology'
                    && ($current->workAnswer['response'] ?? null) === 'other') === []) {
                    return [$questions, []];
                }
                $session = EstimateGenerationSession::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->find($sessionId);
                if ($session === null) {
                    return [$questions, []];
                }
                $capture = $this->models->snapshotForPlanning($organizationId, $projectId, $sessionId, $this->maxFacts);
                $snapshot = $capture['snapshot'];
                if (count($snapshot->facts) >= $this->maxFacts) {
                    return [$questions, []];
                }
                $decisions = $this->models->decisionsForSelectedFacts($organizationId, $projectId, $sessionId, array_column($snapshot->facts, 'id'));
                $references = $this->evidence->sourceClaimReferences($organizationId, $projectId, $sessionId, $snapshot,
                    $decisions, $session->input_payload ?? [], includeSourceDetails: true);
                $checks = [];
                foreach ($questions as $current) {
                    if (($current->question->sourceLocator['kind'] ?? null) !== 'composer_work_technology'
                        || ($current->workAnswer['response'] ?? null) !== 'other' || $current->snapshotToken !== $capture['token']) {
                        continue;
                    }
                    $matches = array_values(array_filter($references, static fn (array $reference): bool => isset($reference['source_proof'])
                        && $reference['source_answer_fact_id'] === $current->workAnswer['fact_id']
                        && $reference['entity_id'] === ($current->question->sourceLocator['entity_id'] ?? null)));
                    if (count($matches) !== 1) {
                        continue;
                    }
                    $reference = $matches[0];
                    $checks[$current->question->code] = [['source_answer_fact_id' => $reference['source_answer_fact_id'],
                        'entity_id' => $reference['entity_id'], 'fact_type' => $reference['fact_type'], 'value' => $reference['value'],
                        'source' => $reference['source_proof'], 'interpretation_status' => 'proposed', 'source_correspondence' => 'passed',
                        'engineering_applicability' => 'unresolved', 'numeric_parameters_confirmed' => false]];
                }
                return [$questions, $checks];
            });
        } catch (\Illuminate\Database\QueryException) {
            return $fallback();
        }
    }
}
