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
    public function handle(int $organizationId, int $projectId, int $sessionId): array
    {
        $answered = array_fill_keys($this->answers->answeredKeys($organizationId, $projectId, $sessionId), true);
        $result = [];
        foreach ($this->catalog->allCurrent($organizationId, $projectId, $sessionId) as $current) {
            if (isset($answered[$current->question->code])) {
                continue;
            }
            $result[] = [
                ...$current->question->toArray(),
                'source_version' => $current->sourceVersion,
                'answer_fingerprint' => $current->answerFingerprint,
                'status' => 'unanswered',
            ];
        }

        return $result;
    }
}
