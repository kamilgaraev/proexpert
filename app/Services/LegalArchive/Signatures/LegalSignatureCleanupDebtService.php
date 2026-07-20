<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\Storage\FileService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Throwable;

final readonly class LegalSignatureCleanupDebtService
{
    private const MAX_ATTEMPTS = 8;

    public function __construct(
        private FileService $files,
        private ConnectionInterface $connection,
        private LegalDocumentAudit $audit,
        private LegalSignatureCleanupMetrics $metrics = new LogLegalSignatureCleanupMetrics,
    ) {}

    public function processDue(int $limit = 100): int
    {
        $ids = $this->connection->table('legal_archive_file_cleanup_debts')
            ->where('reason', 'signature_registration_failed')
            ->whereNotNull('storage_version_id')
            ->whereNull('resolved_at')
            ->whereNull('dead_lettered_at')
            ->where(static function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->where(static function ($query): void {
                $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->pluck('id');
        $resolved = 0;
        foreach ($ids as $id) {
            $resolved += $this->processOne((int) $id) ? 1 : 0;
        }

        return $resolved;
    }

    private function processOne(int $id): bool
    {
        $lease = Str::random(64);
        $authorization = $this->connection->transaction(function () use ($id, $lease): array {
            $row = $this->connection->table('legal_archive_file_cleanup_debts')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->resolved_at !== null || $row->dead_lettered_at !== null
                || $row->reason !== 'signature_registration_failed' || $row->storage_version_id === null
                || ($row->next_attempt_at !== null && now()->lt($row->next_attempt_at))
                || ($row->lease_expires_at !== null && now()->lt($row->lease_expires_at))) {
                return ['status' => 'idle'];
            }
            $this->lockArtifactMutex((int) $row->organization_id, (string) $row->storage_path);
            $artifact = $this->connection->table('legal_signature_artifacts')
                ->where('organization_id', $row->organization_id)
                ->where('storage_path', $row->storage_path)
                ->lockForUpdate()->first();
            $referencedSignatureId = $this->connection->table('legal_document_signatures')
                ->where('organization_id', $row->organization_id)
                ->where('signature_path', $row->storage_path)
                ->where('storage_version_id', $row->storage_version_id)
                ->lockForUpdate()->value('id');
            if ($referencedSignatureId !== null || ($artifact !== null && (string) $artifact->state === 'referenced')) {
                if ($artifact !== null) {
                    $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                        'state' => 'referenced',
                        'referenced_signature_id' => $referencedSignatureId ?? $artifact->referenced_signature_id,
                        'upload_lease_token_hash' => null,
                        'upload_lease_expires_at' => null,
                        'deletion_lease_token_hash' => null,
                        'deletion_lease_expires_at' => null,
                        'updated_at' => now(),
                    ]);
                }
                $this->resolveDebt($id, null);
                $this->recordAudit('signature_storage_cleanup_reference_preserved', $row, (int) $row->attempts);
                $this->metrics->increment('legal_signature_cleanup_reference_preserved_total', [
                    'organization_id' => (int) $row->organization_id,
                ]);

                return ['status' => 'resolved'];
            }
            $authorized = $artifact !== null
                && hash_equals((string) $artifact->storage_version_id, (string) $row->storage_version_id)
                && (string) $artifact->state === 'deleting'
                && (int) $artifact->claim_count === 0
                && (bool) $artifact->cleanup_owned
                && $artifact->referenced_signature_id === null
                && ($artifact->deletion_lease_expires_at === null || now()->gte($artifact->deletion_lease_expires_at));
            if (! $authorized) {
                $this->connection->table('legal_archive_file_cleanup_debts')->where('id', $id)->update([
                    'last_error' => 'legal_signature_cleanup_authorization_rejected',
                    'dead_lettered_at' => now(),
                    'next_attempt_at' => null,
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);
                $this->recordAudit('signature_storage_cleanup_authorization_rejected', $row, (int) $row->attempts);
                $this->metrics->increment('legal_signature_cleanup_authorization_rejected_total', [
                    'organization_id' => (int) $row->organization_id,
                ]);

                return ['status' => 'idle'];
            }
            $tokenHash = hash('sha256', $lease);
            $this->connection->table('legal_archive_file_cleanup_debts')->where('id', $id)->update([
                'lease_token_hash' => $tokenHash,
                'lease_expires_at' => now()->addMinutes(5),
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);
            $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                'deletion_lease_token_hash' => $tokenHash,
                'deletion_lease_expires_at' => now()->addMinutes(5),
                'last_attempt_at' => now(),
                'attempt_count' => ((int) $artifact->attempt_count) + 1,
                'updated_at' => now(),
            ]);

            return ['status' => 'authorized', 'debt' => $row];
        }, 3);
        if ($authorization['status'] !== 'authorized') {
            return $authorization['status'] === 'resolved';
        }
        $debt = $authorization['debt'];
        if (! is_object($debt)) {
            return false;
        }
        try {
            $this->files->removeImmutable((string) $debt->storage_path, (string) $debt->storage_version_id);
        } catch (Throwable $error) {
            $this->recordFailure($id, $lease, $debt, $error);

            return false;
        }

        return $this->recordSuccess($id, $lease, $debt);
    }

    private function recordSuccess(int $id, string $lease, object $debt): bool
    {
        return $this->connection->transaction(function () use ($id, $lease, $debt): bool {
            $tokenHash = hash('sha256', $lease);
            $lockedDebt = $this->connection->table('legal_archive_file_cleanup_debts')
                ->where('id', $id)->where('lease_token_hash', $tokenHash)->lockForUpdate()->first();
            $artifact = $this->connection->table('legal_signature_artifacts')
                ->where('organization_id', $debt->organization_id)
                ->where('storage_path', $debt->storage_path)
                ->where('storage_version_id', $debt->storage_version_id)
                ->where('deletion_lease_token_hash', $tokenHash)
                ->lockForUpdate()->first();
            if ($lockedDebt === null || $artifact === null || (string) $artifact->state !== 'deleting'
                || (int) $artifact->claim_count !== 0 || ! (bool) $artifact->cleanup_owned
                || $artifact->referenced_signature_id !== null) {
                throw new \RuntimeException('legal_signature_cleanup_artifact_state_conflict');
            }
            $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                'state' => 'deleted', 'claim_count' => 0,
                'upload_lease_token_hash' => null, 'upload_lease_expires_at' => null,
                'deletion_lease_token_hash' => null, 'deletion_lease_expires_at' => null,
                'last_error_code' => null, 'updated_at' => now(),
            ]);
            $updated = $this->connection->table('legal_archive_file_cleanup_debts')
                ->where('id', $id)->where('lease_token_hash', $tokenHash)->whereNull('resolved_at')
                ->update([
                    'resolved_at' => now(), 'lease_token_hash' => null, 'lease_expires_at' => null,
                    'next_attempt_at' => null, 'last_error' => null, 'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                return false;
            }
            $this->recordAudit('signature_storage_cleanup_resolved', $debt, (int) $debt->attempts);
            $this->metrics->increment('legal_signature_cleanup_resolved_total', ['organization_id' => (int) $debt->organization_id]);

            return true;
        }, 3);
    }

    private function recordFailure(int $id, string $lease, object $debt, Throwable $error): void
    {
        $this->connection->transaction(function () use ($id, $lease, $debt, $error): void {
            $attempts = ((int) $debt->attempts) + 1;
            $dead = $attempts >= self::MAX_ATTEMPTS;
            $updated = $this->connection->table('legal_archive_file_cleanup_debts')
                ->where('id', $id)->where('lease_token_hash', hash('sha256', $lease))->whereNull('resolved_at')
                ->update([
                    'attempts' => $attempts,
                    'next_attempt_at' => $dead ? null : now()->addSeconds(min(3600, 2 ** min($attempts, 10))),
                    'last_error' => $error::class,
                    'dead_lettered_at' => $dead ? now() : null,
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                return;
            }
            $this->connection->table('legal_signature_artifacts')
                ->where('organization_id', $debt->organization_id)
                ->where('storage_path', $debt->storage_path)
                ->where('storage_version_id', $debt->storage_version_id)
                ->where('deletion_lease_token_hash', hash('sha256', $lease))
                ->update([
                    'deletion_lease_token_hash' => null,
                    'deletion_lease_expires_at' => null,
                    'last_error_code' => $error::class,
                    'dead_lettered_at' => $dead ? now() : null,
                    'updated_at' => now(),
                ]);
            $event = $dead ? 'signature_storage_cleanup_dead_lettered' : 'signature_storage_cleanup_retry_scheduled';
            $this->recordAudit($event, $debt, $attempts);
            $this->metrics->increment("legal_{$event}_total", ['organization_id' => (int) $debt->organization_id]);
        }, 3);
    }

    private function resolveDebt(int $id, ?string $lease): void
    {
        $query = $this->connection->table('legal_archive_file_cleanup_debts')
            ->where('id', $id)->whereNull('resolved_at');
        if ($lease !== null) {
            $query->where('lease_token_hash', hash('sha256', $lease));
        }
        $query->update([
            'resolved_at' => now(), 'lease_token_hash' => null, 'lease_expires_at' => null,
            'next_attempt_at' => null, 'last_error' => null, 'updated_at' => now(),
        ]);
    }

    private function recordAudit(string $event, object $debt, int $attempts): void
    {
        if ($debt->document_id === null) {
            return;
        }
        $document = (new LegalArchiveDocument)->setConnection($this->connection->getName())->newQuery()
            ->where('id', $debt->document_id)->where('organization_id', $debt->organization_id)->first();
        if (! $document instanceof LegalArchiveDocument) {
            return;
        }
        $this->audit->recordForActorId($event, $document, null, [
            'source_event_id' => "signature-cleanup-debt:{$debt->id}:{$event}:{$attempts}",
            'cleanup_debt_id' => (int) $debt->id,
            'document_version_id' => $debt->document_version_id === null ? null : (int) $debt->document_version_id,
            'storage_version_fingerprint' => hash('sha256', (string) $debt->storage_version_id),
            'attempts' => $attempts,
        ]);
    }

    private function lockArtifactMutex(int $organizationId, string $path): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ["legal-signature-artifact:{$organizationId}:{$path}"],
            );
        }
    }
}
