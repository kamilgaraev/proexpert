<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\DTOs\Contract\ContractDossierCreationInput;
use App\DTOs\Contract\ContractDossierCreationResult;
use App\Models\Contract;
use App\Models\ContractDossierSource;
use App\Models\User;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class ContractDossierCreationService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ContractSideMutationService $contracts,
        private readonly ContractAuditedMutationService $contractMutations,
        private readonly ContractDossierDocumentCreator $documents,
    ) {}

    public function create(
        int $organizationId,
        User $actor,
        ContractDossierCreationInput $input,
    ): ContractDossierCreationResult {
        if ($organizationId < 1 || (int) $actor->id < 1 || (int) $actor->current_organization_id !== $organizationId) {
            throw new DomainException('contract_dossier_creation_context_invalid');
        }

        try {
            return $this->connection->transaction(function () use ($organizationId, $actor, $input): ContractDossierCreationResult {
                $key = $input->normalizedIdempotencyKey();
                if ($input->hasSourceIdentity()) {
                    $source = ContractDossierSource::query()
                        ->where('organization_id', $organizationId)
                        ->where('source_type', $input->sourceType)
                        ->where('source_id', $input->sourceId)
                        ->lockForUpdate()
                        ->first();
                    if ($source instanceof ContractDossierSource) {
                        $contract = $source->contract()->firstOrFail();

                        return new ContractDossierCreationResult(
                            $contract,
                            $this->documentForReplay($contract, $organizationId),
                            true,
                        );
                    }
                }
                $contract = Contract::query()
                    ->where('organization_id', $organizationId)
                    ->where('dossier_creation_key', $key)
                    ->lockForUpdate()
                    ->first();
                if ($contract instanceof Contract) {
                    $document = $this->documentForReplay($contract, $organizationId);

                    return new ContractDossierCreationResult($contract, $document, true);
                }

                $contract = $this->contracts->create(
                    $organizationId,
                    $input->contract,
                    actorId: (int) $actor->id,
                    dossierCreationKey: $key,
                );
                $documentData = [
                    'primary_project_id' => $contract->project_id,
                    'title' => $input->documentTitle,
                    'document_number' => $contract->number,
                    'type_profile_code' => $input->profileCode,
                    'document_date' => $contract->date?->toDateString(),
                    'effective_from' => $contract->start_date?->toDateString(),
                    'effective_until' => $contract->end_date?->toDateString(),
                    'counterparty_name' => $contract->contractor?->name ?? $contract->supplier?->name,
                    'source_type' => 'contract',
                    'source_id' => (string) $contract->id,
                    'source_idempotency_key' => $key,
                    'links' => array_merge([[
                        'link_type' => 'contract',
                        'linked_type' => 'contract',
                        'linked_id' => (string) $contract->id,
                        'display_name' => $contract->number,
                    ]], $input->sourceLinks),
                    'metadata' => $this->documentMetadata($contract, $input),
                ];
                if ($input->confidentialityLevel !== null) {
                    $documentData['confidentiality_level'] = $input->confidentialityLevel;
                }
                $document = $this->documents->create($organizationId, (int) $actor->id, $documentData);
                $this->contractMutations->update(
                    $contract,
                    ['legal_archive_document_id' => (int) $document->id],
                    'legal_dossier_linked',
                    (int) $actor->id,
                );
                if ($input->hasSourceIdentity()) {
                    ContractDossierSource::query()->create([
                        'organization_id' => $organizationId,
                        'contract_id' => $contract->id,
                        'source_type' => $input->sourceType,
                        'source_id' => $input->sourceId,
                        'idempotency_key' => $key,
                    ]);
                }

                return new ContractDossierCreationResult($contract->refresh(), $document, false);
            });
        } catch (QueryException $exception) {
            if (! $this->isCreationKeyConflict($exception) && ! $this->isSourceConflict($exception)) {
                throw $exception;
            }
            if ($input->hasSourceIdentity()) {
                $source = ContractDossierSource::query()
                    ->where('organization_id', $organizationId)
                    ->where('source_type', $input->sourceType)
                    ->where('source_id', $input->sourceId)
                    ->first();
                if ($source instanceof ContractDossierSource) {
                    $contract = $source->contract()->firstOrFail();

                    return new ContractDossierCreationResult(
                        $contract,
                        $this->documentForReplay($contract, $organizationId),
                        true,
                    );
                }
            }
            $contract = Contract::query()
                ->where('organization_id', $organizationId)
                ->where('dossier_creation_key', $input->normalizedIdempotencyKey())
                ->first();
            if (! $contract instanceof Contract) {
                throw $exception;
            }

            return new ContractDossierCreationResult(
                $contract,
                $this->documentForReplay($contract, $organizationId),
                true,
            );
        }
    }

    private function documentForReplay(Contract $contract, int $organizationId): LegalArchiveDocument
    {
        $document = $contract->legalArchiveDocument;
        if (! $document instanceof LegalArchiveDocument || (int) $document->organization_id !== $organizationId) {
            throw new DomainException('contract_dossier_creation_incomplete');
        }

        return $document;
    }

    /** @return array<string, mixed> */
    private function documentMetadata(Contract $contract, ContractDossierCreationInput $input): array
    {
        if ($input->profileCode !== 'contract.supply' || $input->sourceType !== 'purchase_order') {
            return $input->documentMetadata;
        }

        $subject = $this->firstFilledString($contract->subject);
        $buyer = $this->firstFilledString($contract->organization?->legal_name, $contract->organization?->name);
        $supplier = $this->firstFilledString($contract->supplier?->name, $contract->contractor?->name);
        $deliveryTerms = $this->firstFilledString($input->documentMetadata['delivery_terms'] ?? null);
        $price = $contract->total_amount ?? $contract->base_amount;

        if (
            $subject === null
            || $buyer === null
            || $supplier === null
            || $deliveryTerms === null
            || ! is_numeric($price)
        ) {
            throw ValidationException::withMessages([
                'metadata' => [trans_message('legal_archive.messages.contract_dossier_supply_data_incomplete')],
            ]);
        }

        return [
            ...$input->documentMetadata,
            'subject' => $subject,
            'buyer' => $buyer,
            'supplier' => $supplier,
            'price' => (float) $price,
            'delivery_terms' => $deliveryTerms,
        ];
    }

    private function firstFilledString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function isCreationKeyConflict(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'contracts_dossier_creation_key_unique');
    }

    private function isSourceConflict(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'contract_dossier_sources_source_unique')
            || str_contains($exception->getMessage(), 'contract_dossier_sources_key_unique');
    }
}
