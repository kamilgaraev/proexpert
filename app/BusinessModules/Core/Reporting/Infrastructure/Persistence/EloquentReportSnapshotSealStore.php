<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Security\CanonicalReportSnapshotSealer;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final readonly class EloquentReportSnapshotSealStore implements ReportSnapshotSealStore
{
    public function __construct(private CanonicalReportSnapshotSealer $sealer) {}

    public function create(
        string $snapshotKind,
        string $snapshotId,
        DateTimeImmutable $generatedAt,
        Sha256Hash $sourceHash,
        DateTimeImmutable $sealedAt,
    ): ReportSnapshotSeal {
        return DB::transaction(function () use ($snapshotKind, $snapshotId, $generatedAt, $sourceHash, $sealedAt): ReportSnapshotSeal {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [
                    'report-snapshot-seal:'.$snapshotKind.':'.$snapshotId,
                ]);
            }
            $existing = DB::table('report_snapshot_seals')
                ->where('snapshot_kind', $snapshotKind)
                ->where('snapshot_id', $snapshotId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $persisted = $this->hydrate($existing);
                if (! hash_equals($persisted->sealedPayloadHash->value, $sourceHash->value)
                    || (new DateTimeImmutable((string) $existing->generated_at))->format('U.u') !== $generatedAt->format('U.u')
                    || $persisted->sealedAt->format('U.u') !== $sealedAt->format('U.u')) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
                }

                return $persisted;
            }

            $seal = $this->sealer->seal($snapshotId, $snapshotKind, $generatedAt, $sourceHash, $sealedAt);
            DB::table('report_snapshot_seals')->insert([
                'snapshot_kind' => $snapshotKind,
                'snapshot_id' => $snapshotId,
                'algorithm' => $seal->algorithm,
                'key_id' => $seal->keyId,
                'sealed_payload_hash' => $seal->sealedPayloadHash->value,
                'signature' => $seal->signature,
                'generated_at' => $generatedAt,
                'sealed_at' => $seal->sealedAt,
                'created_at' => $sealedAt,
            ]);

            return $seal;
        });
    }

    public function get(string $snapshotKind, string $snapshotId): ReportSnapshotSeal
    {
        $record = DB::table('report_snapshot_seals')
            ->where('snapshot_kind', $snapshotKind)
            ->where('snapshot_id', $snapshotId)
            ->first();
        if ($record === null) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED);
        }

        return $this->hydrate($record);
    }

    private function hydrate(object $record): ReportSnapshotSeal
    {
        try {
            return new ReportSnapshotSeal(
                (string) $record->key_id,
                (string) $record->algorithm,
                new Sha256Hash((string) $record->sealed_payload_hash),
                (string) $record->signature,
                new DateTimeImmutable((string) $record->sealed_at, new DateTimeZone('UTC')),
            );
        } catch (\Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED,
                previous: $exception,
            );
        }
    }
}
