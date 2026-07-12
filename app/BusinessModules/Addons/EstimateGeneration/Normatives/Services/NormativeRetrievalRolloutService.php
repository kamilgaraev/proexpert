<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final readonly class NormativeRetrievalRolloutService
{
    public function __construct(private ConnectionInterface $connection, private NormativeRolloutFaultInjector $faults) {}

    public function status(): array
    {
        return (array) ($this->connection->table('estimate_normative_retrieval_rollouts')->where('schema_version', NormativeRetrievalBackfillService::VERSION)->first() ?? []);
    }

    public function deploy(): array
    {
        if ($this->connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Normative rollout requires PostgreSQL.');
        }
        $locked = (bool) ($this->connection->selectOne("SELECT pg_try_advisory_lock(hashtext('normative-retrieval-v1')) AS locked")->locked ?? false);
        if (! $locked) {
            throw new RuntimeException('Normative rollout is already running.');
        }
        try {
            $state = $this->status();
            if (($state['backfill_status'] ?? null) !== 'complete') {
                throw new RuntimeException('Normative retrieval backfill is incomplete.');
            }
            $phase = (string) ($state['deploy_phase'] ?? 'pending');
            $deployStatus = (string) ($state['deploy_status'] ?? 'pending');
            if (! in_array($phase, ['indexes', 'constraints', 'validate', 'enabled'], true) || ($phase === 'indexes' && $deployStatus !== 'complete')) {
                $this->phase('indexes', 'running');
                $this->connection->statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS estimate_norms_collection_unit_idx ON estimate_norms (collection_id, canonical_unit)');
                $this->faults->after('index_collection');
                $this->connection->statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS estimate_norms_section_dimension_idx ON estimate_norms (section_code, unit_dimension)');
                $this->connection->statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS estimate_norms_search_vector_gin ON estimate_norms USING gin (search_vector)');
                $this->phase('indexes', 'complete');
                $this->faults->after('indexes');
                $phase = 'indexes';
                $deployStatus = 'complete';
            }
            if ($phase === 'indexes' || ($phase === 'constraints' && $deployStatus !== 'complete')) {
                $this->phase('constraints', 'running');
                $this->connection->unprepared("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='estimate_norms_validity_ck') THEN ALTER TABLE estimate_norms ADD CONSTRAINT estimate_norms_validity_ck CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from) NOT VALID; END IF; END $$");
                $this->faults->after('constraint_validity');
                $this->connection->unprepared("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='estimate_norm_semantic_score_ck') THEN ALTER TABLE estimate_norm_semantic_scores ADD CONSTRAINT estimate_norm_semantic_score_ck CHECK (score >= 0 AND score <= 1) NOT VALID; END IF; END $$");
                $this->phase('constraints', 'complete');
                $this->faults->after('constraints');
                $phase = 'constraints';
                $deployStatus = 'complete';
            }
            if ($phase === 'constraints' || ($phase === 'validate' && $deployStatus !== 'complete')) {
                $this->phase('validate', 'running');
                $this->connection->statement('ALTER TABLE estimate_norms VALIDATE CONSTRAINT estimate_norms_validity_ck');
                $this->connection->statement('ALTER TABLE estimate_norm_semantic_scores VALIDATE CONSTRAINT estimate_norm_semantic_score_ck');
                $this->phase('validate', 'complete');
                $this->faults->after('validate');
            }
            $this->phase('enabled', 'enabled');

            return $this->status();
        } catch (\Throwable $exception) {
            $this->connection->table('estimate_normative_retrieval_rollouts')->where('schema_version', NormativeRetrievalBackfillService::VERSION)->update(['deploy_status' => 'failed', 'last_error' => mb_substr($exception::class, 0, 255), 'updated_at' => now()]);
            throw $exception;
        } finally {
            $this->connection->select("SELECT pg_advisory_unlock(hashtext('normative-retrieval-v1'))");
        }
    }

    private function phase(string $phase, string $status): void
    {
        $this->connection->table('estimate_normative_retrieval_rollouts')->where('schema_version', NormativeRetrievalBackfillService::VERSION)->update(['deploy_phase' => $phase, 'deploy_status' => $status, 'last_error' => null, 'updated_at' => now()]);
    }
}
