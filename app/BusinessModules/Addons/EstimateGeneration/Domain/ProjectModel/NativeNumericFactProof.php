<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

/** Rechecks the native publisher's immutable claim-to-evidence binding, including the actual node fingerprint. */
final class NativeNumericFactProof
{
    public function matches(Fact $fact, Evidence $evidence): bool
    {
        $proof = $fact->nativeNumericProofs[$evidence->id] ?? null;
        if (! is_array($proof) || $evidence->sourceType !== 'document' || $evidence->producerName !== 'native_numeric_parser'
            || $evidence->fingerprint === null || ! in_array($evidence->id, $fact->evidenceIds, true)
            || [$fact->organizationId, $fact->projectId, $fact->sessionId, $fact->sourceVersion]
                !== [$evidence->organizationId, $evidence->projectId, $evidence->sessionId, $evidence->sourceVersion]
            || ($proof['entity_id'] ?? null) !== $fact->entityId || ($proof['source_version'] ?? null) !== $fact->sourceVersion
            || ($proof['parameter'] ?? null) !== FactVocabulary::parameter($fact->type)
            || ($proof['evidence_fingerprint'] ?? null) !== $evidence->fingerprint
            || (! is_string($fact->value) && ! is_int($fact->value))) {
            return false;
        }
        try {
            return ($proof['measurement'] ?? null) === FactVocabulary::measurementSignature((string) $fact->value, $fact->unit);
        } catch (\Throwable) {
            return false;
        }
    }
}
