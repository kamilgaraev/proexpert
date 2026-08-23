<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

abstract class EstimateGenerationPostgresTestCase extends EstimateGenerationApplicationTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1') {
            self::markTestSkipped('Requires the isolated estimate-generation PostgreSQL contract runner.');
        }

        self::assertInstanceOf(PostgresConnection::class, DB::connection());
        self::assertStringEndsWith('_contract', (string) DB::getDatabaseName());
    }
}
