<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

use Throwable;

final readonly class ShadowArbiterCoordinator
{
    public function __construct(
        private CompletenessArbiter $arbiter,
        private ArbiterReviewContextFactory $contexts = new ArbiterReviewContextFactory,
        private ArbiterVerdictValidator $validator = new ArbiterVerdictValidator,
    ) {}

    /** @return array<string, mixed> */
    public function review(array $draft, ?ArbiterOperationContext $operation = null): array
    {
        $context = $this->contexts->make($draft, $operation);
        $status = 'reviewed';
        try {
            $verdict = $this->validator->validate($this->arbiter->review($context), $context);
        } catch (Throwable) {
            $status = 'unavailable';
            $verdict = new ArbiterVerdict('human_review', []);
        }
        $draft['arbiter_review'] = [
            'mode' => 'shadow',
            'status' => $status,
            'outcome' => $verdict->outcome,
            'input_hash' => $context['input_hash'],
            'schema_version' => $context['schema_version'],
            'prompt_version' => $this->arbiter->promptVersion(),
            'model' => $this->arbiter->model(),
            'findings' => $verdict->findings,
        ];

        return $draft;
    }
}
