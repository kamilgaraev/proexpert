<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class TrainingBenchmarkOnlineMigrationRuntime
{
    public function backfillDatasets(?callable $interrupt = null): void
    {
        $this->runPhase(static fn (): array => DB::table('estimate_generation_training_datasets')->whereNull('dataset_key')->orderBy('id')->limit(500)->pluck('id')->all(), static function (array $ids): void {
            DB::statement("UPDATE estimate_generation_training_datasets SET dataset_key = uuid, version = 1, dataset_type = 'development', scope = 'organization', status = CASE WHEN status = 'processing' THEN 'processing' WHEN status IN ('processed', 'failed') THEN 'review_required' ELSE 'draft' END WHERE id = ANY(?::bigint[])", ['{'.implode(',', $ids).'}']);
        }, $interrupt);
    }

    public function backfillExamples(?callable $interrupt = null): void
    {
        $this->runPhase(static fn (): array => DB::table('estimate_generation_training_examples')->whereIn('status', ['accepted', 'indexed'])->where(static fn ($query) => $query->whereNull('reviewed_by')->orWhereNull('reviewed_at'))->orderBy('id')->limit(500)->pluck('id')->all(), static fn (array $ids) => DB::table('estimate_generation_training_examples')->whereIn('id', $ids)->update(['status' => 'pending', 'accepted_at' => null, 'indexed_at' => null]), $interrupt);
    }

    public function backfillMembership(?callable $interrupt = null): void
    {
        $this->runPhase(static fn (): array => DB::table('estimate_generation_training_examples')->where(static fn ($query) => $query->whereNull('organization_id')->orWhereNull('dataset_version'))->orderBy('id')->limit(500)->pluck('id')->all(), static function (array $ids): void {
            DB::statement('UPDATE estimate_generation_training_examples e SET organization_id = d.organization_id, dataset_version = d.version FROM estimate_generation_training_datasets d WHERE e.id = ANY(?::bigint[]) AND d.id = e.training_dataset_id', ['{'.implode(',', $ids).'}']);
        }, $interrupt);
    }

    public function backfillProcessingLeases(?callable $interrupt = null): void
    {
        $this->runPhase(static fn (): array => DB::table('estimate_generation_training_datasets')->where('status', 'processing')->orderBy('id')->limit(500)->pluck('id')->all(), static fn (array $ids) => DB::table('estimate_generation_training_datasets')->whereIn('id', $ids)->update(['status' => 'draft', 'processing_token' => null, 'processing_lease_expires_at' => null, 'error_message' => 'training_dataset_processing_lease_expired']), $interrupt);
    }

    public function backfill(
        string $table,
        string $pendingColumn,
        int $batchSize,
        callable $apply,
        ?callable $interruptAfterBatch = null,
    ): void {
        $this->assertIdentifier($table);
        $this->assertIdentifier($pendingColumn);
        if ($batchSize < 1 || $batchSize > 10_000) {
            throw new InvalidArgumentException('estimate_generation_online_migration_batch_invalid');
        }

        $batch = 0;
        while (true) {
            $ids = DB::table($table)->where($pendingColumn, false)->orderBy('id')->limit($batchSize)->pluck('id')->all();
            if ($ids === []) {
                return;
            }
            $apply($ids);
            $batch++;
            if ($interruptAfterBatch !== null && $interruptAfterBatch($batch)) {
                throw new RuntimeException('estimate_generation_online_migration_interrupted');
            }
        }
    }

    public function ensureConcurrentIndex(string $name, string $createSql): void
    {
        $this->assertIdentifier($name);
        if (! str_starts_with($createSql, 'CREATE ') || ! str_contains($createSql, ' INDEX CONCURRENTLY '.$name.' ')) {
            throw new InvalidArgumentException('estimate_generation_online_migration_index_sql_invalid');
        }

        $catalog = DB::selectOne(
            'SELECT i.indisvalid, i.indisready FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname = ?',
            [$name],
        );
        if ($catalog !== null && (bool) $catalog->indisvalid && (bool) $catalog->indisready) {
            return;
        }
        if ($catalog !== null) {
            DB::statement('DROP INDEX CONCURRENTLY '.$name);
        }
        DB::statement($createSql);

        $valid = DB::selectOne(
            'SELECT i.indisvalid, i.indisready FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname = ?',
            [$name],
        );
        if ($valid === null || ! (bool) $valid->indisvalid || ! (bool) $valid->indisready) {
            throw new RuntimeException('estimate_generation_online_migration_index_invalid');
        }
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('estimate_generation_online_migration_identifier_invalid');
        }
    }

    private function runPhase(callable $pendingIds, callable $apply, ?callable $interrupt): void
    {
        $batch = 0;
        while (($ids = $pendingIds()) !== []) {
            $apply($ids);
            $batch++;
            if ($interrupt !== null && $interrupt($batch)) {
                throw new RuntimeException('estimate_generation_online_migration_interrupted');
            }
        }
    }
}
