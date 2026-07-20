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
        $debt = $this->connection->transaction(function () use ($id, $lease): ?object {
            $row = $this->connection->table('legal_archive_file_cleanup_debts')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->resolved_at !== null || $row->dead_lettered_at !== null
                || ($row->next_attempt_at !== null && now()->lt($row->next_attempt_at))
                || ($row->lease_expires_at !== null && now()->lt($row->lease_expires_at))) {
                return null;
            }
            $this->connection->table('legal_archive_file_cleanup_debts')->where('id', $id)->update([
                'lease_token_hash' => hash('sha256', $lease),
                'lease_expires_at' => now()->addMinutes(5),
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);

            return $row;
        }, 3);
        if ($debt === null) {
            return false;
        }
        if ($this->resolveReferencedArtifact($id, $lease, $debt)) {
            return true;
        }
        try {
            $this->files->removeImmutable(
                (string) $debt->storage_path,
                $debt->storage_version_id === null ? null : (string) $debt->storage_version_id,
            );
        } catch (Throwable $error) {
            $this->recordFailure($id, $lease, $debt, $error);

            return false;
        }

        return $this->recordSuccess($id, $lease, $debt);
    }

    private function resolveReferencedArtifact(int $id, string $lease, object $debt): bool
    {
        if ($debt->storage_version_id === null
            || ! $this->connection->getSchemaBuilder()->hasTable('legal_signature_artifacts')) {
            return false;
        }

        return $this->connection->transaction(function () use ($id, $lease, $debt): bool {
            $artifact = $this->connection->table('legal_signature_artifacts')
                ->where('organization_id', $debt->organization_id)
                ->where('storage_path', $debt->storage_path)
                ->where('storage_version_id', $debt->storage_version_id)
                ->lockForUpdate()->first();
            $referencedSignatureId = $this->connection->table('legal_document_signatures')
                ->where('organization_id', $debt->organization_id)
                ->where('signature_path', $debt->storage_path)
                ->where('storage_version_id', $debt->storage_version_id)
                ->lockForUpdate()->value('id');
            if ($referencedSignatureId === null && ($artifact === null || (string) $artifact->state !== 'referenced')) {
                return false;
            }
            if ($artifact !== null) {
                $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                    'state' => 'referenced',
                    'referenced_signature_id' => $referencedSignatureId ?? $artifact->referenced_signature_id,
                    'updated_at' => now(),
                ]);
            }
            $updated = $this->connection->table('legal_archive_file_cleanup_debts')
                ->where('id', $id)->where('lease_token_hash', hash('sha256', $lease))->whereNull('resolved_at')
                ->update([
                    'resolved_at' => now(), 'lease_token_hash' => null, 'lease_expires_at' => null,
                    'next_attempt_at' => null, 'last_error' => null, 'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                return false;
            }
            $this->recordAudit('signature_storage_cleanup_reference_preserved', $debt, (int) $debt->attempts);
            $this->metrics->increment('legal_signature_cleanup_reference_preserved_total', [
                'organization_id' => (int) $debt->organization_id,
            ]);

            return true;
        }, 3);
    }

    private function recordSuccess(int $id, string $lease, object $debt): bool
    {
        return $this->connection->transaction(function () use ($id, $lease, $debt): bool {
            $updated = $this->connection->table('legal_archive_file_cleanup_debts')
                ->where('id', $id)->where('lease_token_hash', hash('sha256', $lease))->whereNull('resolved_at')
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
            $event = $dead ? 'signature_storage_cleanup_dead_lettered' : 'signature_storage_cleanup_retry_scheduled';
            $this->recordAudit($event, $debt, $attempts);
            $this->metrics->increment("legal_{$event}_total", ['organization_id' => (int) $debt->organization_id]);
        }, 3);
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
}
