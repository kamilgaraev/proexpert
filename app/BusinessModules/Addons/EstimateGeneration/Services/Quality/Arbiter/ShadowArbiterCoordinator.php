<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

use Throwable;

final readonly class ShadowArbiterCoordinator implements TargetedPackageRebuildReviewer
{
    public function __construct(
        private CompletenessArbiter $arbiter,
        private ArbiterReviewContextFactory $contexts = new ArbiterReviewContextFactory,
        private ArbiterVerdictValidator $validator = new ArbiterVerdictValidator,
        private ArbiterRemediationCoordinator $remediation = new ArbiterRemediationCoordinator,
    ) {}

    /** @return array<string, mixed> */
    public function review(array $draft, ?ArbiterOperationContext $operation = null): array
    {
        $previousCycle = is_array($draft['arbiter_review'] ?? null)
            && is_array($draft['arbiter_review']['cycle'] ?? null)
            ? $draft['arbiter_review']['cycle']
            : null;
        $previousRemediation = is_array($draft['arbiter_review'] ?? null)
            && is_array($draft['arbiter_review']['remediation'] ?? null)
            ? $draft['arbiter_review']['remediation']
            : null;
        $context = $this->contexts->make($draft, $operation);
        $status = 'reviewed';
        $tokens = [];
        try {
            $raw = $this->arbiter->review($context);
            $verdict = $this->validator->validate($raw, $context);
            foreach (['input_tokens', 'output_tokens'] as $key) {
                if (is_int($raw[$key] ?? null) && $raw[$key] >= 0 && $raw[$key] <= 1_000_000) {
                    $tokens[$key] = $raw[$key];
                }
            }
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
            ...$tokens,
        ];
        if ($previousCycle !== null) {
            $draft['arbiter_review']['cycle'] = $previousCycle;
        }
        if ($previousRemediation !== null) {
            $draft['arbiter_review']['remediation'] = $previousRemediation;
        }
        if ($this->hasAttemptedRemediation($previousRemediation)) {
            return $this->remediation->resolveAfterRebuild($draft, $verdict);
        }

        return $this->remediation->recordShadowCycle($draft, $verdict, $context['input_hash']);
    }

    /** @param array<string, mixed>|null $remediation */
    private function hasAttemptedRemediation(?array $remediation): bool
    {
        if ($remediation === null) {
            return false;
        }

        try {
            $state = ArbiterRemediationState::fromArray($remediation);
        } catch (Throwable) {
            return false;
        }

        return $state->phase === 'attempted' && $state->rebuildAttempted;
    }
}
