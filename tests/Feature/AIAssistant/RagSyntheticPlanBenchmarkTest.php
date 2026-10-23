<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\IsolatedPostgresTestDatabase;

final class RagSyntheticPlanBenchmarkTest extends TestCase
{
    private const DIMENSIONS = 1024;

    private const ROWS = 2048;

    private const ALLOWED_ROWS = 64;

    private const LIMIT = 8;

    public function test_synthetic_filtered_plans_against_exact_oracle(): void
    {
        $output = getenv('MOST_RAG_BENCHMARK_OUTPUT');
        if (! is_string($output) || $output === '') {
            self::markTestSkipped('Set MOST_RAG_BENCHMARK_OUTPUT to an external JSON artifact path.');
        }
        $directory = realpath(dirname($output));
        $repository = realpath(dirname(__DIR__, 3));
        self::assertNotFalse($directory);
        self::assertNotFalse($repository);
        self::assertFalse(str_starts_with(strtolower($directory).DIRECTORY_SEPARATOR, strtolower($repository).DIRECTORY_SEPARATOR));
        self::assertSame('ai-assistant', getenv('MOST_POSTGRES_TEST_PROFILE'));

        $connection = IsolatedPostgresTestDatabase::connection();
        $schema = $connection->getConfig('schema');
        self::assertIsString($schema);
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]{24}$/D', $schema);
        $connection->statement('SET search_path TO "'.$schema.'", public');
        $connection->statement("SET statement_timeout = '30s'");
        $seedStarted = hrtime(true);
        $this->seed($connection);
        $seedMs = (hrtime(true) - $seedStarted) / 1_000_000;
        $corpus = $connection->selectOne('SELECT count(*) AS rows, min(vector_dims(embedding)) AS min_dimensions, max(vector_dims(embedding)) AS max_dimensions, min(vector_norm(embedding)) AS min_norm, max(vector_norm(embedding)) AS max_norm FROM ai_rag_chunks');
        self::assertSame(self::ROWS, (int) $corpus->rows);
        self::assertSame(self::DIMENSIONS, (int) $corpus->min_dimensions);
        self::assertSame(self::DIMENSIONS, (int) $corpus->max_dimensions);
        self::assertEqualsWithDelta(1.0, (float) $corpus->min_norm, 0.000001);
        self::assertEqualsWithDelta(1.0, (float) $corpus->max_norm, 0.000001);
        [$currentSql, $currentBindings] = $this->currentQuery();
        [$directSql, $directBindings] = $this->directQuery($currentSql, $currentBindings);
        $strategies = [
            'flat_exact_oracle' => [$directSql, $directBindings, ['enable_indexscan' => 'off', 'enable_bitmapscan' => 'off']],
            'current_materialized' => [$currentSql, $currentBindings, []],
            'direct_aligned_default' => [$directSql, $directBindings, []],
            'direct_aligned_forced_hnsw' => [$directSql, $directBindings, ['enable_seqscan' => 'off', 'hnsw.ef_search' => '40', 'hnsw.iterative_scan' => 'off']],
        ];
        $results = [];
        $oracleIds = [];
        foreach ($strategies as $name => [$sql, $bindings, $settings]) {
            $connection->beginTransaction();
            try {
                foreach ($settings as $setting => $value) {
                    $connection->statement('SET LOCAL '.$setting.' = '.$value);
                }
                $connection->select($sql, $bindings);
                $durations = [];
                $rows = [];
                for ($sample = 0; $sample < 3; $sample++) {
                    $started = hrtime(true);
                    $rows = $connection->select($sql, $bindings);
                    $durations[] = (hrtime(true) - $started) / 1_000_000;
                }
                $planRow = $connection->select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings)[0];
                $plan = json_decode((string) $planRow->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
                $ids = array_map(static fn (object $row): int => (int) $row->id, $rows);
                $scopeViolations = 0;
                foreach ($rows as $row) {
                    if ((int) $row->project_id !== 1 || (int) $row->rag_source_id !== 1
                        || (int) $row->id < 1 || (int) $row->id > self::ALLOWED_ROWS) {
                        $scopeViolations++;
                    }
                }
                if ($name === 'flat_exact_oracle') {
                    $oracleIds = $ids;
                }
                sort($durations);
                $results[$name] = [
                    'settings' => $settings,
                    'ids' => $ids,
                    'returned_rows' => count($ids),
                    'unique_ids' => count(array_unique($ids)),
                    'scope_violations' => $scopeViolations,
                    'hnsw_index_used' => str_contains(json_encode($plan, JSON_THROW_ON_ERROR), 'c5_embedding_1024_hnsw'),
                    'recall_at_8' => count(array_intersect($oracleIds, $ids)) / self::LIMIT,
                    'client_ms_sorted' => $durations,
                    'client_median_ms' => $durations[1],
                    'planning_ms' => $plan['Planning Time'],
                    'execution_ms' => $plan['Execution Time'],
                    'plan' => $this->safePlan($plan['Plan']),
                ];
            } finally {
                $connection->rollBack();
            }
        }
        $artifact = [
            'scope' => 'synthetic SQL shape; fixed source allowlist; not production actor authorization, latency, semantic quality or hybrid end-to-end',
            'dimensions' => self::DIMENSIONS,
            'rows' => self::ROWS,
            'allowed_rows' => self::ALLOWED_ROWS,
            'limit' => self::LIMIT,
            'fixture_and_index_build_ms' => $seedMs,
            'index_bytes' => (int) $connection->selectOne("SELECT pg_relation_size('c5_embedding_1024_hnsw') AS bytes")->bytes,
            'fixture_distribution' => '64 approved-source rows; others alternate same-project unapproved source, other project, other organization. Four approved vectors nearest; distractors nearer than remaining approved vectors. Dense deterministic unit vectors, synthetic ordering gold only.',
            'samples' => 'one warmup, three client samples, then separate EXPLAIN ANALYZE per strategy; ordered, warm-cache evidence, insufficient for p95 or production speed claims',
            'retriever_sha256' => hash_file('sha256', dirname(__DIR__, 3).'/app/BusinessModules/Features/AIAssistant/Services/Rag/RagRetriever.php'),
            'pgvector_version' => $connection->selectOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'")->extversion,
            'postgres_version' => $connection->selectOne('SHOW server_version')->server_version,
            'results' => $results,
        ];
        self::assertNotFalse(file_put_contents($output, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL));
        fwrite(STDOUT, PHP_EOL.'C5 synthetic benchmark artifact: '.$output.PHP_EOL);
        self::assertCount(self::LIMIT, $oracleIds);
        self::assertFalse($results['flat_exact_oracle']['hnsw_index_used']);
        self::assertSame($oracleIds, $results['current_materialized']['ids']);
        self::assertTrue($results['direct_aligned_forced_hnsw']['hnsw_index_used']);
        foreach ($results as $result) {
            self::assertSame($result['returned_rows'], $result['unique_ids']);
            self::assertLessThanOrEqual(self::LIMIT, $result['returned_rows']);
            self::assertSame(0, $result['scope_violations']);
        }
    }

    private function seed(Connection $connection): void
    {
        $connection->statement('CREATE TABLE ai_rag_sources (id bigint PRIMARY KEY, organization_id bigint NOT NULL, source_type text NOT NULL, entity_type text NOT NULL, entity_id bigint NOT NULL, title text NOT NULL, indexed_at timestamp NOT NULL, checksum text NOT NULL, source_version text NOT NULL)');
        $connection->statement('CREATE TABLE ai_rag_chunks (id bigint NOT NULL, source_id bigint NOT NULL, organization_id bigint NOT NULL, project_id bigint NOT NULL, chunk_index integer NOT NULL, content text NOT NULL, metadata jsonb NOT NULL, embedding vector, embedding_provider text NOT NULL, embedding_model text NOT NULL)');
        for ($source = 1; $source <= 4; $source++) {
            $connection->table('ai_rag_sources')->insert(['id' => $source, 'organization_id' => $source === 4 ? 2 : 1,
                'source_type' => 'file_document', 'entity_type' => 'synthetic', 'entity_id' => $source,
                'title' => 'Synthetic source '.$source, 'indexed_at' => '2026-01-01 00:00:00', 'checksum' => hash('sha256', (string) $source), 'source_version' => 'synthetic-v1']);
        }
        $batch = [];
        for ($id = 1; $id <= self::ROWS; $id++) {
            $source = $id <= self::ALLOWED_ROWS ? 1 : 2 + ($id % 3);
            $batch[] = ['id' => $id, 'source_id' => $source, 'organization_id' => $source === 4 ? 2 : 1,
                'project_id' => $source === 3 ? 2 : 1, 'chunk_index' => $id,
                'content' => str_repeat('Synthetic bounded text. ', 20), 'metadata' => '{}',
                'embedding' => $this->vector($id), 'embedding_provider' => 'synthetic', 'embedding_model' => 'deterministic-unit-v1'];
            if (count($batch) === 32) {
                $connection->table('ai_rag_chunks')->insert($batch);
                $batch = [];
            }
        }
        $connection->statement('CREATE INDEX c5_embedding_1024_hnsw ON ai_rag_chunks USING hnsw ((embedding::vector(1024)) vector_cosine_ops) WHERE vector_dims(embedding) = 1024');
        $connection->statement('ANALYZE ai_rag_sources');
        $connection->statement('ANALYZE ai_rag_chunks');
    }

    private function vector(int $id): string
    {
        $cosine = $id <= 4 ? 0.99999 - $id * 0.000001 : ($id <= self::ALLOWED_ROWS ? 0.8 + $id * 0.001 : 0.95 + $id / 100000);
        $tail = [];
        $norm = 0.0;
        for ($dimension = 1; $dimension < self::DIMENSIONS; $dimension++) {
            $value = (($id * 104729 + $dimension * 15485863) % 2003 - 1001) / 1001;
            $tail[] = $value;
            $norm += $value * $value;
        }
        $scale = sqrt((1 - $cosine * $cosine) / $norm);

        return json_encode([$cosine, ...array_map(static fn (float $value): float => $value * $scale, $tail)], JSON_THROW_ON_ERROR);
    }

    /** @return array{string, array<int, mixed>} */
    private function currentQuery(): array
    {
        $provider = new class implements RagEmbeddingProviderInterface
        {
            public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
            {
                throw new RuntimeException('synthetic_benchmark_must_not_embed');
            }

            public function provider(): string
            {
                return 'synthetic';
            }

            public function model(): string
            {
                return 'deterministic-unit-v1';
            }

            public function dimensions(): int
            {
                return 1024;
            }
        };
        $retriever = (new ReflectionClass(RagRetriever::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(RagRetriever::class, 'postgresRows');
        $capture = new class
        {
            /** @var list<array{query: string, bindings: array<int, mixed>}> */
            public array $queries = [];

            /** @param array<int, mixed> $bindings @return array<never, never> */
            public function select(string $sql, array $bindings): array
            {
                $this->queries[] = ['query' => $sql, 'bindings' => $bindings];

                return [];
            }
        };
        $previousApplication = DB::getFacadeApplication();
        $previousRoot = DB::getFacadeRoot();
        DB::setFacadeApplication(null);
        DB::swap($capture);
        try {
            $method->invoke($retriever, [1.0, ...array_fill(0, 1023, 0.0)], $provider, 1, self::LIMIT, ['file_document'], [1], 1, false, [1]);
        } finally {
            DB::clearResolvedInstance('db');
            if ($previousRoot !== null) {
                DB::swap($previousRoot);
            }
            DB::setFacadeApplication($previousApplication);
        }
        $queries = $capture->queries;
        self::assertCount(1, $queries);

        return [$queries[0]['query'], $queries[0]['bindings']];
    }

    /** @param array<int, mixed> $bindings @return array{string, array<int, mixed>} */
    private function directQuery(string $sql, array $bindings): array
    {
        self::assertSame(1, preg_match('/\AWITH compatible_chunks AS MATERIALIZED \((.*?)\)\nSELECT .*?\nFROM compatible_chunks\nORDER BY embedding <=> \?::vector\nLIMIT \?\z/s', $sql, $matches));
        $inner = str_replace('c.embedding'."\n".'FROM', '1 - (c.embedding::vector(1024) <=> ?::vector) AS similarity'."\n".'FROM', $matches[1]);
        self::assertNotSame($matches[1], $inner);
        $inner = str_replace('vector_dims(c.embedding) = ?', 'vector_dims(c.embedding) = 1024', $inner);
        $scopeBindings = array_slice($bindings, 0, -3);
        unset($scopeBindings[3]);

        return [$inner.'ORDER BY c.embedding::vector(1024) <=> ?::vector LIMIT ?', [$bindings[count($bindings) - 3], ...array_values($scopeBindings), $bindings[count($bindings) - 2], self::LIMIT]];
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    private function safePlan(array $plan): array
    {
        $keys = ['Node Type', 'Parent Relationship', 'Subplan Name', 'Relation Name', 'Alias', 'Index Name', 'Scan Direction', 'Startup Cost', 'Total Cost', 'Plan Rows', 'Plan Width', 'Actual Startup Time', 'Actual Total Time', 'Actual Rows', 'Actual Loops', 'Rows Removed by Filter', 'Rows Removed by Join Filter', 'Shared Hit Blocks', 'Shared Read Blocks', 'Shared Dirtied Blocks', 'Shared Written Blocks', 'Local Hit Blocks', 'Local Read Blocks', 'Temp Read Blocks', 'Temp Written Blocks', 'Sort Method', 'Sort Space Used', 'Sort Space Type'];
        $safe = array_intersect_key($plan, array_flip($keys));
        if (isset($plan['Plans'])) {
            $safe['Plans'] = array_map(fn (array $child): array => $this->safePlan($child), $plan['Plans']);
        }

        return $safe;
    }
}
