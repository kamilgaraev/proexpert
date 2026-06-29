<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class NormativeCandidateFeedbackService
{
    /**
     * @var (callable(string): string)|null
     */
    private $messageResolver;

    /**
     * @var (callable(array<string, array<int, string>>): ValidationException)|null
     */
    private $validationExceptionFactory;

    public function __construct(
        private readonly EstimateValidationService $validationService,
        private readonly EstimateGenerationPackagePersistenceService $packagePersistenceService,
        ?callable $messageResolver = null,
        ?callable $validationExceptionFactory = null,
    ) {
        $this->messageResolver = $messageResolver;
        $this->validationExceptionFactory = $validationExceptionFactory;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function apply(EstimateGenerationSession $session, EstimateGenerationFeedback $feedback): ?array
    {
        if ($feedback->feedback_type !== 'normative_rejection') {
            return null;
        }

        $payload = is_array($feedback->payload) ? $feedback->payload : [];
        $draft = is_array($session->draft_payload ?? null) ? $session->draft_payload : [];
        $draft = $this->applyRejectionToDraft(
            $draft,
            (string) $feedback->work_item_key,
            $payload,
            $feedback->comments
        );
        $draft = $this->validationService->validate($draft);
        $this->packagePersistenceService->syncFromDraft($session, $draft);

        $session->forceFill([
            'draft_payload' => $draft,
            'problem_flags' => $draft['problem_flags'] ?? [],
            'status' => $this->draftRequiresReview($draft) ? 'review_required' : 'ready_for_review',
            'processing_stage' => 'validation_and_normalization',
            'processing_progress' => 100,
            'last_error' => null,
        ])->save();

        return $draft;
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function applyRejectionToDraft(array $draft, string $workItemKey, array $payload, ?string $comments = null): array
    {
        $found = false;
        $rejectedNormId = $this->nullableInt($payload['norm_id'] ?? null);
        $rejectedCode = $this->nullableString($payload['normative_code'] ?? null);
        $reason = $this->nullableString($payload['reason'] ?? null);

        if ($rejectedNormId === null && $rejectedCode === null) {
            throw $this->validationException([
                'payload.norm_id' => [$this->message('estimate_generation.normative_feedback_norm_required')],
            ]);
        }

        foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
            if (!is_array($localEstimate)) {
                continue;
            }

            foreach ($localEstimate['sections'] ?? [] as $sectionIndex => $section) {
                if (!is_array($section)) {
                    continue;
                }

                foreach ($section['work_items'] ?? [] as $workIndex => $workItem) {
                    if (!is_array($workItem) || (string) ($workItem['key'] ?? '') !== $workItemKey) {
                        continue;
                    }

                    $found = true;
                    $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$workIndex] = $this->rejectWorkItemNorm(
                        $workItem,
                        $rejectedNormId,
                        $rejectedCode,
                        $reason,
                        $comments
                    );
                    break 3;
                }
            }
        }

        if (!$found) {
            throw $this->validationException([
                'work_item_key' => [$this->message('estimate_generation.work_item_not_found')],
            ]);
        }

        return $draft;
    }

    /**
     * @param array<string, mixed> $workItem
     * @return array<string, mixed>
     */
    private function rejectWorkItemNorm(
        array $workItem,
        ?int $rejectedNormId,
        ?string $rejectedCode,
        ?string $reason,
        ?string $comments
    ): array {
        $currentMatch = is_array($workItem['normative_match'] ?? null) ? $workItem['normative_match'] : [];
        $rejectsCurrentMatch = $this->matchesNormIdentity($currentMatch, $rejectedNormId, $rejectedCode);
        $candidates = is_array($workItem['normative_candidates'] ?? null) ? $workItem['normative_candidates'] : [];
        $rejectsOfferedCandidate = $this->hasMatchingCandidate($candidates, $rejectedNormId, $rejectedCode);

        if (!$rejectsCurrentMatch && !$rejectsOfferedCandidate) {
            throw $this->validationException([
                'payload.norm_id' => [$this->message('estimate_generation.normative_feedback_norm_not_found')],
            ]);
        }

        $workItem['normative_candidates'] = $this->markRejectedCandidates(
            $candidates,
            $rejectedNormId,
            $rejectedCode,
            $reason
        );
        $workItem['metadata'] = [
            ...(is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : []),
            'normative_feedback' => [
                'status' => 'rejected_by_user',
                'norm_id' => $rejectedNormId,
                'normative_code' => $rejectedCode,
                'reason' => $reason,
                'comments' => $comments,
            ],
        ];

        if (!$rejectsCurrentMatch) {
            return $workItem;
        }

        $warnings = $this->uniqueStrings([
            ...(is_array($currentMatch['warnings'] ?? null) ? $currentMatch['warnings'] : []),
            'rejected_by_user',
        ]);
        $decision = is_array($currentMatch['decision'] ?? null) ? $currentMatch['decision'] : [];
        $workItem['normative_match'] = [
            ...$currentMatch,
            'status' => 'rejected',
            'warnings' => $warnings,
            'decision' => [
                ...$decision,
                'status' => 'rejected',
                'can_use_for_pricing' => false,
                'warnings' => $this->uniqueStrings([
                    ...(is_array($decision['warnings'] ?? null) ? $decision['warnings'] : []),
                    'rejected_by_user',
                    'safe_norm_required',
                ]),
                'reasons' => $this->uniqueStrings([
                    ...(is_array($decision['reasons'] ?? null) ? $decision['reasons'] : []),
                    'user_feedback',
                ]),
            ],
        ];
        $workItem['normative_rate_code'] = null;
        $workItem['materials'] = [];
        $workItem['labor'] = [];
        $workItem['machinery'] = [];
        $workItem['other_resources'] = [];
        $workItem['work_cost'] = 0.0;
        $workItem['materials_cost'] = 0.0;
        $workItem['machinery_cost'] = 0.0;
        $workItem['labor_cost'] = 0.0;
        $workItem['total_cost'] = 0.0;
        $workItem['price_source'] = null;
        $workItem['pricing_status'] = 'not_calculated';
        $workItem['pricing_blocker'] = 'normative_rejected';
        $workItem['validation_flags'] = $this->uniqueStrings([
            ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
            'normative_rejected_by_user',
            'requires_normative_review',
            'safe_norm_required',
            'pricing_not_calculated',
        ]);

        return $workItem;
    }

    /**
     * @param array<int, mixed> $candidates
     * @return array<int, mixed>
     */
    private function markRejectedCandidates(array $candidates, ?int $rejectedNormId, ?string $rejectedCode, ?string $reason): array
    {
        return array_map(function (mixed $candidate) use ($rejectedNormId, $rejectedCode, $reason): mixed {
            if (!is_array($candidate) || !$this->matchesNormIdentity($candidate, $rejectedNormId, $rejectedCode)) {
                return $candidate;
            }

            return [
                ...$candidate,
                'user_feedback' => 'rejected',
                'rejected_by_user' => true,
                'rejection_reason' => $reason,
                'warnings' => $this->uniqueStrings([
                    ...(is_array($candidate['warnings'] ?? null) ? $candidate['warnings'] : []),
                    'rejected_by_user',
                ]),
            ];
        }, $candidates);
    }

    /**
     * @param array<int, mixed> $candidates
     */
    private function hasMatchingCandidate(array $candidates, ?int $rejectedNormId, ?string $rejectedCode): bool
    {
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $this->matchesNormIdentity($candidate, $rejectedNormId, $rejectedCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function matchesNormIdentity(array $candidate, ?int $normId, ?string $normativeCode): bool
    {
        if ($normId !== null && (int) ($candidate['norm_id'] ?? $candidate['id'] ?? 0) === $normId) {
            return true;
        }

        if ($normativeCode !== null && $this->normalizeCode((string) ($candidate['code'] ?? '')) === $this->normalizeCode($normativeCode)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private function uniqueStrings(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== ''
        )));
    }

    private function normalizeCode(string $code): string
    {
        return mb_strtolower(trim($code));
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function message(string $key): string
    {
        if ($this->messageResolver !== null) {
            return ($this->messageResolver)($key);
        }

        return trans_message($key);
    }

    /**
     * @param array<string, array<int, string>> $messages
     */
    private function validationException(array $messages): ValidationException
    {
        if ($this->validationExceptionFactory !== null) {
            return ($this->validationExceptionFactory)($messages);
        }

        return ValidationException::withMessages($messages);
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function draftRequiresReview(array $draft): bool
    {
        return (int) data_get($draft, 'quality_summary.normative_items.requires_review', 0) > 0
            || (int) data_get($draft, 'quality_summary.not_calculated_work_items', 0) > 0
            || (int) data_get($draft, 'quality_summary.safe_norm_required_work_items', 0) > 0
            || (int) data_get($draft, 'quality_summary.duplicate_work_items', 0) > 0;
    }
}
