<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

final readonly class ListEstimateClarifications
{
    public function __construct(
        private EstimateClarificationCatalog $catalog,
        private EstimateClarificationAnswerRegistry $answers,
    ) {}

    /** @return list<array<string,mixed>> */
    public function handle(int $organizationId, int $projectId, int $sessionId, ?\App\Models\User $actor = null): array
    {
        $answered = array_fill_keys($this->answers->answeredKeys($organizationId, $projectId, $sessionId), true);
        $result = [];
        foreach ($this->catalog->allCurrent($organizationId, $projectId, $sessionId) as $current) {
            if (($current->question->sourceLocator['kind'] ?? null) === 'composer_work_technology') {
                $answer = $current->workAnswer;
                $canAnswer = $actor !== null && (int) $actor->current_organization_id === $organizationId
                    && app(\App\Domain\Authorization\Services\AuthorizationService::class)->can($actor, 'estimate_generation.review',
                        ['organization_id' => $organizationId, 'project_id' => $projectId]);
                $result[] = [...$current->question->toArray(), 'source_version' => $current->sourceVersion,
                    'answer_fingerprint' => $current->answerFingerprint,
                    'status' => $answer === null ? 'unanswered' : match ($answer['response']) {
                        'other' => 'answered', 'revoke' => 'revoked', default => 'left_unresolved',
                    },
                    'answer' => $answer, 'actions' => ! $canAnswer ? [] : ($answer === null ? [($current->composerContext['previous_fact_id'] ?? null) === null ? 'answer' : 'reanswer']
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
}
