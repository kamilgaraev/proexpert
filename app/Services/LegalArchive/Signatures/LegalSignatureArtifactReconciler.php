<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Files\LegalCleanupDebtKey;
use App\Services\Storage\Exceptions\VersionedObjectIntegrityException;
use App\Services\Storage\FileService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Throwable;

final readonly class LegalSignatureArtifactReconciler
{
    private const MAX_ATTEMPTS = 8;

    public function __construct(
        private FileService $files,
        private ConnectionInterface $connection,
        private LegalDocumentAudit $audit,
        private LegalSignatureCleanupMetrics $metrics = new LogLegalSignatureCleanupMetrics,
    ) {}

    public function reconcile(int $limit = 100): int
    {
        $ids = $this->connection->table('legal_signature_artifacts')
            ->whereIn('state', ['uploading', 'uploaded', 'deleting'])
            ->whereNull('referenced_signature_id')
            ->whereNull('dead_lettered_at')
            ->where(static function ($query): void {
                $query->where('state', 'deleting')
                    ->orWhere('upload_lease_expires_at', '<=', now())
                    ->orWhere(static function ($stale): void {
                        $stale->whereNull('upload_lease_expires_at')->where('updated_at', '<=', now()->subMinutes(10));
                    });
            })
            ->orderBy('id')->limit(max(1, min($limit, 500)))->pluck('id');
        $reconciled = 0;
        foreach ($ids as $id) {
            $reconciled += $this->reconcileOne((int) $id) ? 1 : 0;
        }

        return $reconciled;
    }

    private function reconcileOne(int $id): bool
    {
        $snapshot = $this->connection->table('legal_signature_artifacts')->where('id', $id)->first();
        if ($snapshot !== null && (string) $snapshot->state === 'deleting') {
            return $this->repairDeletingDebt($snapshot);
        }
        $token = Str::random(64);
        $claim = $this->connection->transaction(function () use ($id, $token): ?object {
            $artifact = $this->connection->table('legal_signature_artifacts')->where('id', $id)->lockForUpdate()->first();
            if ($artifact === null || $artifact->referenced_signature_id !== null || $artifact->dead_lettered_at !== null
                || ! in_array((string) $artifact->state, ['uploading', 'uploaded'], true)) {
                return null;
            }
            $signature = $this->connection->table('legal_document_signatures')
                ->where('organization_id', $artifact->organization_id)
                ->where('signature_path', $artifact->storage_path)
                ->when($artifact->storage_version_id !== null, static fn ($query) => $query->where('storage_version_id', $artifact->storage_version_id))
                ->lockForUpdate()->first();
            if ($signature !== null) {
                if ((string) $artifact->state === 'uploading' && $artifact->storage_version_id === null) {
                    $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                        'state' => 'uploaded', 'storage_version_id' => $signature->storage_version_id,
                        'cleanup_owned' => true, 'updated_at' => now(),
                    ]);
                }
                $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                    'state' => 'referenced', 'referenced_signature_id' => $signature->id, 'claim_count' => 0,
                    'upload_lease_token_hash' => null, 'upload_lease_expires_at' => null,
                    'deletion_lease_token_hash' => null, 'deletion_lease_expires_at' => null, 'updated_at' => now(),
                ]);
                $this->record('signature_artifact_reconcile_reference_preserved', $artifact);

                return (object) ['state' => 'reference_preserved'];
            }
            if ($artifact->upload_lease_expires_at !== null && now()->lt($artifact->upload_lease_expires_at)) {
                return null;
            }
            $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                'upload_lease_token_hash' => hash('sha256', $token),
                'upload_lease_expires_at' => now()->addMinutes(5),
                'last_attempt_at' => now(),
                'attempt_count' => ((int) $artifact->attempt_count) + 1,
                'updated_at' => now(),
            ]);

            return $artifact;
        }, 3);
        if ($claim === null) {
            return false;
        }
        if (($claim->state ?? null) === 'reference_preserved') {
            return true;
        }
        try {
            $description = $this->files->describeVersion(
                (string) $claim->storage_path,
                $claim->storage_version_id === null ? null : (string) $claim->storage_version_id,
            );
            if (! hash_equals((string) $claim->content_hash, (string) $description['sha256'])) {
                throw new VersionedObjectIntegrityException('legal_signature_artifact_hash_mismatch');
            }
        } catch (VersionedObjectIntegrityException $error) {
            if ($error->getMessage() === 's3_pinned_object_unavailable' && $claim->storage_version_id === null) {
                return $this->markAbandoned((int) $claim->id, $token, $claim);
            }
            $this->recordFailure((int) $claim->id, $token, $claim, $error);

            return false;
        } catch (Throwable $error) {
            $this->recordFailure((int) $claim->id, $token, $claim, $error);

            return false;
        }

        return $this->connection->transaction(function () use ($claim, $token, $description): bool {
            $artifact = $this->connection->table('legal_signature_artifacts')->where('id', $claim->id)
                ->where('upload_lease_token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if ($artifact === null || ! in_array((string) $artifact->state, ['uploading', 'uploaded'], true)) {
                return false;
            }
            if ((string) $artifact->state === 'uploading') {
                $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                    'state' => 'uploaded', 'storage_version_id' => (string) $description['version_id'],
                    'cleanup_owned' => true, 'updated_at' => now(),
                ]);
            } elseif (! hash_equals((string) $artifact->storage_version_id, (string) $description['version_id'])) {
                throw new \RuntimeException('legal_signature_artifact_version_mismatch');
            }
            $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                'state' => 'deleting', 'claim_count' => 0, 'cleanup_owned' => true,
                'upload_lease_token_hash' => null, 'upload_lease_expires_at' => null,
                'last_error_code' => null, 'updated_at' => now(),
            ]);
            $artifact->storage_version_id = (string) $description['version_id'];
            $this->ensureDebt($artifact);
            $this->record('signature_artifact_reconcile_cleanup_scheduled', $artifact);

            return true;
        }, 3);
    }

    private function repairDeletingDebt(object $snapshot): bool
    {
        if ($snapshot->storage_version_id === null) {
            return false;
        }
        $debtKey = LegalCleanupDebtKey::for(
            (int) $snapshot->organization_id,
            (string) $snapshot->storage_path,
            (string) $snapshot->storage_version_id,
        );

        return $this->connection->transaction(function () use ($snapshot, $debtKey): bool {
            $this->connection->table('legal_archive_file_cleanup_debts')
                ->where('organization_id', $snapshot->organization_id)->where('debt_key', $debtKey)
                ->lockForUpdate()->first();
            $artifact = $this->connection->table('legal_signature_artifacts')->where('id', $snapshot->id)
                ->lockForUpdate()->first();
            if ($artifact === null || (string) $artifact->state !== 'deleting'
                || (int) $artifact->claim_count !== 0 || ! (bool) $artifact->cleanup_owned
                || $artifact->referenced_signature_id !== null) {
                return false;
            }
            $signature = $this->connection->table('legal_document_signatures')
                ->where('organization_id', $artifact->organization_id)
                ->where('signature_path', $artifact->storage_path)
                ->where('storage_version_id', $artifact->storage_version_id)
                ->lockForUpdate()->first();
            if ($signature !== null) {
                $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                    'state' => 'referenced', 'referenced_signature_id' => $signature->id,
                    'deletion_lease_token_hash' => null, 'deletion_lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

                return false;
            }
            $this->ensureDebt($artifact);

            return true;
        }, 3);
    }

    private function markAbandoned(int $id, string $token, object $artifact): bool
    {
        return $this->connection->transaction(function () use ($id, $token, $artifact): bool {
            $updated = $this->connection->table('legal_signature_artifacts')->where('id', $id)
                ->where('upload_lease_token_hash', hash('sha256', $token))->where('state', 'uploading')->update([
                    'state' => 'abandoned', 'claim_count' => 0,
                    'upload_lease_token_hash' => null, 'upload_lease_expires_at' => null,
                    'last_error_code' => null, 'updated_at' => now(),
                ]);
            if ($updated === 1) {
                $this->record('signature_artifact_reconcile_empty_reservation', $artifact);
            }

            return $updated === 1;
        }, 3);
    }

    private function recordFailure(int $id, string $token, object $artifact, Throwable $error): void
    {
        $this->connection->transaction(function () use ($id, $token, $artifact, $error): void {
            $attempts = (int) $artifact->attempt_count + 1;
            $dead = $attempts >= self::MAX_ATTEMPTS;
            $updated = $this->connection->table('legal_signature_artifacts')->where('id', $id)
                ->where('upload_lease_token_hash', hash('sha256', $token))->update([
                    'upload_lease_token_hash' => null, 'upload_lease_expires_at' => null,
                    'last_error_code' => $error::class, 'dead_lettered_at' => $dead ? now() : null,
                    'updated_at' => now(),
                ]);
            if ($updated === 1) {
                $this->record($dead ? 'signature_artifact_reconcile_dead_lettered' : 'signature_artifact_reconcile_retry', $artifact);
            }
        }, 3);
    }

    private function ensureDebt(object $artifact): void
    {
        if ($artifact->storage_version_id === null) {
            throw new \RuntimeException('legal_signature_artifact_version_missing');
        }
        $now = now();
        $this->connection->table('legal_archive_file_cleanup_debts')->upsert([[
            'organization_id' => (int) $artifact->organization_id,
            'document_id' => (int) $artifact->document_id,
            'document_version_id' => (int) $artifact->document_version_id,
            'storage_path' => (string) $artifact->storage_path,
            'storage_version_id' => (string) $artifact->storage_version_id,
            'debt_key' => LegalCleanupDebtKey::for((int) $artifact->organization_id, (string) $artifact->storage_path, (string) $artifact->storage_version_id),
            'content_hash' => (string) $artifact->content_hash,
            'reason' => 'signature_registration_failed', 'attempts' => 0, 'next_attempt_at' => $now,
            'resolved_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]], ['organization_id', 'debt_key'], ['next_attempt_at', 'resolved_at', 'dead_lettered_at', 'updated_at']);
    }

    private function record(string $event, object $artifact): void
    {
        $this->metrics->increment("legal_{$event}_total", ['organization_id' => (int) $artifact->organization_id]);
        $document = (new LegalArchiveDocument)->setConnection($this->connection->getName())->newQuery()
            ->where('id', $artifact->document_id)->where('organization_id', $artifact->organization_id)->first();
        if ($document instanceof LegalArchiveDocument) {
            $this->audit->recordForActorId($event, $document, null, [
                'source_event_id' => "signature-artifact:{$artifact->id}:{$event}:{$artifact->attempt_count}",
                'signature_artifact_id' => (int) $artifact->id,
            ]);
        }
    }
}
