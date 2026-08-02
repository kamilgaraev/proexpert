<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Services;

use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverChecklistFact;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverEvidenceFact;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverGateDefinition;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverReadinessMetric;
use InvalidArgumentException;

final readonly class HandoverReadinessFormula
{
    public function evaluate(
        HandoverGateDefinition $gate,
        iterable $checklistFacts,
        iterable $evidenceFacts,
    ): HandoverReadinessMetric {
        $checklists = $this->latestChecklistStatuses($checklistFacts);
        $evidence = $this->orderedEvidence($evidenceFacts);
        $approvedDocuments = [];
        $blockers = [];
        $attempts = [];
        $successfulResults = [];
        $scopeReady = true;

        foreach ($evidence as $fact) {
            if (in_array($fact->eventType, ['scope_reopened', 'scope_rejected'], true)) {
                $scopeReady = false;
            }
            if (in_array($fact->eventType, ['scope_accepted', 'scope_handed_over'], true)) {
                $scopeReady = true;
            }

            if ($fact->eventType === 'document_approved' && $fact->sourceCode !== null) {
                $approvedDocuments[$fact->sourceCode] = true;
            }
            if (
                in_array($fact->eventType, [
                    'document_approval_reversed',
                    'document_deleted',
                    'document_replaced',
                ], true)
                && $fact->sourceCode !== null
            ) {
                unset($approvedDocuments[$fact->sourceCode]);
            }

            if (in_array($fact->sourceType, $gate->hardBlockerSourceTypes, true)) {
                $key = $fact->sourceType.':'.$fact->sourceId;
                if (in_array($fact->eventType, [
                    'finding_opened',
                    'finding_reopened',
                    'blocker_opened',
                    'blocker_reopened',
                ], true)) {
                    $blockers[$key] = true;
                }
                if (in_array($fact->eventType, ['finding_resolved', 'blocker_resolved'], true)) {
                    unset($blockers[$key]);
                }
            }

            if ($fact->eventType === 'inspection_attempted') {
                $attempts[$fact->sourceType.':'.$fact->sourceId] = true;
            }
            if ($fact->eventType === 'inspection_resulted' && $fact->status === 'successful') {
                $key = $fact->sourceType.':'.$fact->sourceId;
                if (! isset($attempts[$key])) {
                    throw new InvalidArgumentException('handover_result_without_attempt');
                }
                $successfulResults[$key] = true;
            }
        }

        $mandatoryRequired = count($gate->requiredChecklistCodes);
        $mandatoryAccepted = 0;
        foreach ($gate->requiredChecklistCodes as $code) {
            if (($checklists[$code] ?? null) === 'accepted') {
                $mandatoryAccepted++;
            }
        }

        $documentRequired = count($gate->requiredDocumentCodes);
        $documentApproved = 0;
        foreach ($gate->requiredDocumentCodes as $code) {
            if (isset($approvedDocuments[$code])) {
                $documentApproved++;
            }
        }

        $mandatoryCompleteness = $this->ratio($mandatoryAccepted, $mandatoryRequired);
        $documentCompleteness = $this->ratio($documentApproved, $documentRequired);
        $ready = $mandatoryCompleteness === '1.00000000'
            && $documentCompleteness === '1.00000000'
            && $blockers === []
            && (count($attempts) === 0 || count($successfulResults) === count($attempts))
            && $scopeReady;

        return new HandoverReadinessMetric(
            $mandatoryCompleteness,
            $documentCompleteness,
            count($blockers),
            count($attempts),
            count($successfulResults),
            $ready,
        );
    }

    private function latestChecklistStatuses(iterable $facts): array
    {
        $statuses = [];
        foreach ($facts as $fact) {
            if (! $fact instanceof HandoverChecklistFact) {
                throw new InvalidArgumentException('handover_checklist_fact_invalid');
            }
            $statuses[$fact->code] = $fact->status;
        }

        return $statuses;
    }

    private function orderedEvidence(iterable $facts): array
    {
        $evidence = [];
        foreach ($facts as $fact) {
            if (! $fact instanceof HandoverEvidenceFact) {
                throw new InvalidArgumentException('handover_evidence_fact_invalid');
            }
            $evidence[] = $fact;
        }
        usort(
            $evidence,
            static fn (HandoverEvidenceFact $left, HandoverEvidenceFact $right): int => $left->occurredAt <=> $right->occurredAt
                ?: $left->sourceVersion <=> $right->sourceVersion
                ?: strcmp($left->eventType, $right->eventType),
        );

        return $evidence;
    }

    private function ratio(int $numerator, int $denominator): string
    {
        if ($denominator === 0) {
            return '1.00000000';
        }

        return bcdiv((string) $numerator, (string) $denominator, 8);
    }
}
