<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

use InvalidArgumentException;

final readonly class ArbitrationDecision
{
    /** @param list<string> $supportingClaimIds @param list<string> $evidenceRefs @param array<string,mixed>|null $question */
    public function __construct(
        public string $claimId,
        public string $status,
        public array $supportingClaimIds,
        public array $evidenceRefs,
        public string $reasonCode,
        public ?array $question,
    ) {}

    /** @param array<string,mixed> $intent @param list<ObservationClaim> $claims */
    public static function fromProviderIntent(array $intent, array $claims): self
    {
        if (array_diff(array_keys($intent), ['claim_id', 'status', 'supporting_claim_ids', 'evidence_refs', 'reason_code', 'question']) !== []) {
            throw new InvalidArgumentException('arbitration_intent_shape_invalid');
        }
        $claimsById = [];
        $allowedEvidence = [];
        foreach ($claims as $claim) {
            $claimsById[$claim->id] = $claim;
            if ($claim->evidenceRef !== null) {
                $allowedEvidence[$claim->evidenceRef] = true;
            }
        }
        $claimId = $intent['claim_id'] ?? null;
        $status = $intent['status'] ?? null;
        $support = $intent['supporting_claim_ids'] ?? null;
        $evidence = $intent['evidence_refs'] ?? null;
        $reason = $intent['reason_code'] ?? null;
        $question = $intent['question'] ?? null;
        if (! is_string($claimId) || ! isset($claimsById[$claimId])
            || ! in_array($status, ['accepted', 'candidate', 'unresolved'], true)
            || ! is_array($support) || ! array_is_list($support) || $support === [] || count($support) > 3
            || ! is_array($evidence) || ! array_is_list($evidence) || count($evidence) > 8
            || ! is_string($reason) || preg_match('/^[a-z0-9][a-z0-9_]{0,79}$/D', $reason) !== 1
            || count($support) !== count(array_unique($support)) || count($evidence) !== count(array_unique($evidence))) {
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
        if ($status === 'unresolved') {
            self::assertQuestion($question);
            $locatorEvidence = $question['source_locator']['evidence_refs'] ?? null;
            $pageNumber = $question['source_locator']['page_number'] ?? null;
            if (! is_array($locatorEvidence) || ! array_is_list($locatorEvidence) || $locatorEvidence === []
                || array_diff($locatorEvidence, $evidence) !== [] || ! is_int($pageNumber)
                || $pageNumber < 1 || $pageNumber > 10_000) {
                throw new InvalidArgumentException('arbitration_question_locator_invalid');
            }
        } elseif ($question !== null) {
            throw new InvalidArgumentException('arbitration_question_status_invalid');
        }

        return new self($claimId, $status, $support, $evidence, $reason, $question);
    }

    private static function assertQuestion(mixed $question): void
    {
        $keys = ['code', 'subject', 'reason', 'impact', 'recommendation', 'choices', 'source_locator'];
        if (! is_array($question) || array_keys($question) !== $keys
            || ! is_string($question['code']) || preg_match('/^[a-z0-9][a-z0-9_]{0,79}$/D', $question['code']) !== 1
            || ! is_array($question['choices']) || ! array_is_list($question['choices'])
            || count($question['choices']) < 2 || count($question['choices']) > 8
            || ! is_array($question['source_locator']) || array_is_list($question['source_locator'])) {
            throw new InvalidArgumentException('arbitration_question_invalid');
        }
        foreach (['subject', 'reason', 'impact', 'recommendation'] as $field) {
            if (! is_string($question[$field]) || trim($question[$field]) === '' || mb_strlen($question[$field]) > 500) {
                throw new InvalidArgumentException('arbitration_question_invalid');
            }
        }
        foreach ($question['choices'] as $choice) {
            if (! is_string($choice) || trim($choice) === '' || mb_strlen($choice) > 120) {
                throw new InvalidArgumentException('arbitration_question_invalid');
            }
        }
    }
}
