<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\Services\LegalArchive\CanonicalJson;
use DomainException;

final readonly class SignerIdentitySet
{
    /** @var list<SignerIdentity> */
    public array $identities;

    /** @param list<SignerIdentity> $identities */
    public function __construct(array $identities)
    {
        if ($identities === [] || count($identities) > 50) {
            throw new DomainException('legal_signature_signers_required');
        }
        foreach ($identities as $identity) {
            if (! $identity instanceof SignerIdentity) {
                throw new DomainException('legal_signature_signer_identity_invalid');
            }
        }
        $canonical = array_map(static fn (SignerIdentity $identity): array => $identity->canonical(), $identities);
        usort($canonical, static fn (array $left, array $right): int => CanonicalJson::encode($left) <=> CanonicalJson::encode($right));
        $keys = array_map(static fn (array $identity): string => CanonicalJson::fingerprint($identity), $canonical);
        if (count(array_unique($keys)) !== count($keys)) {
            throw new DomainException('legal_signature_signer_identity_duplicate');
        }
        $this->identities = array_map(SignerIdentity::fromArray(...), $canonical);
    }

    public static function fromSnapshot(array $snapshot): self
    {
        if (! array_is_list($snapshot)) {
            throw new DomainException('legal_signature_signer_snapshot_invalid');
        }

        return new self(array_map(static fn (mixed $identity): SignerIdentity => is_array($identity)
            ? SignerIdentity::fromArray($identity)
            : throw new DomainException('legal_signature_signer_snapshot_invalid'), $snapshot));
    }

    public function snapshot(): array
    {
        return array_map(static fn (SignerIdentity $identity): array => $identity->canonical(), $this->identities);
    }

    public function hash(): string
    {
        return CanonicalJson::fingerprint($this->snapshot());
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->hash(), $other->hash());
    }

    public function primary(): SignerIdentity
    {
        return $this->identities[0];
    }
}
