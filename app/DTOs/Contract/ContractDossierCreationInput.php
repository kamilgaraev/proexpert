<?php

declare(strict_types=1);

namespace App\DTOs\Contract;

use InvalidArgumentException;

final readonly class ContractDossierCreationInput
{
    /**
     * @param array<string, mixed> $documentMetadata
     */
    public function __construct(
        public ContractDTO $contract,
        public string $idempotencyKey,
        public string $documentTitle,
        public string $profileCode = 'contract.work',
        public array $documentMetadata = [],
        public ?string $confidentialityLevel = null,
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
    }

    public function normalizedIdempotencyKey(): string
    {
        return trim($this->idempotencyKey);
    }
}
