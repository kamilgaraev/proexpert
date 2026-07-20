<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use App\Models\Organization;
use App\Services\Storage\FileService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class LegalDocumentFileCleanupDebtService
{
    private const MAX_ATTEMPTS = 8;

    public function __construct(
        private FileService $files,
        private ConnectionInterface $connection,
    ) {}

    public function processDue(int $limit = 100): int
    {
        $ids = $this->connection->table('legal_archive_file_cleanup_debts')
            ->whereNull('storage_version_id')
            ->where('reason', '!=', 'signature_registration_failed')
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
            $row = $this->connection->table('legal_archive_file_cleanup_debts')
                ->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->resolved_at !== null || $row->dead_lettered_at !== null
                || $row->storage_version_id !== null || $row->reason === 'signature_registration_failed'
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

        try {
            $organization = (new Organization)->forceFill(['id' => (int) $debt->organization_id]);
            if (! $this->files->delete((string) $debt->storage_path, $organization)) {
                throw new RuntimeException('legal_document_file_cleanup_failed');
            }
        } catch (Throwable $error) {
            $this->recordFailure($id, $lease, $debt, $error);

            return false;
        }

        return $this->connection->transaction(function () use ($id, $lease): bool {
            return $this->connection->table('legal_archive_file_cleanup_debts')
                ->where('id', $id)
                ->where('lease_token_hash', hash('sha256', $lease))
                ->whereNull('resolved_at')
                ->update([
                    'resolved_at' => now(),
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'next_attempt_at' => null,
                    'last_error' => null,
                    'updated_at' => now(),
                ]) === 1;
        }, 3);
    }

    private function recordFailure(int $id, string $lease, object $debt, Throwable $error): void
    {
        $this->connection->transaction(function () use ($id, $lease, $debt, $error): void {
            $attempts = ((int) $debt->attempts) + 1;
            $dead = $attempts >= self::MAX_ATTEMPTS;
            $this->connection->table('legal_archive_file_cleanup_debts')
                ->where('id', $id)
                ->where('lease_token_hash', hash('sha256', $lease))
                ->whereNull('resolved_at')
                ->update([
                    'attempts' => $attempts,
                    'next_attempt_at' => $dead ? null : now()->addSeconds(min(3600, 2 ** min($attempts, 10))),
                    'last_error' => $error::class,
                    'dead_lettered_at' => $dead ? now() : null,
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);
        }, 3);
    }
}
