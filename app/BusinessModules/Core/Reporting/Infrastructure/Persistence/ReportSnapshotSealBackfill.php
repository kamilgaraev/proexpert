<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealVerifier;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealVerificationInput;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
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

    public function __construct(
        private ReportSnapshotSealStore $seals,
        private ReportSnapshotSealVerifier $verifier,
    ) {}

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
                DB::table('report_snapshot_seal_backfills')
                    ->where('snapshot_kind', $snapshotKind)
                    ->lockForUpdate()
                    ->first();
                DB::table('report_snapshot_seal_backfills')->updateOrInsert(
                    ['snapshot_kind' => $snapshotKind],
                    [
                        'status' => 'running',
                        'source_count' => $sourceCount,
                        'sealed_count' => $sealedCount,
                        'failed_count' => 0,
                        'failure_fingerprint' => null,
                        'remediation' => null,
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
                $this->validateCoverage($snapshotKind, $table, $sourceCount, $finalSealedCount);
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
                    'remediation' => 'verify_snapshot_identity_then_reseal_with_trusted_active_key',
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

    private function validateCoverage(
        string $snapshotKind,
        string $table,
        int $sourceCount,
        int $sealedCount,
    ): void {
        if ($sealedCount !== $sourceCount) {
            throw new \RuntimeException('report_snapshot_seal_backfill_coverage_mismatch');
        }
        $orphans = DB::table('report_snapshot_seals')
            ->leftJoin($table, $table.'.id', '=', 'report_snapshot_seals.snapshot_id')
            ->where('report_snapshot_seals.snapshot_kind', $snapshotKind)
            ->whereNull($table.'.id')
            ->exists();
        if ($orphans) {
            throw new \RuntimeException('report_snapshot_seal_backfill_orphan');
        }

        DB::table($table)
            ->join('report_snapshot_seals', function ($join) use ($table, $snapshotKind): void {
                $join->on('report_snapshot_seals.snapshot_id', '=', $table.'.id')
                    ->where('report_snapshot_seals.snapshot_kind', '=', $snapshotKind);
            })
            ->whereNotNull($table.'.sealed_at')
            ->orderBy($table.'.id')
            ->select([
                $table.'.id',
                $table.'.generated_at as snapshot_generated_at',
                $table.'.source_hash',
                $table.'.sealed_at as snapshot_sealed_at',
                'report_snapshot_seals.generated_at as seal_generated_at',
                'report_snapshot_seals.sealed_at as seal_sealed_at',
            ])
            ->each(function (object $record) use ($snapshotKind): void {
                $generatedAt = new DateTimeImmutable((string) $record->snapshot_generated_at);
                $sealedAt = new DateTimeImmutable((string) $record->snapshot_sealed_at);
                if ($generatedAt->format('U.u') !== (new DateTimeImmutable((string) $record->seal_generated_at))->format('U.u')
                    || $sealedAt->format('U.u') !== (new DateTimeImmutable((string) $record->seal_sealed_at))->format('U.u')) {
                    throw new \RuntimeException('report_snapshot_seal_backfill_timestamp_mismatch');
                }
                $sourceHash = new Sha256Hash((string) $record->source_hash);
                $seal = $this->seals->get($snapshotKind, (string) $record->id);
                if (! hash_equals($sourceHash->value, $seal->sealedPayloadHash->value)) {
                    throw new \RuntimeException('report_snapshot_seal_backfill_payload_mismatch');
                }
                $this->verifier->assertTrusted(new ReportSnapshotSealVerificationInput(
                    $seal,
                    (string) $record->id,
                    $snapshotKind,
                    ReportSnapshotClassification::OFFICIAL,
                    $generatedAt,
                    $sourceHash,
                ));
            }, 100);
    }
}
