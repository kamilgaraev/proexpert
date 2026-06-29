<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Support\Collection;
use Throwable;

use function function_exists;
use function trans_message;

class EstimatorReadinessService
{
    /**
     * @return array<string, mixed>
     */
    public function evaluate(EstimateGenerationSession $session): array
    {
        $documents = $this->documents($session);
        $draft = is_array($session->draft_payload) ? $session->draft_payload : [];
        $quality = is_array($draft['quality_summary'] ?? null) ? $draft['quality_summary'] : [];
        $metrics = $this->metrics($session, $documents, $draft, $quality);
        $blockers = $this->blockers($session, $metrics, $quality);
        $warnings = $this->warnings($metrics);
        $status = $this->status($session, $metrics, $blockers);

        return [
            'status' => $status,
            'can_generate' => $this->canGenerate($metrics),
            'can_apply' => $this->canApply($status, $blockers),
            'next_action' => $this->nextAction($status),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'metrics' => $metrics,
        ];
    }

    /**
     * @return Collection<int, EstimateGenerationDocument>
     */
    private function documents(EstimateGenerationSession $session): Collection
    {
        if ($session->relationLoaded('documents')) {
            return $session->documents;
        }

        return collect();
    }

    /**
     * @param Collection<int, EstimateGenerationDocument> $documents
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $quality
     * @return array<string, int>
     */
    private function metrics(EstimateGenerationSession $session, Collection $documents, array $draft, array $quality): array
    {
        $totalWorkItems = (int) ($quality['total_work_items'] ?? 0);
        $operationWorkItems = (int) ($quality['operation_work_items'] ?? 0);
        $quantityReviewWorkItems = (int) ($quality['quantity_review_work_items'] ?? 0);
        $pricedTarget = max($totalWorkItems - $operationWorkItems - $quantityReviewWorkItems, 0);
        $normativeItems = is_array($quality['normative_items'] ?? null) ? $quality['normative_items'] : [];
        $zeroTotalCalculatedItems = $this->zeroTotalCalculatedPricedWorkItems($draft);
        $pricedWorkItems = max((int) ($quality['priced_work_items'] ?? 0) - $zeroTotalCalculatedItems, 0);
        $notCalculatedWorkItems = (int) ($quality['not_calculated_work_items'] ?? 0) + $zeroTotalCalculatedItems;

        return [
            'documents_total' => $documents->count(),
            'documents_ready' => $documents->where('status', 'ready')->count(),
            'documents_pending' => $documents
                ->filter(static fn (EstimateGenerationDocument $document): bool => in_array((string) $document->status, ['uploaded', 'queued', 'processing'], true))
                ->count(),
            'documents_action_required' => $documents
                ->filter(fn (EstimateGenerationDocument $document): bool => in_array((string) $document->status, ['failed', 'needs_review'], true)
                    || $this->requiresDocumentReview($document))
                ->count(),
            'facts' => $this->sumDocumentCount($documents, 'facts_count', 'facts'),
            'drawing_elements' => $this->sumDocumentCount($documents, 'drawing_elements_count', 'drawingElements'),
            'quantity_takeoffs' => $this->sumDocumentCount($documents, 'quantity_takeoffs_count', 'quantityTakeoffs'),
            'scope_inferences' => $this->sumDocumentCount($documents, 'scope_inferences_count', 'scopeInferences'),
            'priced_work_items' => $pricedWorkItems,
            'priced_work_items_total' => $pricedTarget,
            'operation_work_items' => $operationWorkItems,
            'quantity_review_work_items' => $quantityReviewWorkItems,
            'not_calculated_work_items' => $notCalculatedWorkItems,
            'zero_total_calculated_work_items' => $zeroTotalCalculatedItems,
            'safe_norm_required_work_items' => (int) ($quality['safe_norm_required_work_items'] ?? 0),
            'duplicate_work_items' => (int) ($quality['duplicate_work_items'] ?? 0),
            'normative_requires_review' => (int) ($normativeItems['requires_review'] ?? $quality['safe_norm_required_work_items'] ?? 0),
            'problem_flags' => count(is_array($draft['problem_flags'] ?? null) ? $draft['problem_flags'] : ($session->problem_flags ?? [])),
        ];
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function zeroTotalCalculatedPricedWorkItems(array $draft): int
    {
        $count = 0;

        foreach (($draft['local_estimates'] ?? []) as $localEstimate) {
            if (!is_array($localEstimate)) {
                continue;
            }

            foreach (($localEstimate['sections'] ?? []) as $section) {
                if (!is_array($section)) {
                    continue;
                }

                foreach (($section['work_items'] ?? []) as $workItem) {
                    if (!is_array($workItem)) {
                        continue;
                    }

                    if (
                        ($workItem['item_type'] ?? null) === 'priced_work'
                        && ($workItem['pricing_status'] ?? null) === 'calculated'
                        && (float) ($workItem['total_cost'] ?? 0) <= 0
                    ) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * @param Collection<int, EstimateGenerationDocument> $documents
     */
    private function sumDocumentCount(Collection $documents, string $attribute, string $relation): int
    {
        return $documents->sum(function (EstimateGenerationDocument $document) use ($attribute, $relation): int {
            if ($document->relationLoaded($relation)) {
                return $document->{$relation}->count();
            }

            $value = $document->getAttribute($attribute);

            return is_numeric($value) ? (int) $value : 0;
        });
    }

    /**
     * @param array<string, int> $metrics
     * @param array<string, mixed> $quality
     * @return array<int, array<string, string>>
     */
    private function blockers(EstimateGenerationSession $session, array $metrics, array $quality): array
    {
        $blockers = [];
        $hasDraft = is_array($session->draft_payload) && ($session->draft_payload['local_estimates'] ?? []) !== [];
        $qualityStatus = (string) ($quality['status'] ?? '');
        $qualityLevel = (string) ($quality['level'] ?? '');

        if ($metrics['documents_total'] === 0) {
            $blockers[] = $this->issue('no_documents', 'estimate_generation.readiness_blocker_no_documents');
        }

        if ($metrics['documents_pending'] > 0) {
            $blockers[] = $this->issue('documents_pending', 'estimate_generation.readiness_blocker_documents_pending');
        }

        if ($metrics['documents_action_required'] > 0) {
            $blockers[] = $this->issue('documents_require_review', 'estimate_generation.readiness_blocker_documents_require_review');
        }

        if ($hasDraft && $metrics['priced_work_items_total'] <= 0) {
            $blockers[] = $this->issue('no_priced_positions', 'estimate_generation.readiness_blocker_no_priced_positions');
        }

        if ($hasDraft && $metrics['normative_requires_review'] > 0) {
            $blockers[] = $this->issue('norms_require_review', 'estimate_generation.readiness_blocker_norms_require_review');
        }

        if ($hasDraft && $metrics['quantity_review_work_items'] > 0) {
            $blockers[] = $this->issue('quantities_require_review', 'estimate_generation.readiness_blocker_quantities_require_review');
        }

        if (
            $hasDraft
            && (
                $metrics['not_calculated_work_items'] > 0
                || $metrics['safe_norm_required_work_items'] > 0
            )
        ) {
            $blockers[] = $this->issue('prices_require_review', 'estimate_generation.readiness_blocker_prices_require_review');
        }

        if ($hasDraft && ($qualityStatus === 'review_required' || $metrics['duplicate_work_items'] > 0)) {
            $blockers[] = $this->issue('quality_requires_review', 'estimate_generation.readiness_next_review_draft');
        }

        if ($hasDraft && ($qualityStatus === 'critical' || $qualityLevel === 'blocked')) {
            $blockers[] = $this->issue('quality_blocked', 'estimate_generation.readiness_blocker_quality_blocked');
        }

        return $blockers;
    }

    /**
     * @param array<string, int> $metrics
     * @return array<int, array<string, string>>
     */
    private function warnings(array $metrics): array
    {
        $warnings = [];

        if ($metrics['documents_ready'] > 0 && $metrics['quantity_takeoffs'] === 0) {
            $warnings[] = $this->issue('no_quantity_takeoffs', 'estimate_generation.readiness_warning_no_quantity_takeoffs');
        }

        if ($metrics['documents_ready'] > 0 && $metrics['facts'] === 0 && $metrics['drawing_elements'] === 0) {
            $warnings[] = $this->issue('low_document_understanding', 'estimate_generation.readiness_warning_low_document_understanding');
        }

        return $warnings;
    }

    /**
     * @param array<string, int> $metrics
     * @param array<int, array<string, string>> $blockers
     */
    private function status(EstimateGenerationSession $session, array $metrics, array $blockers): string
    {
        if ((string) $session->status === 'applied') {
            return 'applied';
        }

        if ($metrics['documents_total'] === 0) {
            return 'needs_documents';
        }

        if ($metrics['documents_pending'] > 0) {
            return 'documents_processing';
        }

        if ($metrics['documents_action_required'] > 0) {
            return 'documents_need_review';
        }

        $hasDraft = is_array($session->draft_payload) && ($session->draft_payload['local_estimates'] ?? []) !== [];
        if (!$hasDraft) {
            return 'ready_for_generation';
        }

        if ($this->hasBlocker($blockers, ['quality_blocked', 'no_priced_positions'])) {
            return 'draft_blocked';
        }

        if ($this->hasBlocker($blockers, ['norms_require_review', 'quantities_require_review', 'prices_require_review', 'quality_requires_review'])) {
            return 'draft_needs_review';
        }

        return 'ready_to_apply';
    }

    /**
     * @param array<int, array<string, string>> $blockers
     * @param array<int, string> $codes
     */
    private function hasBlocker(array $blockers, array $codes): bool
    {
        foreach ($blockers as $blocker) {
            if (in_array($blocker['code'] ?? '', $codes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $metrics
     */
    private function canGenerate(array $metrics): bool
    {
        return $metrics['documents_ready'] > 0
            && $metrics['documents_pending'] === 0
            && $metrics['documents_action_required'] === 0;
    }

    private function requiresDocumentReview(EstimateGenerationDocument $document): bool
    {
        if ((string) $document->status === 'ignored') {
            return false;
        }

        $factsSummary = is_array($document->facts_summary) ? $document->facts_summary : [];
        $understanding = is_array($factsSummary['document_understanding'] ?? null)
            ? $factsSummary['document_understanding']
            : [];
        $capabilities = is_array($understanding['extracted_capabilities'] ?? null)
            ? $understanding['extracted_capabilities']
            : [];

        return ($understanding['role_for_estimation'] ?? null) === 'needs_review'
            || ($capabilities['requires_manual_review'] ?? false) === true;
    }

    /**
     * @param array<int, array<string, string>> $blockers
     */
    private function canApply(string $status, array $blockers): bool
    {
        return $status === 'ready_to_apply' && $blockers === [];
    }

    /**
     * @return array<string, string>
     */
    private function nextAction(string $status): array
    {
        return match ($status) {
            'needs_documents' => $this->issue('upload_documents', 'estimate_generation.readiness_next_upload_documents'),
            'documents_processing' => $this->issue('wait_documents', 'estimate_generation.readiness_next_wait_documents'),
            'documents_need_review' => $this->issue('review_documents', 'estimate_generation.readiness_next_review_documents'),
            'ready_for_generation' => $this->issue('generate_draft', 'estimate_generation.readiness_next_generate_draft'),
            'draft_blocked', 'draft_needs_review' => $this->issue('review_draft', 'estimate_generation.readiness_next_review_draft'),
            'ready_to_apply' => $this->issue('apply_draft', 'estimate_generation.readiness_next_apply_draft'),
            'applied' => $this->issue('open_estimate', 'estimate_generation.readiness_next_open_estimate'),
            default => $this->issue('review_session', 'estimate_generation.readiness_next_review_session'),
        };
    }

    /**
     * @return array<string, string>
     */
    private function issue(string $code, string $messageKey): array
    {
        $message = $messageKey;

        if (function_exists('trans_message')) {
            try {
                $message = trans_message($messageKey);
            } catch (Throwable) {
                $message = $messageKey;
            }
        }

        return [
            'code' => $code,
            'message_key' => $messageKey,
            'message' => $message,
        ];
    }
}
