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
        ];
        $hasTerminalSystemFailure = false;
        $hasRecoverableSystemFailure = false;
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

            if (in_array($pageStatus, ['queued', 'processing'], true)
                || in_array($unitStatus, ['pending', 'running'], true)) {
                $counts['processing']++;
            } elseif ($category === 'user_action_required' || $pageStatus === 'needs_review') {
                $counts['needs_user_action']++;
            } elseif ($pageStatus === 'ready' && $unitStatus === 'completed' && (int) ($unit['output_count'] ?? 0) > 0) {
                $counts['ready']++;
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
            $counts['system_failed'] > 0 && $hasTerminalSystemFailure => ['system_failure', 'failed'],
            $counts['system_failed'] > 0 && $hasRecoverableSystemFailure => ['temporary_failure', 'failed'],
            $counts['needs_user_action'] > 0, $counts['included'] === 0 => ['user_action_required', 'needs_review'],
            default => ['ready', 'ready'],
        };

        return new DocumentProcessingOutcome(
            type: $type,
            documentStatus: $status,
            processedPages: $counts['ready'],
            counts: $counts,
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
