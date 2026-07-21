<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentLink;
use App\Models\Contract;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileRegistry;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileValidator;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

use function trans_message;

final class ContractLegalDossierService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ContractAuditedMutationService $contractMutations,
        private readonly ContractDossierDocumentCreator $documents,
        private readonly LegalDocumentAuthorizer $authorizer,
        private readonly LegalDocumentProfileRegistry $profiles,
        private readonly LegalDocumentProfileValidator $profileValidator,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(
        User $actor,
        int $organizationId,
        int $projectId,
        int $contractId,
        array $data,
    ): ContractLegalDossierMutationResult {
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw new DomainException('contract_legal_dossier_idempotency_invalid');
        }

        try {
            return $this->connection->transaction(function () use (
                $actor,
                $organizationId,
                $projectId,
                $contractId,
                $data,
                $idempotencyKey,
            ): ContractLegalDossierMutationResult {
                $contract = $this->lockedContract($organizationId, $projectId, $contractId);
                $bound = $this->boundDocument($contract, $organizationId);
                if ($bound instanceof LegalArchiveDocument) {
                    if ($this->isExactCreateReplay($bound, $contract, $idempotencyKey)) {
                        return new ContractLegalDossierMutationResult($contract, $bound, 'replayed');
                    }

                    throw new DomainException('contract_legal_dossier_already_bound');
                }

                $profileCode = $this->documentProfileCode($contract);
                $metadata = $this->profileValidator->validate(
                    $this->profiles->find($organizationId, $profileCode),
                    $this->documentMetadata($contract, $profileCode, $data),
                );

                $document = $this->documents->create($organizationId, (int) $actor->id, [
                    'primary_project_id' => $projectId,
                    'title' => (string) $data['title'],
                    'document_number' => $data['document_number'] ?? $contract->number,
                    'type_profile_code' => $profileCode,
                    'document_date' => $data['document_date'] ?? $contract->date?->toDateString(),
                    'description' => $data['description'] ?? null,
                    'metadata' => $metadata,
                    'source_type' => 'contract',
                    'source_id' => (string) $contract->id,
                    'source_idempotency_key' => $idempotencyKey,
                    'links' => [[
                        'link_type' => 'contract',
                        'linked_type' => 'contract',
                        'linked_id' => (string) $contract->id,
                        'display_name' => (string) $contract->number,
                    ]],
                ]);
                $this->assertAttachable($document, $contract, $organizationId, $projectId);
                $this->createContractLink($document, $contract);
                $updated = $this->contractMutations->update(
                    $contract,
                    ['legal_archive_document_id' => (int) $document->id],
                    'legal_dossier_linked',
                    (int) $actor->id,
                );

                return new ContractLegalDossierMutationResult($updated, $document, 'created');
            });
        } catch (QueryException $exception) {
            return $this->resolveConcurrentBinding($organizationId, $projectId, $contractId, null, $idempotencyKey, $exception);
        }
    }

    public function attach(
        User $actor,
        int $organizationId,
        int $projectId,
        int $contractId,
        int $documentId,
    ): ContractLegalDossierMutationResult {
        try {
            return $this->connection->transaction(function () use (
                $actor,
                $organizationId,
                $projectId,
                $contractId,
                $documentId,
            ): ContractLegalDossierMutationResult {
                $contract = $this->lockedContract($organizationId, $projectId, $contractId);
                $document = LegalArchiveDocument::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($documentId)
                    ->lockForUpdate()
                    ->first();
                if (! $document instanceof LegalArchiveDocument) {
                    throw new DomainException('contract_legal_dossier_document_unavailable');
                }
                $this->authorizer->authorizePermission($actor, $document, 'legal_archive.update');

                $bound = $this->boundDocument($contract, $organizationId);
                if ($bound instanceof LegalArchiveDocument) {
                    if ((int) $bound->id === (int) $document->id) {
                        return new ContractLegalDossierMutationResult($contract, $bound, 'replayed');
                    }

                    throw new DomainException('contract_legal_dossier_already_bound');
                }

                $this->assertAttachable($document, $contract, $organizationId, $projectId);
                $this->createContractLink($document, $contract);
                $updated = $this->contractMutations->update(
                    $contract,
                    ['legal_archive_document_id' => (int) $document->id],
                    'legal_dossier_linked',
                    (int) $actor->id,
                );

                return new ContractLegalDossierMutationResult($updated, $document, 'attached');
            });
        } catch (QueryException $exception) {
            return $this->resolveConcurrentBinding($organizationId, $projectId, $contractId, $documentId, null, $exception);
        }
    }

    /** @param array{q?: string, page?: int, per_page?: int} $filters */
    public function candidates(
        User $actor,
        int $organizationId,
        int $projectId,
        int $contractId,
        array $filters,
    ): LengthAwarePaginator {
        $contract = $this->contractForCandidates($organizationId, $projectId, $contractId);
        $profileCodes = $this->profiles->standardCodesForCategory('contract');

        $query = LegalArchiveDocument::query()
            ->where('legal_archive_documents.organization_id', $organizationId)
            ->where('legal_archive_documents.primary_project_id', $projectId)
            ->where('legal_archive_documents.document_type', 'contract')
            ->whereNotNull('legal_archive_documents.type_profile_code')
            ->where(function (Builder $profiles) use ($organizationId, $profileCodes): void {
                $profiles->whereIn('legal_archive_documents.type_profile_code', $profileCodes)
                    ->orWhereExists(function ($custom) use ($organizationId, $profileCodes): void {
                        $custom->selectRaw('1')
                            ->from('legal_archive_document_type_profiles as type_profiles')
                            ->whereColumn('type_profiles.code', 'legal_archive_documents.type_profile_code')
                            ->where('type_profiles.organization_id', $organizationId)
                            ->where('type_profiles.is_active', true)
                            ->whereIn('type_profiles.base_code', $profileCodes);
                    });
            })
            ->where(function (Builder $source) use ($contract): void {
                $source->where(static function (Builder $blank): void {
                    $blank->whereRaw("TRIM(COALESCE(legal_archive_documents.source_type, '')) = ''")
                        ->whereRaw("TRIM(COALESCE(legal_archive_documents.source_id, '')) = ''")
                        ->whereRaw("TRIM(COALESCE(legal_archive_documents.source_idempotency_key, '')) = ''");
                })->orWhere(static fn (Builder $exact) => $exact
                    ->whereRaw("TRIM(COALESCE(legal_archive_documents.source_type, '')) = ?", ['contract'])
                    ->whereRaw("TRIM(COALESCE(legal_archive_documents.source_id, '')) = ?", [(string) $contract->id]));
            })
            ->whereNotIn('legal_archive_documents.id', Contract::withTrashed()
                ->select('legal_archive_document_id')
                ->where('organization_id', $organizationId)
                ->whereNotNull('legal_archive_document_id')
                ->whereKeyNot((int) $contract->id))
            ->whereNotExists(function ($linked) use ($organizationId, $contract): void {
                $linked->selectRaw('1')
                    ->from('legal_archive_document_links as contract_links')
                    ->whereColumn('contract_links.document_id', 'legal_archive_documents.id')
                    ->where('contract_links.organization_id', $organizationId)
                    ->whereIn('contract_links.linked_type', ['contract', Contract::class])
                    ->where('contract_links.linked_id', '<>', (string) $contract->id);
            });

        if ($contract->legal_archive_document_id !== null) {
            $query->whereKeyNot((int) $contract->legal_archive_document_id);
        }

        $this->authorizer->scopeAccessibleQuery($query, $actor, $organizationId, 'edit');
        $this->applyCandidateSearch($query, trim((string) ($filters['q'] ?? '')));

        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        return $query->orderByDesc('legal_archive_documents.updated_at')
            ->orderByDesc('legal_archive_documents.id')
            ->paginate($perPage, ['legal_archive_documents.*'], 'page', $page);
    }

    private function contractForCandidates(int $organizationId, int $projectId, int $contractId): Contract
    {
        $contract = Contract::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->whereKey($contractId)
            ->first();
        if (! $contract instanceof Contract) {
            throw new AuthorizationException;
        }

        return $contract;
    }

    private function applyCandidateSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search));
        $needle = '%'.$escaped.'%';
        $query->where(function (Builder $matching) use ($needle): void {
            $matching->whereRaw("LOWER(legal_archive_documents.title) LIKE ? ESCAPE '\\\\'", [$needle])
                ->orWhereRaw("LOWER(legal_archive_documents.document_number) LIKE ? ESCAPE '\\\\'", [$needle]);
        });
    }

    private function lockedContract(int $organizationId, int $projectId, int $contractId): Contract
    {
        $contract = Contract::query()
            ->with([
                'organization:id,name,legal_name',
                'supplier:id,organization_id,name',
            ])
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->whereKey($contractId)
            ->lockForUpdate()
            ->first();
        if (! $contract instanceof Contract) {
            throw new DomainException('contract_legal_dossier_contract_unavailable');
        }

        return $contract;
    }

    private function boundDocument(Contract $contract, int $organizationId): ?LegalArchiveDocument
    {
        if ($contract->legal_archive_document_id === null) {
            return null;
        }

        $document = LegalArchiveDocument::query()
            ->where('organization_id', $organizationId)
            ->whereKey((int) $contract->legal_archive_document_id)
            ->lockForUpdate()
            ->first();
        if (! $document instanceof LegalArchiveDocument) {
            throw new DomainException('contract_legal_dossier_binding_invalid');
        }

        return $document;
    }

    private function isExactCreateReplay(LegalArchiveDocument $document, Contract $contract, string $idempotencyKey): bool
    {
        return (string) $document->source_type === 'contract'
            && (string) $document->source_id === (string) $contract->id
            && hash_equals((string) $document->source_idempotency_key, $idempotencyKey);
    }

    private function assertAttachable(
        LegalArchiveDocument $document,
        Contract $contract,
        int $organizationId,
        int $projectId,
    ): void {
        if ((int) $document->organization_id !== $organizationId) {
            throw new DomainException('contract_legal_dossier_document_unavailable');
        }
        if ((int) $document->primary_project_id !== $projectId) {
            throw new DomainException('contract_legal_dossier_project_mismatch');
        }
        if (! $this->hasContractProfile($document, $organizationId)) {
            throw new DomainException('contract_legal_dossier_profile_invalid');
        }
        if (! $this->hasCompatibleSource($document, $contract)) {
            throw new DomainException('contract_legal_dossier_source_invalid');
        }
        if (Contract::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('legal_archive_document_id', (int) $document->id)
            ->where('id', '<>', (int) $contract->id)
            ->exists()) {
            throw new DomainException('contract_legal_dossier_document_bound_elsewhere');
        }

        $linkedElsewhere = LegalArchiveDocumentLink::query()
            ->where('organization_id', $organizationId)
            ->where('document_id', (int) $document->id)
            ->whereIn('linked_type', ['contract', Contract::class])
            ->where('linked_id', '<>', (string) $contract->id)
            ->exists();
        if ($linkedElsewhere) {
            throw new DomainException('contract_legal_dossier_document_bound_elsewhere');
        }
    }

    private function hasCompatibleSource(LegalArchiveDocument $document, Contract $contract): bool
    {
        $sourceType = trim((string) $document->source_type);
        $sourceId = trim((string) $document->source_id);
        $sourceKey = trim((string) $document->source_idempotency_key);

        return ($sourceType === '' && $sourceId === '' && $sourceKey === '')
            || ($sourceType === 'contract' && $sourceId === (string) $contract->id);
    }

    private function createContractLink(LegalArchiveDocument $document, Contract $contract): void
    {
        $existing = LegalArchiveDocumentLink::query()
            ->where('organization_id', (int) $document->organization_id)
            ->where('document_id', (int) $document->id)
            ->whereIn('linked_type', ['contract', Contract::class])
            ->where('linked_id', (string) $contract->id)
            ->first();
        if ($existing instanceof LegalArchiveDocumentLink) {
            return;
        }

        LegalArchiveDocumentLink::query()->create([
            'document_id' => (int) $document->id,
            'organization_id' => (int) $document->organization_id,
            'link_type' => 'contract',
            'linked_type' => 'contract',
            'linked_id' => (string) $contract->id,
            'display_name' => (string) $contract->number,
        ]);
    }

    private function documentProfileCode(Contract $contract): string
    {
        return $contract->supplier_id !== null ? 'contract.supply' : 'contract.work';
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function documentMetadata(Contract $contract, string $profileCode, array $data): array
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        if ($profileCode !== 'contract.supply') {
            return $metadata;
        }

        return [
            ...$metadata,
            ...$this->supplyMetadata($contract),
        ];
    }

    /** @return array{subject: string, buyer: string, supplier: string, price: float, delivery_terms: string} */
    private function supplyMetadata(Contract $contract): array
    {
        $subject = $this->firstFilledString($contract->subject);
        $buyer = $this->firstFilledString($contract->organization?->legal_name, $contract->organization?->name);
        $supplier = $this->firstFilledString($contract->supplier?->name);
        $deliveryTerms = $this->firstFilledString($contract->payment_terms, $contract->notes);
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

    private function hasContractProfile(LegalArchiveDocument $document, int $organizationId): bool
    {
        $profileCode = trim((string) $document->type_profile_code);
        if ($profileCode === '' || (string) $document->document_type !== 'contract') {
            return false;
        }

        try {
            return $this->profiles->find($organizationId, $profileCode)->category === 'contract';
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function resolveConcurrentBinding(
        int $organizationId,
        int $projectId,
        int $contractId,
        ?int $expectedDocumentId,
        ?string $idempotencyKey,
        QueryException $exception,
    ): ContractLegalDossierMutationResult {
        $contract = Contract::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->whereKey($contractId)
            ->first();
        if (! $contract instanceof Contract || $contract->legal_archive_document_id === null) {
            throw $exception;
        }
        $document = $this->boundDocument($contract, $organizationId);
        if (! $document instanceof LegalArchiveDocument) {
            throw $exception;
        }
        if ($expectedDocumentId !== null && (int) $document->id === $expectedDocumentId) {
            return new ContractLegalDossierMutationResult($contract, $document, 'replayed');
        }
        if ($idempotencyKey !== null && $this->isExactCreateReplay($document, $contract, $idempotencyKey)) {
            return new ContractLegalDossierMutationResult($contract, $document, 'replayed');
        }

        throw new DomainException('contract_legal_dossier_already_bound');
    }
}
