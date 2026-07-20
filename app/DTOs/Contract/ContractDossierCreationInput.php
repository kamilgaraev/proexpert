<?php

declare(strict_types=1);

namespace App\DTOs\Contract;

use InvalidArgumentException;

final readonly class ContractDossierCreationInput
{
    /**
     * @param array<string, mixed> $documentMetadata
     * @param list<array{link_type: string, linked_type: string, linked_id: string, display_name?: string}> $sourceLinks
     */
    public function __construct(
        public ContractDTO $contract,
        public string $idempotencyKey,
        public string $documentTitle,
        public string $profileCode = 'contract.work',
        public array $documentMetadata = [],
        public ?string $confidentialityLevel = null,
        public array $sourceLinks = [],
        public ?string $sourceType = null,
        public ?string $sourceId = null,
    ) {
        if (trim($idempotencyKey) === '' || mb_strlen(trim($idempotencyKey)) > 191) {
            throw new InvalidArgumentException('contract_dossier_idempotency_key_invalid');
        }
        if (trim($documentTitle) === '' || mb_strlen(trim($documentTitle)) > 512) {
            throw new InvalidArgumentException('contract_dossier_document_title_invalid');
        }
        if (trim($profileCode) === '') {
            throw new InvalidArgumentException('contract_dossier_profile_invalid');
        }
        foreach ($sourceLinks as $link) {
            if (! is_array($link)
                || trim((string) ($link['link_type'] ?? '')) === ''
                || trim((string) ($link['linked_type'] ?? '')) === ''
                || trim((string) ($link['linked_id'] ?? '')) === '') {
                throw new InvalidArgumentException('contract_dossier_source_link_invalid');
            }
        }
        if (($sourceType === null) !== ($sourceId === null)
            || ($sourceType !== null && (trim($sourceType) === '' || trim($sourceId ?? '') === ''))) {
            throw new InvalidArgumentException('contract_dossier_source_identity_invalid');
        }
    }

    public function normalizedIdempotencyKey(): string
    {
        return trim($this->idempotencyKey);
    }

    public function hasSourceIdentity(): bool
    {
        return $this->sourceType !== null && $this->sourceId !== null;
    }
}
