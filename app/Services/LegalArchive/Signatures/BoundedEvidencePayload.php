<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use DomainException;

final class BoundedEvidencePayload
{
    public static function assert(array $payload): void
    {
        $nodes = 0;
        self::walk($payload, 0, $nodes);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > 65_536) {
            throw new DomainException('legal_signature_evidence_payload_invalid');
        }
    }

    private static function walk(array $payload, int $depth, int &$nodes): void
    {
        if ($depth > 5 || count($payload) > 128) {
            throw new DomainException('legal_signature_evidence_payload_invalid');
        }
        foreach ($payload as $key => $value) {
            $nodes++;
            if ($nodes > 512 || (is_string($key) && (trim($key) === '' || mb_strlen($key) > 128))) {
                throw new DomainException('legal_signature_evidence_payload_invalid');
            }
            if (is_array($value)) {
                self::walk($value, $depth + 1, $nodes);
            } elseif (is_string($value) && mb_strlen($value) > 4096) {
                throw new DomainException('legal_signature_evidence_payload_invalid');
            } elseif (! is_scalar($value) && $value !== null) {
                throw new DomainException('legal_signature_evidence_payload_invalid');
            }
        }
    }
}
