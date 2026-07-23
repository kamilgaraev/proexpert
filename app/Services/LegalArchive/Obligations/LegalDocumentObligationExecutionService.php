<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Obligations;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentObligation;
use App\Models\User;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class LegalDocumentObligationExecutionService
{
    public function __construct(private readonly LegalDocumentAudit $audit) {}

    /** @param array{responsible_user_id?: int|null, status: 'open'|'completed', evidence?: list<array{label?: string, url: string}>} $data */
    public function update(LegalArchiveDocument $document, int $obligationId, User $actor, array $data): LegalDocumentObligation
    {
        return DB::transaction(function () use ($document, $obligationId, $actor, $data): LegalDocumentObligation {
            if (! in_array((string) $document->status, ['active', 'effective'], true)) {
                throw new DomainException('legal_obligation_document_not_effective');
            }

            $obligation = LegalDocumentObligation::query()
                ->where('organization_id', (int) $document->organization_id)
                ->where('document_id', (int) $document->id)
                ->whereKey($obligationId)
                ->lockForUpdate()
                ->firstOrFail();

            $responsibleUserId = array_key_exists('responsible_user_id', $data)
                ? $data['responsible_user_id']
                : $obligation->responsible_user_id;
            if ($responsibleUserId !== null && ! $this->isActiveOrganizationUser((int) $responsibleUserId, (int) $document->organization_id)) {
                throw ValidationException::withMessages([
                    'responsible_user_id' => [trans_message('legal_archive.messages.obligation_responsible_invalid')],
                ]);
            }

            $evidence = array_key_exists('evidence', $data) ? array_values($data['evidence'] ?? []) : $obligation->evidence;
            if ($evidence === []) {
                throw ValidationException::withMessages([
                    'evidence' => [trans_message('legal_archive.messages.obligation_evidence_required')],
                ]);
            }

            if ($obligation->status === 'completed') {
                if ((int) $obligation->responsible_user_id === (int) $responsibleUserId && $obligation->evidence === $evidence) {
                    return $obligation->load('responsible:id,name');
                }

                throw new DomainException('legal_obligation_completed_immutable');
            }

            $obligation->fill([
                'responsible_user_id' => $responsibleUserId,
                'status' => 'completed',
                'evidence' => $evidence,
                'completed_at' => now(),
            ]);
            $obligation->save();

            $this->audit->record('obligation_completed', $document, $actor, [
                'source_event_id' => "obligation:{$obligation->id}:completed",
                'idempotency_key' => "obligation:{$obligation->id}:completed",
                'before' => ['status' => 'open'],
                'after' => ['status' => 'completed', 'responsible_user_id' => $responsibleUserId, 'evidence' => $evidence],
                'diff' => ['status' => 'completed'],
            ]);

            return $obligation->load('responsible:id,name');
        });
    }

    private function isActiveOrganizationUser(int $userId, int $organizationId): bool
    {
        return User::query()
            ->whereKey($userId)
            ->whereHas('organizations', static fn ($query) => $query
                ->where('organization_user.organization_id', $organizationId)
                ->where('organization_user.is_active', true))
            ->exists();
    }
}
