<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Access;

final readonly class LegalDocumentAccessSubject
{
    private function __construct(
        public LegalDocumentAccessSubjectKind $kind,
        public int $organizationId,
        public ?int $userId = null,
        public ?string $roleSlug = null,
    ) {}

    public static function internalUser(int $organizationId, int $userId): self
    {
        return new self(LegalDocumentAccessSubjectKind::INTERNAL_USER, $organizationId, $userId);
    }

    public static function internalRole(int $organizationId, string $roleSlug): self
    {
        return new self(LegalDocumentAccessSubjectKind::INTERNAL_ROLE, $organizationId, roleSlug: trim($roleSlug));
    }

    public static function externalOrganization(int $organizationId): self
    {
        return new self(LegalDocumentAccessSubjectKind::EXTERNAL_ORGANIZATION, $organizationId);
    }

    public static function externalUser(int $organizationId, int $userId): self
    {
        return new self(LegalDocumentAccessSubjectKind::EXTERNAL_USER, $organizationId, $userId);
    }
}
