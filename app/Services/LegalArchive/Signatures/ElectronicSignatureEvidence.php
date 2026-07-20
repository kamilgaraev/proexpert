<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use DateTimeImmutable;
use DomainException;

final readonly class ElectronicSignatureEvidence
{
    public function __construct(
        public string $signatureKind,
        public string $containerFormat,
        public SignerIdentitySet $signers,
        public string $certificateFingerprint,
        public string $certificateSerial,
        public string $certificateIssuer,
        public DateTimeImmutable $certificateValidFrom,
        public DateTimeImmutable $certificateValidUntil,
        public bool $authorityConfirmed,
        public string $timeSource,
        public string $diagnosticCode,
        public DateTimeImmutable $signedAt,
        public DateTimeImmutable $verifiedAt,
        public ?string $partyRoleSnapshot = null,
        public ?string $signingSessionId = null,
        public ?string $clientIpHash = null,
        public ?string $userAgentHash = null,
    ) {
        if (! in_array($signatureKind, ['detached_cades', 'embedded_cades', 'xml_dsig'], true)
            || ! in_array($containerFormat, ['p7s', 'p7m', 'sig', 'xml'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $certificateFingerprint) !== 1
            || trim($certificateSerial) === '' || mb_strlen($certificateSerial) > 128
            || trim($certificateIssuer) === '' || mb_strlen($certificateIssuer) > 512
            || $certificateValidFrom >= $certificateValidUntil
            || $verifiedAt < $signedAt
            || $signedAt < $certificateValidFrom || $signedAt > $certificateValidUntil
            || ! in_array($timeSource, ['provider', 'trusted_timestamp', 'certificate', 'operator'], true)
            || trim($diagnosticCode) === '' || mb_strlen($diagnosticCode) > 128
            || ! self::bounded($partyRoleSnapshot, 64)
            || ! self::bounded($signingSessionId, 191)
            || ! self::hashOrNull($clientIpHash)
            || ! self::hashOrNull($userAgentHash)) {
            throw new DomainException('legal_signature_evidence_invalid');
        }
    }

    public function certificateMetadata(): array
    {
        return [
            'fingerprint' => $this->certificateFingerprint,
            'serial' => $this->certificateSerial,
            'issuer' => $this->certificateIssuer,
            'valid_from' => $this->certificateValidFrom->format(DATE_ATOM),
            'valid_until' => $this->certificateValidUntil->format(DATE_ATOM),
        ];
    }

    private static function bounded(?string $value, int $max): bool
    {
        return $value === null || (trim($value) !== '' && mb_strlen($value) <= $max);
    }

    private static function hashOrNull(?string $value): bool
    {
        return $value === null || preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
