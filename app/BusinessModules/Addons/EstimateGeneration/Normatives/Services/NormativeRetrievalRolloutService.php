<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final readonly class NormativeRetrievalRolloutService
{
    public function __construct(private ConnectionInterface $connection) {}

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
            if (($state['status'] ?? null) !== 'complete') {
                throw new RuntimeException('Normative retrieval backfill is incomplete.');
            }
            $this->connection->statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS estimate_norms_collection_unit_idx ON estimate_norms (collection_id, canonical_unit)');
            $this->connection->statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS estimate_norms_section_dimension_idx ON estimate_norms (section_code, unit_dimension)');
            $this->connection->statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS estimate_norms_search_vector_gin ON estimate_norms USING gin (search_vector)');
            $this->connection->unprepared("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='estimate_norms_validity_ck') THEN ALTER TABLE estimate_norms ADD CONSTRAINT estimate_norms_validity_ck CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from) NOT VALID; END IF; END $$");
            $this->connection->unprepared("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='estimate_norm_semantic_score_ck') THEN ALTER TABLE estimate_norm_semantic_scores ADD CONSTRAINT estimate_norm_semantic_score_ck CHECK (score >= 0 AND score <= 1) NOT VALID; END IF; END $$");
            $this->connection->statement('ALTER TABLE estimate_norms VALIDATE CONSTRAINT estimate_norms_validity_ck');
            $this->connection->statement('ALTER TABLE estimate_norm_semantic_scores VALIDATE CONSTRAINT estimate_norm_semantic_score_ck');
            $this->connection->table('estimate_normative_retrieval_rollouts')->where('schema_version', NormativeRetrievalBackfillService::VERSION)->update(['status' => 'enabled', 'last_error' => null, 'updated_at' => now()]);

            return $this->status();
        } catch (\Throwable $exception) {
            $this->connection->table('estimate_normative_retrieval_rollouts')->where('schema_version', NormativeRetrievalBackfillService::VERSION)->update(['status' => 'failed', 'last_error' => mb_substr($exception::class, 0, 255), 'updated_at' => now()]);
            throw $exception;
        } finally {
            $this->connection->select("SELECT pg_advisory_unlock(hashtext('normative-retrieval-v1'))");
        }
    }
}
