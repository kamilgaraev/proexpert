<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\PlanningReanalysisTrigger;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

use function trans_message;

final class AnswerEstimateClarification
{
    private Closure $translate;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly EstimateClarificationSource $source,
        private readonly ProjectModelRepository $models,
        private readonly ApplyProjectModelDecision $decisions,
        private readonly PlanningReanalysisTrigger $reanalysis,
        ?Closure $translate = null,
    ) {
        $this->translate = $translate ?? static fn (string $key): string => trans_message($key);
    }

    public function handle(
        User $actor,
        EstimateGenerationSession $session,
        ActorContext $context,
        string $questionKey,
        string $response,
        ?string $other = null,
    ): EstimateClarificationAnswer {
        $this->authorize($actor, $session, $context);
        if ($context->expectedSourceVersion === null || $context->expectedValueFingerprint === null) {
            throw new InvalidArgumentException('estimate_generation.question_fence_required');
        }
        $decisionId = 'decision:clarification:'.hash('sha256', $context->idempotencyKey);
        $existing = $this->models->decisions(
            $context->organizationId,
            $context->projectId,
            (int) $session->getKey(),
            [$decisionId],
        );
        if ($existing !== []) {
            [$decision, $selected] = $this->replay(
                $existing[0],
                $context,
                $questionKey,
                $response,
                $other,
            );
            if ($this->models->currentCompleteness(
                $context->organizationId,
                $context->projectId,
                (int) $session->getKey(),
            ) === null) {
                $this->reanalysis->trigger((int) $session->getKey(), $context);
            }

            return $this->replayResult($decision, $selected);
        }
        $current = $this->source->findCurrent(
            $context->organizationId,
            $context->projectId,
            (int) $session->getKey(),
            $questionKey,
        );
        if (! $current instanceof CurrentEstimateClarification) {
            throw new InvalidArgumentException('estimate_generation.question_not_found');
        }
        if (($context->expectedSourceVersion !== null
                && ! hash_equals($context->expectedSourceVersion, $current->sourceVersion))
            || ($context->expectedValueFingerprint !== null
                && ! hash_equals($context->expectedValueFingerprint, $current->answerFingerprint))) {
            throw new InvalidArgumentException('estimate_generation.question_stale');
        }
        [$normalizedResponse, $choiceLabel, $normalizedOther] = $this->answer($current->question, $response, $other);
        $decision = $this->decisions->applyClarificationChoice(
            organizationId: $context->organizationId,
            projectId: $context->projectId,
            sessionId: (int) $session->getKey(),
            sourceVersion: $current->sourceVersion,
            factId: $current->targetFactId,
            questionKey: $current->question->code,
            response: $normalizedResponse,
            choiceValue: $response,
            choiceLabel: $choiceLabel,
            other: $normalizedOther,
            questionFingerprint: $current->answerFingerprint,
            sourceLocator: $current->question->sourceLocator,
            actorId: (string) $context->actorId,
            reason: $current->question->subject,
            decisionId: $decisionId,
        );
        $this->reanalysis->trigger((int) $session->getKey(), $context);

        return $this->result($current, $decision, $normalizedResponse, $choiceLabel, $normalizedOther);
    }

    private function authorize(User $actor, EstimateGenerationSession $session, ActorContext $context): void
    {
        if ((int) $actor->getKey() !== $context->actorId
            || (int) $actor->current_organization_id !== $context->organizationId
            || (int) $session->organization_id !== $context->organizationId
            || (int) $session->project_id !== $context->projectId
            || ! $this->authorization->can($actor, 'estimate_generation.review', [
                'organization_id' => $context->organizationId,
                'project_id' => $context->projectId,
            ])) {
            throw new AuthorizationException(($this->translate)('estimate_generation.access_denied'));
        }
    }

    /** @return array{string,?string,?string} */
    private function answer(EstimateClarificationQuestion $question, string $response, ?string $other): array
    {
        $choice = null;
        foreach ($question->choices as $candidate) {
            if ($candidate->value === $response) {
                $choice = $candidate;
                break;
            }
        }
        if (! $choice instanceof EstimateClarificationChoice) {
            throw new InvalidArgumentException('estimate_generation.question_response_invalid');
        }
        if ($choice->kind === 'other') {
            $normalizedOther = trim((string) $other);
            if ($normalizedOther === '' || mb_strlen($normalizedOther) > 500) {
                throw new InvalidArgumentException('estimate_generation.question_other_invalid');
            }

            return ['other', $choice->label, $normalizedOther];
        }
        if ($other !== null) {
            throw new InvalidArgumentException('estimate_generation.question_response_invalid');
        }

        return [$choice->kind === 'leave_unresolved' ? 'leave_unresolved' : 'selected', $choice->label, null];
    }

    /** @return array{Decision,Fact} */
    private function replay(
        Decision $decision,
        ActorContext $context,
        string $questionKey,
        string $response,
        ?string $other,
    ): array {
        $selected = $decision->selectedFactId === null ? null : $this->models->fact(
            $context->organizationId,
            $context->projectId,
            $decision->sessionId,
            $decision->selectedFactId,
        );
        $normalizedOther = $other === null ? null : trim($other);
        if (! $selected instanceof Fact
            || ! is_array($selected->value)
            || ($selected->value['question_key'] ?? null) !== $questionKey
            || ($selected->value['choice_value'] ?? null) !== $response
            || ($selected->value['other'] ?? null) !== $normalizedOther
            || ($selected->value['question_fingerprint'] ?? null) !== $context->expectedValueFingerprint
            || ! hash_equals($decision->sourceVersion, $context->expectedSourceVersion)
            || $decision->organizationId !== $context->organizationId
            || $decision->projectId !== $context->projectId
            || $decision->actorId !== (string) $context->actorId) {
            throw new InvalidArgumentException('estimate_generation.question_idempotency_collision');
        }

        return [$decision, $selected];
    }

    private function result(
        CurrentEstimateClarification $current,
        Decision $decision,
        string $response,
        ?string $choiceLabel,
        ?string $other,
    ): EstimateClarificationAnswer {
        return new EstimateClarificationAnswer(
            $current->question->code,
            $response === 'leave_unresolved' ? 'left_unresolved' : 'answered',
            $response,
            $choiceLabel,
            $other,
            $decision->id,
            $current->question->sourceLocator,
        );
    }

    private function replayResult(Decision $decision, Fact $selected): EstimateClarificationAnswer
    {
        $value = $selected->value;

        return new EstimateClarificationAnswer(
            (string) $value['question_key'],
            ($value['response'] ?? null) === 'leave_unresolved' ? 'left_unresolved' : 'answered',
            (string) $value['response'],
            is_string($value['choice_label'] ?? null) ? $value['choice_label'] : null,
            is_string($value['other'] ?? null) ? $value['other'] : null,
            $decision->id,
            is_array($value['source_locator'] ?? null) ? $value['source_locator'] : [],
        );
    }
}
