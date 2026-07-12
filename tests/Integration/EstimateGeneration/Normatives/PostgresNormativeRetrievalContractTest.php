<?php

declare(strict_types=1);

namespace Tests\Integration\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\PostgresNormativeCandidateSource;
use PHPUnit\Framework\TestCase;

final class PostgresNormativeRetrievalContractTest extends TestCase
{
    public function test_versioned_index_and_bounded_parameterized_query_contract(): void
    {
        if (getenv('RUN_POSTGRES_NORMATIVE_CONTRACT') !== '1') {
            self::markTestSkipped('Opt-in PostgreSQL contract; not run in local DB-less verification.');
        }

        self::assertStringContainsString('CONCURRENTLY', PostgresNormativeCandidateSource::INDEX_CONTRACT);
        self::assertStringContainsString('organization_id = :organization_id', PostgresNormativeCandidateSource::QUERY_CONTRACT);
        self::assertStringContainsString('project_id = :project_id', PostgresNormativeCandidateSource::QUERY_CONTRACT);
        self::assertStringContainsString('version_key = :dataset_version', PostgresNormativeCandidateSource::QUERY_CONTRACT);
        self::assertStringContainsString('index_version = :semantic_index_version', PostgresNormativeCandidateSource::QUERY_CONTRACT);
        self::assertStringContainsString('LIMIT :limit', PostgresNormativeCandidateSource::QUERY_CONTRACT);
    }
}
