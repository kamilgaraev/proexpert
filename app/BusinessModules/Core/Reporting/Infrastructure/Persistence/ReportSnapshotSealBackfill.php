<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ReportSnapshotSealBackfill
{
    private const TABLES = [
        'quality_defect_flow' => 'quality_defect_flow_snapshots',
        'safety_incident_actions' => 'safety_incident_snapshots',
        'workforce_admission' => 'safety_admission_snapshots',
    ];

    public function __construct(private ReportSnapshotSealStore $seals) {}

    public function ensureCovered(string $snapshotKind): void
    {
        $table = self::TABLES[$snapshotKind] ?? null;
        if ($table === null) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        try {
            DB::transaction(function () use ($snapshotKind, $table): void {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [
                        'report-snapshot-seal-backfill:'.$snapshotKind,
                    ]);
                }
                $sourceCount = DB::table($table)->whereNotNull('sealed_at')->count();
                $sealedCount = DB::table('report_snapshot_seals')
                    ->where('snapshot_kind', $snapshotKind)
                    ->count();
                $ledger = DB::table('report_snapshot_seal_backfills')
                    ->where('snapshot_kind', $snapshotKind)
                    ->lockForUpdate()
                    ->first();
                if ($ledger !== null
                    && $ledger->status === 'ready'
                    && (int) $ledger->source_count === $sourceCount
                    && (int) $ledger->sealed_count === $sealedCount
                    && $sourceCount === $sealedCount) {
                    return;
                }
                DB::table('report_snapshot_seal_backfills')->updateOrInsert(
                    ['snapshot_kind' => $snapshotKind],
                    [
                        'status' => 'running',
                        'source_count' => $sourceCount,
                        'sealed_count' => $sealedCount,
                        'failed_count' => 0,
                        'failure_fingerprint' => null,
                        'started_at' => now(),
                        'completed_at' => null,
                        'updated_at' => now(),
                    ],
                );

                do {
                    $missing = DB::table($table)
                        ->leftJoin('report_snapshot_seals', function ($join) use ($table, $snapshotKind): void {
                            $join->on('report_snapshot_seals.snapshot_id', '=', $table.'.id')
                                ->where('report_snapshot_seals.snapshot_kind', '=', $snapshotKind);
                        })
                        ->whereNotNull($table.'.sealed_at')
                        ->whereNull('report_snapshot_seals.snapshot_id')
                        ->orderBy($table.'.id')
                        ->limit(100)
                        ->select([
                            $table.'.id',
                            $table.'.generated_at',
                            $table.'.source_hash',
                            $table.'.sealed_at',
                        ])
                        ->get();
                    foreach ($missing as $snapshot) {
                        $this->seals->create(
                            $snapshotKind,
                            (string) $snapshot->id,
                            new DateTimeImmutable((string) $snapshot->generated_at),
                            new Sha256Hash((string) $snapshot->source_hash),
                            new DateTimeImmutable((string) $snapshot->sealed_at),
                        );
                    }
                } while ($missing->isNotEmpty());

                $finalSealedCount = DB::table('report_snapshot_seals')
                    ->where('snapshot_kind', $snapshotKind)
                    ->count();
                if ($finalSealedCount !== $sourceCount) {
                    throw new \RuntimeException('report_snapshot_seal_backfill_coverage_mismatch');
                }
                DB::table('report_snapshot_seal_backfills')
                    ->where('snapshot_kind', $snapshotKind)
                    ->update([
                        'status' => 'ready',
                        'sealed_count' => $finalSealedCount,
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);
            });
        } catch (Throwable $exception) {
            DB::table('report_snapshot_seal_backfills')->updateOrInsert(
                ['snapshot_kind' => $snapshotKind],
                [
                    'status' => 'failed',
                    'failed_count' => 1,
                    'failure_fingerprint' => hash('sha256', $exception::class.':'.$exception->getMessage()),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ],
            );
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED,
                previous: $exception,
            );
        }
    }
}
