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

        self::assertSame('1', getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT'));
        self::assertInstanceOf(PostgresConnection::class, DB::connection());
        self::assertStringEndsWith('_contract', (string) DB::getDatabaseName());
    }
}
