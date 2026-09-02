<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class LegalDocumentGovernanceService
{
    public function __construct(
        private readonly LegalDocumentAuthorizer $authorization,
        private readonly LegalDocumentAudit $audit,
    ) {}

    /** @param array<string, mixed> $retention */
    public function updateRetention(LegalArchiveDocument $document, User $actor, array $retention, int $expectedLockVersion): LegalArchiveDocument
    {
        $this->assertAllowed($document, $actor, 'legal_archive.retention.manage');

        return DB::transaction(function () use ($document, $actor, $retention, $expectedLockVersion): LegalArchiveDocument {
            $document = LegalArchiveDocument::query()->whereKey($document->id)
                ->where('organization_id', $document->organization_id)->lockForUpdate()->firstOrFail();
            $this->assertAllowed($document, $actor, 'legal_archive.retention.manage');
            if ((int) $document->lock_version !== $expectedLockVersion) {
                throw LegalArchiveLockConflict::forDocument((int) $document->id, (int) $document->lock_version);
            }
            $before = Arr::only($document->getAttributes(), [
                'retention_policy',
                'retention_basis',
                'retention_started_at',
                'retention_until',
            ]);
            $document->forceFill(Arr::only($retention, [
                'retention_policy',
                'retention_basis',
                'retention_started_at',
                'retention_until',
            ]));
            $document->forceFill([
                'updated_by_user_id' => $actor->id,
                'lock_version' => $expectedLockVersion + 1,
            ])->save();
            $this->audit->record('retention_updated', $document, $actor, [
                'before' => $before,
                'after' => Arr::only($document->getAttributes(), array_keys($before)),
            ]);

            return $document->refresh();
        });
    }

    public function setLegalHold(LegalArchiveDocument $document, User $actor, bool $enabled, int $expectedLockVersion): LegalArchiveDocument
    {
        $this->assertAllowed($document, $actor, 'legal_archive.legal_hold.manage');

        return DB::transaction(function () use ($document, $actor, $enabled, $expectedLockVersion): LegalArchiveDocument {
            $document = LegalArchiveDocument::query()->whereKey($document->id)
                ->where('organization_id', $document->organization_id)->lockForUpdate()->firstOrFail();
            $this->assertAllowed($document, $actor, 'legal_archive.legal_hold.manage');
            if ((int) $document->lock_version !== $expectedLockVersion) {
                throw LegalArchiveLockConflict::forDocument((int) $document->id, (int) $document->lock_version);
            }
            $before = (bool) $document->legal_hold;
            $document->forceFill([
                'legal_hold' => $enabled,
                'updated_by_user_id' => $actor->id,
                'lock_version' => $expectedLockVersion + 1,
            ])->save();
            $this->audit->record($enabled ? 'legal_hold_enabled' : 'legal_hold_disabled', $document, $actor, [
                'before' => ['legal_hold' => $before],
                'after' => ['legal_hold' => $enabled],
            ]);

            return $document->refresh();
        });
    }

    private function assertAllowed(LegalArchiveDocument $document, User $actor, string $permission): void
    {
        $this->authorization->authorizePermission($actor, $document, $permission);
    }
}
