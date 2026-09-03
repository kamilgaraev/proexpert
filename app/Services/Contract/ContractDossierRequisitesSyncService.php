<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Models\Contract;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\LegalArchive\LegalDocumentAggregateLock;
use App\Services\LegalArchive\Editor\LegalDocumentEditGuard;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ContractDossierRequisitesSyncService
{
    public function __construct(
        private readonly LegalArchiveRegistryService $registry,
        private readonly LegalDocumentAggregateLock $locks,
    ) {}

    public function synchronize(Contract $contract, Contract $previous, ?int $actorId): array
    {
        if (! $contract->legal_archive_document_id || ! $contract->dossier_creation_key) {
            return [];
        }

        $document = $this->locks->lockDocument(
            DB::connection(),
            (int) $contract->organization_id,
            (int) $contract->legal_archive_document_id,
        );

        if ($document->source_type !== 'contract'
            || (string) $document->source_id !== (string) $contract->id
            || $document->source_idempotency_key !== $contract->dossier_creation_key) {
            return [];
        }

        $changes = [];
        foreach ([
            'number' => 'document_number',
            'date' => 'document_date',
            'start_date' => 'effective_from',
            'end_date' => 'effective_until',
        ] as $contractField => $documentField) {
            $before = $this->value($previous->getAttribute($contractField));
            $after = $this->value($contract->getAttribute($contractField));
            if ($before !== $after && $this->value($document->getAttribute($documentField)) === $before) {
                $changes[$documentField] = $after;
            }
        }

        if ($changes === []) {
            return [];
        }

        try {
            (new LegalDocumentEditGuard(DB::connection()))->assertVersionMutationAllowed($document);
        } catch (DomainException $exception) {
            if (! in_array($exception->getMessage(), [
                'legal_document_editing_frozen',
                'legal_document_active_workflow_exists',
                'legal_document_active_signature_exists',
                'legal_document_active_editor_exists',
            ], true)) {
                throw $exception;
            }

            return ['dossier_requisites_sync' => ['skipped' => $exception->getMessage()]];
        }

        $this->registry->update($document, (int) $contract->organization_id, $actorId, [
            ...$changes,
            'lock_version' => (int) $document->lock_version,
        ]);

        return ['dossier_requisites_sync' => ['fields' => array_keys($changes)]];
    }

    private function value(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : ($value === null ? null : (string) $value);
    }
}
