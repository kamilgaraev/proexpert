<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use DomainException;

final readonly class SignerIdentity
{
    private const KINDS = ['user', 'organization', 'party', 'role', 'manual'];

    public function __construct(
        public string $kind,
        public string $name,
        public ?int $userId = null,
        public ?int $organizationId = null,
        public ?int $partyId = null,
        public ?string $roleSlug = null,
        public ?string $taxNumber = null,
        public ?string $position = null,
        public ?string $partyRole = null,
    ) {
        if (! in_array($kind, self::KINDS, true) || trim($name) === '' || mb_strlen($name) > 255) {
            throw new DomainException('legal_signature_signer_identity_invalid');
        }
        $valid = match ($kind) {
            'user' => $userId !== null && $userId > 0 && $organizationId !== null && $organizationId > 0
                && $partyId === null && $roleSlug === null,
            'organization' => $organizationId !== null && $organizationId > 0 && $userId === null
                && $partyId === null && $roleSlug === null && self::bounded($taxNumber, 32, true),
            'party' => $partyId !== null && $partyId > 0 && $userId === null && $roleSlug === null,
            'role' => $organizationId !== null && $organizationId > 0 && self::bounded($roleSlug, 191, true)
                && $userId === null && $partyId === null,
            'manual' => $userId === null && $organizationId === null && $partyId === null && $roleSlug === null,
        };
        if (! $valid || ! self::bounded($taxNumber, 32) || ! self::bounded($position, 255) || ! self::bounded($partyRole, 64)) {
            throw new DomainException('legal_signature_signer_identity_invalid');
        }
    }

    public function canonical(): array
    {
        return [
            'kind' => $this->kind,
            'name' => trim($this->name),
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'party_id' => $this->partyId,
            'role_slug' => $this->roleSlug === null ? null : trim($this->roleSlug),
            'tax_number' => $this->taxNumber === null ? null : trim($this->taxNumber),
            'position' => $this->position === null ? null : trim($this->position),
            'party_role' => $this->partyRole === null ? null : trim($this->partyRole),
        ];
    }

    public static function fromArray(array $value): self
    {
        return new self(
            (string) ($value['kind'] ?? ''),
            (string) ($value['name'] ?? ''),
            self::nullablePositiveInt($value['user_id'] ?? null),
            self::nullablePositiveInt($value['organization_id'] ?? null),
            self::nullablePositiveInt($value['party_id'] ?? null),
            self::nullableString($value['role_slug'] ?? null),
            self::nullableString($value['tax_number'] ?? null),
            self::nullableString($value['position'] ?? null),
            self::nullableString($value['party_role'] ?? null),
        );
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function bounded(?string $value, int $max, bool $required = false): bool
    {
        if ($value === null) {
            return ! $required;
        }
        $value = trim($value);

        return ($value !== '' || ! $required) && mb_strlen($value) <= $max;
    }
}
