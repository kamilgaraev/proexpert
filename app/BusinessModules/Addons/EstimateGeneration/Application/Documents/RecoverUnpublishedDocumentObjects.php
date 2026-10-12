<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class RecoverUnpublishedDocumentObjects
{
    public function __construct(private DeleteUnpublishedDocumentObject $delete) {}

    public function handle(int $limit = 10): int
    {
        $ids = DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('status', 'pending')->where('next_attempt_at', '<=', now())
            ->orderBy('id')->limit(min(100, max(1, $limit)))->pluck('id');
        $completed = 0;
        $bytes = 0;
        foreach ($ids as $id) {
            $size = (int) DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $id)->value('file_size_bytes');
            if ($bytes + $size > 268435456) {
                break;
            }
            $bytes += $size;
            $completed += $this->recover((int) $id) ? 1 : 0;
        }

        return $completed;
    }

    private function recover(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $row = DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $id)->where('status', 'pending')
                ->where('next_attempt_at', '<=', now())->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }
            $object = new UnpublishedDocumentObject((int) $row->organization_id, (int) $row->project_id, (int) $row->session_id,
                (string) $row->storage_path, (string) $row->checksum_sha256, (int) $row->file_size_bytes);
            $attempts = (int) $row->attempts + 1;
            $status = 'pending';
            $failure = null;
            try {
                $status = $this->delete->handle($object);
            } catch (Throwable $error) {
                $failure = 'document_cleanup_transport_failed';
                $status = $attempts >= 10 ? 'exhausted' : 'pending';
                Log::warning('estimate_generation.document_cleanup_deferred', ['cleanup_id' => $id,
                    'failure_code' => $failure, 'exception_class' => $error::class]);
            }
            DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $id)->update(['status' => $status, 'attempts' => $attempts,
                'last_failure_code' => $failure, 'next_attempt_at' => now()->addSeconds(min(86400, 60 * (2 ** min($attempts, 10)))),
                'completed_at' => $status === 'completed' ? now() : null, 'updated_at' => now()]);

            return $status === 'completed';
        }, 1);
    }
}
