<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use InvalidArgumentException;

final readonly class ArbitrationIntentIngestor
{
    private const SAFETY_OVERRIDE_KEYS = [
        'role', 'system_prompt', 'instructions', 'prompt_contract',
    ];

    /**
     * @param  list<mixed>  $intents
     * @param  list<ObservationClaim>  $claims
     */
    public function ingest(array $intents, array $claims, VisionDocumentInput $source): ArbitrationIntentIngestionResult
    {
        if (! array_is_list($intents) || count($intents) > 64) {
            throw new VisionContractException('arbitration_transport_unbounded');
        }

        $accepted = [];
        $quarantined = [];
        foreach ($intents as $index => $intent) {
            if (! is_array($intent) || array_is_list($intent)) {
                $quarantined[] = ['index' => $index, 'reason' => 'arbitration_intent_shape_invalid'];

                continue;
            }
            if (array_intersect(array_keys($intent), self::SAFETY_OVERRIDE_KEYS) !== []) {
                throw new VisionContractException('arbitration_scope_override_attempted');
            }
            try {
                $accepted[] = $this->project($intent, $claims, $source);
            } catch (InvalidArgumentException $exception) {
                $quarantined[] = ['index' => $index, 'reason' => $exception->getMessage()];
            }
        }

        return new ArbitrationIntentIngestionResult($accepted, $quarantined);
    }

    /** @param array<string,mixed> $intent @param list<ObservationClaim> $claims */
    private function project(array $intent, array $claims, VisionDocumentInput $source): ArbitrationDecision
    {
        $claimsById = [];
        $allowedEvidence = [];
        foreach ($claims as $claim) {
            $claimsById[$claim->id] = $claim;
            if ($claim->evidenceRef !== null) {
                $allowedEvidence[$claim->evidenceRef] = true;
            }
        }

        $claimId = $intent['claim_ref'] ?? $intent['claim_id'] ?? null;
        $status = $intent['status'] ?? null;
        $support = $intent['supporting_claim_refs'] ?? $intent['supporting_claim_ids'] ?? null;
        $evidence = $intent['evidence_refs'] ?? null;
        $reason = $intent['reason'] ?? $intent['reason_code'] ?? null;
        if (! is_string($claimId) || ! isset($claimsById[$claimId])
            || ! in_array($status, ['accepted', 'candidate', 'unresolved'], true)
            || ! is_array($support) || ! array_is_list($support) || $support === [] || count($support) > 3
            || ! is_array($evidence) || ! array_is_list($evidence) || count($evidence) > 8
            || ! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 1000
            || count($support) !== count(array_unique($support))
            || count($evidence) !== count(array_unique($evidence))) {
            throw new InvalidArgumentException('arbitration_intent_invalid');
        }

        foreach ($support as $id) {
            if (! is_string($id) || ! isset($claimsById[$id])) {
                throw new InvalidArgumentException('arbitration_claim_not_allowlisted');
            }
        }
        foreach ($evidence as $ref) {
            if (! is_string($ref) || ! isset($allowedEvidence[$ref])) {
                throw new InvalidArgumentException('arbitration_evidence_not_allowlisted');
            }
        }

        $supportingEvidence = [];
        $hasExplicitEvidence = false;
        foreach ($support as $id) {
            $supportingClaim = $claimsById[$id];
            if ($supportingClaim->evidenceRef !== null) {
                $supportingEvidence[$supportingClaim->evidenceRef] = true;
            }
            $hasExplicitEvidence = $hasExplicitEvidence
                || ($supportingClaim->explicitEvidence && in_array($supportingClaim->evidenceRef, $evidence, true));
        }
        if (array_diff($evidence, array_keys($supportingEvidence)) !== []) {
            throw new InvalidArgumentException('arbitration_evidence_not_supported');
        }
        if ($status === 'accepted' && ($evidence === [] || ! $hasExplicitEvidence)) {
            throw new InvalidArgumentException('arbitration_confirmation_without_explicit_evidence');
        }

        $claim = $claimsById[$claimId];
        $canonicalClaim = [
            'entity_key' => $claim->entityKey,
            'fact_type' => $claim->factType,
            'value' => $claim->value,
            'unit' => $claim->unit,
            'source_claim_id' => $claim->id,
        ];
        $question = $status === 'unresolved'
            ? $this->question($intent['question'] ?? null, $claimId, $evidence, $source)
            : null;
        if ($status !== 'unresolved' && ($intent['question'] ?? null) !== null) {
            throw new InvalidArgumentException('arbitration_question_status_invalid');
        }

        return new ArbitrationDecision(
            $claimId,
            $status,
            array_values($support),
            array_values($evidence),
            'arbiter_reason_'.substr(hash('sha256', $claimId.'|'.trim($reason)), 0, 16),
            $canonicalClaim,
            $question,
            trim($reason),
        );
    }

    /** @param list<string> $evidence */
    private function question(mixed $question, string $claimId, array $evidence, VisionDocumentInput $source): array
    {
        if (! is_array($question) || array_is_list($question)) {
            throw new InvalidArgumentException('arbitration_question_invalid');
        }
        $texts = [];
        foreach (['subject', 'reason', 'impact', 'recommendation'] as $field) {
            $value = $question[$field] ?? null;
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 1000) {
                throw new InvalidArgumentException('arbitration_question_invalid');
            }
            $texts[$field] = trim($value);
        }
        $choices = $question['choices'] ?? [];
        if (! is_array($choices) || ! array_is_list($choices) || count($choices) > 8) {
            throw new InvalidArgumentException('arbitration_question_invalid');
        }
        foreach ($choices as $choice) {
            if (! is_string($choice) || trim($choice) === '' || mb_strlen($choice) > 200) {
                throw new InvalidArgumentException('arbitration_question_invalid');
            }
        }

        return [
            'code' => 'arbiter_question_'.substr(hash('sha256', $claimId.'|'.$texts['subject']), 0, 16),
            ...$texts,
            'choices' => array_values($choices),
            'source_locator' => [
                'page_id' => $source->pageId,
                'page_number' => $source->pageNumber,
                'processing_unit_id' => $source->processingUnitId,
                'source_version' => $source->sourceVersion,
                'coordinate_space' => 'normalized_derivative_v1',
                'evidence_refs' => array_values($evidence),
            ],
        ];
    }
}
