<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentProcessingOutcomeResolver
{
    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  list<array<string, mixed>>  $units
     */
    public function resolve(array $pages, array $units): DocumentProcessingOutcome
    {
        $unitsById = [];
        foreach ($units as $unit) {
            if (is_int($unit['id'] ?? null)) {
                $unitsById[$unit['id']] = $unit;
            }
        }

        $counts = [
            'included' => 0,
            'ready' => 0,
            'needs_user_action' => 0,
            'terminal_system_failed' => 0,
            'breaker_stopped' => 0,
            'system_failed' => 0,
            'processing' => 0,
            'excluded' => 0,
            'cancelled' => 0,
        ];
        $hasTerminalSystemFailure = false;
        $hasRecoverableSystemFailure = false;
        $hasPartialReview = false;
        foreach ($pages as $page) {
            $pageStatus = (string) ($page['status'] ?? 'queued');
            if ($pageStatus === ManageEstimateGenerationDocumentPages::STATUS_EXCLUDED) {
                $counts['excluded']++;

                continue;
            }
            $counts['included']++;
            $unit = $unitsById[(int) ($page['processing_unit_id'] ?? 0)] ?? [];
            $unitStatus = (string) ($unit['status'] ?? 'pending');
            $metadata = is_array($unit['metadata'] ?? null) ? $unit['metadata'] : [];
            $category = $metadata['failure_category'] ?? null;
            $qualityFlags = is_array($page['quality_flags'] ?? null) ? $page['quality_flags'] : [];
            $hasPartialReview = $hasPartialReview || in_array('ai_partial_result', $qualityFlags, true);

            if ($unitStatus === 'superseded'
                && ($metadata['processing_control_status'] ?? null) === 'cancelled') {
                $counts['cancelled']++;
            } elseif (in_array($pageStatus, ['queued', 'processing'], true)
                || in_array($unitStatus, ['pending', 'running'], true)) {
                $counts['processing']++;
            } elseif ($category === 'user_action_required') {
                $counts['needs_user_action']++;
            } elseif ($unitStatus === 'completed'
                && (int) ($unit['output_count'] ?? 0) > 0
                && in_array($pageStatus, ['ready', 'needs_review'], true)) {
                $counts['ready']++;
                $semanticPartial = in_array('ai_partial_result', $qualityFlags, true);
                if ($category === 'user_action_required' || ($pageStatus === 'needs_review' && ! $semanticPartial)) {
                    $counts['needs_user_action']++;
                }
            } else {
                $counts['system_failed']++;
                if (in_array($unit['failure_code'] ?? null, ['breaker_stopped', 'document_systemic_failure'], true)) {
                    $counts['breaker_stopped']++;
                } else {
                    $counts['terminal_system_failed']++;
                }
                if ($category === 'recoverable') {
                    $hasRecoverableSystemFailure = true;
                } else {
                    $hasTerminalSystemFailure = true;
                }
            }
        }

        [$type, $status] = match (true) {
            $counts['processing'] > 0 => ['processing', 'processing'],
            $counts['system_failed'] > 0 && $hasTerminalSystemFailure => ['system_failure', $counts['ready'] > 0 ? 'needs_review' : 'failed'],
            $counts['system_failed'] > 0 && $hasRecoverableSystemFailure => ['temporary_failure', $counts['ready'] > 0 ? 'needs_review' : 'failed'],
            $counts['cancelled'] > 0 => ['cancelled', 'needs_review'],
            $counts['needs_user_action'] > 0, $counts['included'] === 0 => ['user_action_required', 'needs_review'],
            default => ['ready', 'ready'],
        };

        return new DocumentProcessingOutcome(
            type: $type,
            documentStatus: $status,
            processedPages: $counts['ready'],
            counts: $counts,
            state: match (true) {
                $type === 'processing' => 'processing',
                $counts['ready'] > 0 && $counts['system_failed'] > 0 => 'partial',
                $type === 'cancelled' && $counts['ready'] > 0 => 'partial',
                $type === 'cancelled' => 'cancelled',
                $hasPartialReview => 'partial',
                in_array($type, ['system_failure', 'temporary_failure'], true) => 'system_failure',
                $counts['needs_user_action'] > 0 => 'partial',
                default => 'ready',
            },
            errorCode: match ($type) {
                'system_failure' => 'document_processing_system_failed',
                'temporary_failure' => 'document_processing_temporarily_unavailable',
                default => null,
            },
            errorMessageKey: match ($type) {
                'system_failure' => 'estimate_generation.document_processing_system_failed',
                'temporary_failure' => 'estimate_generation.document_processing_temporarily_unavailable',
                default => null,
            },
            retryAllowed: $type === 'temporary_failure',
        );
    }
}
