<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNoAirWorkItemPolicy;
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
        private readonly EstimateGenerationNoAirWorkItemPolicy $noAirWorkItemPolicy = new EstimateGenerationNoAirWorkItemPolicy(),
    ) {
        $this->messageResolver = $messageResolver;
        $this->validationExceptionFactory = $validationExceptionFactory;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function apply(EstimateGenerationSession $session, EstimateGenerationFeedback $feedback): ?array
    {
        if (!in_array($feedback->feedback_type, ['normative_rejection', 'normative_confirmation', 'quantity_confirmation', 'duplicate_resolution', 'work_item_resolution'], true)) {
            return null;
        }

        $payload = is_array($feedback->payload) ? $feedback->payload : [];
        $draft = is_array($session->draft_payload ?? null) ? $session->draft_payload : [];
        $draft = match ($feedback->feedback_type) {
            'quantity_confirmation' => $this->applyQuantityConfirmationToDraft(
                $draft,
                (string) $feedback->work_item_key,
                $payload,
                $feedback->comments
            ),
            'duplicate_resolution' => $this->applyDuplicateResolutionToDraft(
                $draft,
                (string) $feedback->work_item_key,
                $payload,
                $feedback->comments
            ),
            'normative_confirmation' => $this->applyNormativeConfirmationToDraft(
                $draft,
                (string) $feedback->work_item_key,
                $payload,
                $feedback->comments
            ),
            'work_item_resolution' => $this->applyWorkItemResolutionToDraft(
                $draft,
                (string) $feedback->work_item_key,
                $payload,
                $feedback->comments
            ),
            default => $this->applyRejectionToDraft(
                $draft,
                (string) $feedback->work_item_key,
                $payload,
                $feedback->comments
            ),
        };
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
    public function applyDuplicateResolutionToDraft(array $draft, string $workItemKey, array $payload, ?string $comments = null): array
    {
        $action = $this->nullableString($payload['action'] ?? null);

        if (!in_array($action, ['remove_item', 'keep_item', 'merge_with_existing'], true)) {
            throw $this->validationException([
                'payload.action' => [$this->message('estimate_generation.duplicate_resolution_action_required')],
            ]);
        }

        $targetWorkItemKey = $this->nullableString($payload['target_work_item_key'] ?? null);

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

                    return $this->resolveDuplicateWorkItem(
                        $draft,
                        (int) $localIndex,
                        (int) $sectionIndex,
                        (int) $workIndex,
                        $workItem,
                        $action,
                        $targetWorkItemKey,
                        $comments
                    );
                }
            }
        }

        throw $this->validationException([
            'work_item_key' => [$this->message('estimate_generation.work_item_not_found')],
        ]);
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function applyWorkItemResolutionToDraft(array $draft, string $workItemKey, array $payload, ?string $comments = null): array
    {
        $action = $this->nullableString($payload['action'] ?? null);

        if ($action !== 'remove_item') {
            throw $this->validationException([
                'payload.action' => [$this->message('estimate_generation.work_item_resolution_action_required')],
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

                    return $this->removeGenericWorkItem(
                        $draft,
                        (int) $localIndex,
                        (int) $sectionIndex,
                        (int) $workIndex,
                        $workItem,
                        $comments
                    );
                }
            }
        }

        throw $this->validationException([
            'work_item_key' => [$this->message('estimate_generation.work_item_not_found')],
        ]);
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function applyQuantityConfirmationToDraft(array $draft, string $workItemKey, array $payload, ?string $comments = null): array
    {
        $found = false;
        $quantity = $this->nullableFloat($payload['quantity'] ?? null);

        if ($quantity === null || $quantity <= 0) {
            throw $this->validationException([
                'payload.quantity' => [$this->message('estimate_generation.quantity_confirmation_quantity_required')],
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
                    $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$workIndex] = $this->confirmWorkItemQuantity(
                        $workItem,
                        $quantity,
                        $this->nullableString($payload['unit'] ?? null),
                        $this->nullableString($payload['quantity_basis'] ?? null),
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
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function applyNormativeConfirmationToDraft(array $draft, string $workItemKey, array $payload, ?string $comments = null): array
    {
        $found = false;
        $normId = $this->nullableInt($payload['norm_id'] ?? null);
        $normativeCode = $this->nullableString($payload['normative_code'] ?? null);

        if ($normId === null && $normativeCode === null) {
            throw $this->validationException([
                'payload.norm_id' => [$this->message('estimate_generation.normative_confirmation_norm_required')],
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
                    $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$workIndex] = $this->confirmCurrentWorkItemNorm(
                        $workItem,
                        $normId,
                        $normativeCode,
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
     * @param array<string, mixed> $workItem
     * @return array<string, mixed>
     */
    private function confirmCurrentWorkItemNorm(
        array $workItem,
        ?int $normId,
        ?string $normativeCode,
        ?string $comments
    ): array {
        $currentMatch = is_array($workItem['normative_match'] ?? null) ? $workItem['normative_match'] : [];

        if ($currentMatch === [] || !$this->matchesNormIdentity($currentMatch, $normId, $normativeCode)) {
            throw $this->validationException([
                'payload.norm_id' => [$this->message('estimate_generation.normative_feedback_norm_not_found')],
            ]);
        }

        if (!$this->requiresNormativeConfirmation($workItem, $currentMatch)) {
            throw $this->validationException([
                'work_item_key' => [$this->message('estimate_generation.normative_confirmation_not_required')],
            ]);
        }

        if (!$this->canConfirmCalculatedNorm($workItem)) {
            throw $this->validationException([
                'work_item_key' => [$this->message('estimate_generation.normative_confirmation_price_required')],
            ]);
        }

        $decision = is_array($currentMatch['decision'] ?? null) ? $currentMatch['decision'] : [];
        $workItem['pricing_status'] = 'calculated';
        $workItem['pricing_blocker'] = null;
        $workItem['pricing_blocker_message'] = null;
        $workItem['normative_match'] = [
            ...$currentMatch,
            'status' => 'matched',
            'selected_by_user' => true,
            'user_confirmed' => true,
            'warnings' => $this->withoutNormativeReviewWarnings($this->arrayValues($currentMatch['warnings'] ?? [])),
            'decision' => [
                ...$decision,
                'status' => 'accepted',
                'can_use_for_pricing' => true,
                'user_confirmed' => true,
                'warnings' => $this->withoutNormativeReviewWarnings($this->arrayValues($decision['warnings'] ?? [])),
                'reasons' => $this->uniqueStrings([
                    ...$this->arrayValues($decision['reasons'] ?? []),
                    'user_confirmed',
                ]),
            ],
        ];
        $workItem['validation_flags'] = $this->withoutNormativeReviewFlags($this->arrayValues($workItem['validation_flags'] ?? []));
        $workItem['metadata'] = [
            ...(is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : []),
            'normative_confirmation' => [
                'status' => 'confirmed_by_user',
                'norm_id' => $normId,
                'normative_code' => $normativeCode,
                'comments' => $comments,
                'previous_decision_status' => $decision['status'] ?? null,
            ],
        ];

        return $workItem;
    }

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $currentMatch
     */
    private function requiresNormativeConfirmation(array $workItem, array $currentMatch): bool
    {
        $flags = $this->arrayValues($workItem['validation_flags'] ?? []);
        $decisionStatus = (string) data_get($currentMatch, 'decision.status', '');

        return (string) ($workItem['pricing_status'] ?? '') === 'calculated_review_required'
            || $decisionStatus === 'review_priced'
            || array_intersect($flags, [
                'requires_normative_review',
                'safe_normative_analog',
                'normative_match_low_confidence',
                'price_review_required',
            ]) !== [];
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function canConfirmCalculatedNorm(array $workItem): bool
    {
        return (float) ($workItem['quantity'] ?? 0) > 0
            && (float) ($workItem['total_cost'] ?? 0) > 0
            && $this->nullableString($workItem['normative_rate_code'] ?? data_get($workItem, 'normative_match.code')) !== null
            && (
                $this->arrayValues($workItem['materials'] ?? []) !== []
                || $this->arrayValues($workItem['labor'] ?? []) !== []
                || $this->arrayValues($workItem['machinery'] ?? []) !== []
            );
    }

    /**
     * @param array<int, mixed> $flags
     * @return array<int, string>
     */
    private function withoutNormativeReviewFlags(array $flags): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $flag): string => trim((string) $flag), $flags),
            static fn (string $flag): bool => $flag !== ''
                && !in_array($flag, [
                    'requires_normative_review',
                    'safe_normative_analog',
                    'normative_match_low_confidence',
                    'low_confidence',
                    'price_review_required',
                ], true)
        ));
    }

    /**
     * @param array<int, mixed> $warnings
     * @return array<int, string>
     */
    private function withoutNormativeReviewWarnings(array $warnings): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $warning): string => trim((string) $warning), $warnings),
            static fn (string $warning): bool => $warning !== ''
                && !in_array($warning, [
                    'requires_normative_review',
                    'safe_normative_analog',
                    'low_confidence',
                ], true)
        ));
    }

    /**
     * @param array<string, mixed> $workItem
     * @return array<string, mixed>
     */
    private function confirmWorkItemQuantity(
        array $workItem,
        float $quantity,
        ?string $unit,
        ?string $quantityBasis,
        ?string $comments
    ): array {
        if ((string) ($workItem['item_type'] ?? '') !== 'quantity_review') {
            throw $this->validationException([
                'work_item_key' => [$this->message('estimate_generation.quantity_confirmation_not_required')],
            ]);
        }

        $flags = $this->uniqueStrings(array_filter(
            is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : [],
            static fn (mixed $flag): bool => (string) $flag !== 'quantity_review_required'
        ));

        $workItem['item_type'] = 'priced_work';
        $workItem['quantity'] = round($quantity, 4);
        $workItem['unit'] = $unit ?? (string) ($workItem['unit'] ?? '');
        $workItem['quantity_basis'] = $quantityBasis ?? (string) ($workItem['quantity_basis'] ?? '');
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
        $workItem['pricing_blocker'] = 'normative_required';
        $workItem['pricing_blocker_message'] = null;
        $workItem['validation_flags'] = $this->uniqueStrings([
            ...$flags,
            'normative_required',
            'safe_norm_required',
            'pricing_not_calculated',
        ]);
        $workItem['metadata'] = [
            ...(is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : []),
            'display_role' => 'priced_work',
            'normative_grounding_policy' => 'fsnb_required',
            'quantity_feedback' => [
                'status' => 'confirmed_by_user',
                'quantity' => round($quantity, 4),
                'unit' => $workItem['unit'],
                'quantity_basis' => $workItem['quantity_basis'],
                'comments' => $comments,
            ],
        ];

        return $workItem;
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $workItem
     * @return array<string, mixed>
     */
    private function resolveDuplicateWorkItem(
        array $draft,
        int $localIndex,
        int $sectionIndex,
        int $workIndex,
        array $workItem,
        string $action,
        ?string $targetWorkItemKey,
        ?string $comments
    ): array {
        $workItems = $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] ?? [];
        $workItems = is_array($workItems) ? array_values($workItems) : [];
        $signature = $this->duplicateSignature($workItem);
        $matchingIndexes = $signature !== null ? $this->matchingDuplicateIndexes($workItems, $signature) : [];
        $isDuplicateReviewItem = $this->isDuplicateReviewItem($workItem) || count($matchingIndexes) > 1;

        if (!$isDuplicateReviewItem) {
            throw $this->validationException([
                'work_item_key' => [$this->message('estimate_generation.duplicate_resolution_not_required')],
            ]);
        }

        $removedKeys = [];
        $keptKey = (string) ($workItem['key'] ?? '');

        if ($action === 'remove_item') {
            $removedKeys[] = $keptKey;
            array_splice($workItems, $workIndex, 1);
            $keptKey = $this->firstRemainingDuplicateKey($workItems, $signature);
        } else {
            $targetKey = $action === 'merge_with_existing'
                ? ($targetWorkItemKey ?? $keptKey)
                : $keptKey;
            $targetIsInGroup = false;

            foreach ($matchingIndexes as $matchingIndex) {
                if ((string) ($workItems[$matchingIndex]['key'] ?? '') === $targetKey) {
                    $targetIsInGroup = true;
                    break;
                }
            }

            if (!$targetIsInGroup) {
                throw $this->validationException([
                    'payload.target_work_item_key' => [$this->message('estimate_generation.work_item_not_found')],
                ]);
            }

            $mergedItems = [];

            foreach (array_reverse($matchingIndexes) as $matchingIndex) {
                if ((string) ($workItems[$matchingIndex]['key'] ?? '') === $targetKey) {
                    continue;
                }

                if ($action === 'merge_with_existing' && is_array($workItems[$matchingIndex] ?? null)) {
                    $mergedItems[] = $workItems[$matchingIndex];
                }

                $removedKeys[] = (string) ($workItems[$matchingIndex]['key'] ?? '');
                array_splice($workItems, $matchingIndex, 1);
            }

            $keptKey = $targetKey;
            $workItems = array_map(function (mixed $candidate) use ($keptKey, $comments, $action, $removedKeys, $mergedItems): mixed {
                if (!is_array($candidate) || (string) ($candidate['key'] ?? '') !== $keptKey) {
                    return $candidate;
                }

                if ($action === 'merge_with_existing') {
                    $candidate = $this->mergeDuplicateEvidence($candidate, $mergedItems);
                }

                return $this->markDuplicateKeptByUser(
                    $candidate,
                    $comments,
                    $removedKeys,
                    $action === 'merge_with_existing' ? 'merged_by_user' : 'kept_by_user'
                );
            }, $workItems);
        }

        if ($removedKeys === []) {
            throw $this->validationException([
                'work_item_key' => [$this->message('estimate_generation.duplicate_resolution_not_required')],
            ]);
        }

        $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] = array_values($workItems);
        $draft['review_decisions'] = [
            ...(is_array($draft['review_decisions'] ?? null) ? $draft['review_decisions'] : []),
            [
                'type' => 'duplicate_resolution',
                'action' => $action,
                'work_item_key' => (string) ($workItem['key'] ?? ''),
                'kept_work_item_key' => $keptKey,
                'removed_work_item_keys' => array_values(array_filter($removedKeys, static fn (string $key): bool => $key !== '')),
                'comments' => $comments,
            ],
        ];

        foreach ($draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] as $index => $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            if ($signature !== null && $this->duplicateSignature($candidate) === $signature) {
                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$index] = $this->clearDuplicateReviewFlags($candidate);
            }
        }

        return $draft;
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $workItem
     * @return array<string, mixed>
     */
    private function removeGenericWorkItem(
        array $draft,
        int $localIndex,
        int $sectionIndex,
        int $workIndex,
        array $workItem,
        ?string $comments
    ): array {
        if (!$this->noAirWorkItemPolicy->requiresReview($workItem)) {
            throw $this->validationException([
                'work_item_key' => [$this->message('estimate_generation.work_item_resolution_not_required')],
            ]);
        }

        $workItems = $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] ?? [];
        $workItems = is_array($workItems) ? array_values($workItems) : [];
        $removedKey = (string) ($workItem['key'] ?? '');
        array_splice($workItems, $workIndex, 1);

        $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] = array_values($workItems);
        $draft['review_decisions'] = [
            ...(is_array($draft['review_decisions'] ?? null) ? $draft['review_decisions'] : []),
            [
                'type' => 'work_item_resolution',
                'action' => 'remove_item',
                'work_item_key' => $removedKey,
                'removed_work_item_keys' => $removedKey !== '' ? [$removedKey] : [],
                'reason' => EstimateGenerationNoAirWorkItemPolicy::BLOCKER,
                'comments' => $comments,
            ],
        ];

        return $draft;
    }

    /**
     * @param array<int, mixed> $workItems
     * @return array<int, int>
     */
    private function matchingDuplicateIndexes(array $workItems, string $signature): array
    {
        $indexes = [];

        foreach ($workItems as $index => $workItem) {
            if (is_array($workItem) && $this->duplicateSignature($workItem) === $signature) {
                $indexes[] = (int) $index;
            }
        }

        return $indexes;
    }

    /**
     * @param array<int, mixed> $workItems
     */
    private function firstRemainingDuplicateKey(array $workItems, ?string $signature): ?string
    {
        if ($signature === null) {
            return null;
        }

        foreach ($workItems as $workItem) {
            if (is_array($workItem) && $this->duplicateSignature($workItem) === $signature) {
                $key = $this->nullableString($workItem['key'] ?? null);

                if ($key !== null) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function isDuplicateReviewItem(array $workItem): bool
    {
        $flags = [
            ...$this->arrayValues($workItem['validation_flags'] ?? []),
            ...$this->arrayValues($workItem['flags'] ?? []),
        ];

        return in_array('possible_duplicate_work_item', $flags, true)
            || in_array('requires_duplicate_review', $flags, true);
    }

    /**
     * @param array<string, mixed> $workItem
     * @return array<string, mixed>
     */
    private function markDuplicateKeptByUser(
        array $workItem,
        ?string $comments,
        array $removedKeys = [],
        string $status = 'kept_by_user'
    ): array {
        $workItem = $this->clearDuplicateReviewFlags($workItem);
        $workItem['metadata'] = [
            ...(is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : []),
            'duplicate_resolution' => [
                'status' => $status,
                'comments' => $comments,
                'removed_work_item_keys' => array_values(array_filter($removedKeys, static fn (string $key): bool => $key !== '')),
            ],
        ];

        return $workItem;
    }

    /**
     * @param array<string, mixed> $target
     * @param array<int, array<string, mixed>> $removedItems
     * @return array<string, mixed>
     */
    private function mergeDuplicateEvidence(array $target, array $removedItems): array
    {
        $sourceRefs = $this->arrayValues($target['source_refs'] ?? []);

        foreach ($removedItems as $removedItem) {
            $sourceRefs = [
                ...$sourceRefs,
                ...$this->arrayValues($removedItem['source_refs'] ?? []),
            ];
        }

        $sourceRefs = $this->uniqueArrayValues($sourceRefs);

        if ($sourceRefs !== []) {
            $target['source_refs'] = $sourceRefs;
        }

        $quantityBasis = $this->uniqueStrings([
            $target['quantity_basis'] ?? null,
            ...array_map(static fn (array $item): mixed => $item['quantity_basis'] ?? null, $removedItems),
        ]);

        if ($quantityBasis !== []) {
            $target['quantity_basis'] = implode('; ', $quantityBasis);
        }

        return $target;
    }

    /**
     * @param array<string, mixed> $workItem
     * @return array<string, mixed>
     */
    private function clearDuplicateReviewFlags(array $workItem): array
    {
        foreach (['validation_flags', 'flags'] as $field) {
            if (!array_key_exists($field, $workItem)) {
                continue;
            }

            $workItem[$field] = $this->withoutDuplicateReviewFlags($this->arrayValues($workItem[$field]));
        }

        return $workItem;
    }

    /**
     * @param array<int, mixed> $flags
     * @return array<int, string>
     */
    private function withoutDuplicateReviewFlags(array $flags): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $flag): string => trim((string) $flag), $flags),
            static fn (string $flag): bool => $flag !== ''
                && !in_array($flag, ['possible_duplicate_work_item', 'requires_duplicate_review'], true)
        ));
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
     * @param array<string, mixed> $workItem
     */
    private function duplicateSignature(array $workItem): ?string
    {
        $name = $this->normalizeSignaturePart((string) ($workItem['normative_search_text'] ?? $workItem['name'] ?? ''));
        $unit = $this->normalizeSignaturePart((string) ($workItem['unit'] ?? ''));
        $quantity = round((float) ($workItem['quantity'] ?? 0), 4);

        if ($name === '' || $unit === '' || $quantity <= 0) {
            return null;
        }

        $normativeIdentity = $this->normalizeSignaturePart((string) (
            $workItem['normative_rate_code']
            ?? $workItem['normative_search_key']
            ?? $workItem['quantity_formula']
            ?? ''
        ));

        return hash('sha256', implode('|', [
            $name,
            $unit,
            (string) $quantity,
            $normativeIdentity,
        ]));
    }

    private function normalizeSignaturePart(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    /**
     * @return array<int, mixed>
     */
    private function arrayValues(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
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

    /**
     * @param array<int, mixed> $values
     * @return array<int, mixed>
     */
    private function uniqueArrayValues(array $values): array
    {
        $seen = [];
        $result = [];

        foreach ($values as $value) {
            $key = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $value;

            if ($key === false || $key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $value;
        }

        return $result;
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

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
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
            || (int) data_get($draft, 'quality_summary.quantity_review_work_items', 0) > 0
            || (int) data_get($draft, 'quality_summary.not_calculated_work_items', 0) > 0
            || (int) data_get($draft, 'quality_summary.safe_norm_required_work_items', 0) > 0
            || (int) data_get($draft, 'quality_summary.duplicate_work_items', 0) > 0;
    }
}
