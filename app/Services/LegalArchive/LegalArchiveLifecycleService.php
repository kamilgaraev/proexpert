<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Obligations\LegalDocumentObligationService;
use DomainException;
use Illuminate\Database\ConnectionInterface;

final readonly class LegalArchiveLifecycleService
{
    public function __construct(
        private LegalDocumentAuthorizer $access,
        private LegalDocumentAudit $audit,
        private ConnectionInterface $connection,
        private LegalDocumentAggregateLock $lock,
        ?LegalDocumentObligationService $obligations = null,
    ) {
        $this->obligations = $obligations ?? new LegalDocumentObligationService;
    }

    private LegalDocumentObligationService $obligations;

    public function archive(LegalArchiveDocument $document, User $actor, int $expectedLockVersion): LegalArchiveDocument
    {
        return $this->mutate($document, $actor, $expectedLockVersion, 'legal_archive.archive', function (LegalArchiveDocument $locked) use ($actor): array {
            if ((bool) $locked->legal_hold) {
                throw new DomainException('archive_blocked_by_legal_hold');
            }

            return [
                'status' => 'archived',
                'lifecycle_status' => 'archived',
                'archived_at' => now(),
                'archived_by_user_id' => (int) $actor->id,
            ];
        }, 'archived');
    }

    public function restore(LegalArchiveDocument $document, User $actor, int $expectedLockVersion): LegalArchiveDocument
    {
        return $this->mutate($document, $actor, $expectedLockVersion, 'legal_archive.archive', static function (LegalArchiveDocument $locked): array {
            if ($locked->archived_at === null) {
                throw new DomainException('document_not_archived');
            }

            return [
                'status' => 'draft',
                'lifecycle_status' => 'draft',
                'archived_at' => null,
                'archived_by_user_id' => null,
            ];
        }, 'restored');
    }

    public function activate(LegalArchiveDocument $document, User $actor, int $expectedLockVersion): LegalArchiveDocument
    {
        return $this->mutate($document, $actor, $expectedLockVersion, 'legal_archive.signatures.verify', static function (LegalArchiveDocument $locked): array {
            if ((string) $locked->approval_status !== 'approved' || (string) $locked->signature_status !== 'signed') {
                throw new DomainException('activation_requirements_not_met');
            }

            return ['status' => 'active', 'lifecycle_status' => 'active', 'activated_at' => now()];
        }, 'activated', function (LegalArchiveDocument $locked): void {
            $this->obligations->syncFromEffectiveDocument($locked);
        });
    }

    private function mutate(
        LegalArchiveDocument $document,
        User $actor,
        int $expectedLockVersion,
        string $permission,
        callable $changes,
        string $event,
        ?callable $afterMutation = null,
    ): LegalArchiveDocument {
        return $this->connection->transaction(function () use ($document, $actor, $expectedLockVersion, $permission, $changes, $event, $afterMutation): LegalArchiveDocument {
            $locked = $this->lock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $this->access->authorizePermission($actor, $locked, $permission);
            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw LegalArchiveLockConflict::forDocument((int) $locked->id, (int) $locked->lock_version);
            }
            $before = ['status' => $locked->status, 'lifecycle_status' => $locked->lifecycle_status, 'lock_version' => (int) $locked->lock_version];
            $locked->forceFill([
                ...$changes($locked),
                'updated_by_user_id' => (int) $actor->id,
                'lock_version' => $expectedLockVersion + 1,
            ])->save();
            if ($afterMutation !== null) {
                $afterMutation($locked);
            }
            $this->audit->record($event, $locked, $actor, [
                'before' => $before,
                'after' => ['status' => $locked->status, 'lifecycle_status' => $locked->lifecycle_status, 'lock_version' => (int) $locked->lock_version],
            ]);

            return $locked->refresh();
        }, 3);
    }
}
