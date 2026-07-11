<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationUsageLedgerPostgresTest extends TestCase
{
    #[Test]
    public function concurrent_same_attempt_is_idempotent_without_leaving_fixture_rows(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1' || ! function_exists('pg_connect')) {
            self::markTestSkipped('Opt-in PostgreSQL contract is disabled.');
        }
        $connectionString = (string) getenv('ESTIMATE_USAGE_PG_CONNECTION');
        $first = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
        $second = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        $attempt = '018f47a2-4e5c-7d9a-8b1c-'.substr(hash('sha256', uniqid('', true)), 0, 12);
        $params = [$attempt, (string) getenv('ESTIMATE_USAGE_ORGANIZATION_ID'), (string) getenv('ESTIMATE_USAGE_PROJECT_ID'), (string) getenv('ESTIMATE_USAGE_SESSION_ID')];
        $sql = "INSERT INTO estimate_generation_ai_usage (attempt_id,correlation_id,immutable_fingerprint,organization_id,project_id,session_id,stage,operation,attempt_ordinal,provider,requested_model,usage_status,status,input_tokens,cached_input_tokens,output_tokens,reasoning_tokens,image_count,page_count,duration_ms,price_snapshot,pricing_status,created_at) VALUES ($1,$1,'sha256:".str_repeat('a', 64)."',$2,$3,$4,'match_normatives','rerank',1,'timeweb','fixture-model','unavailable','connection_failed',0,0,0,0,0,0,1,'{}','unavailable',CURRENT_TIMESTAMP) ON CONFLICT (attempt_id) DO NOTHING";

        pg_query($first, 'BEGIN');
        pg_query($second, 'BEGIN');
        try {
            self::assertNotFalse(pg_query_params($first, $sql, $params));
            self::assertTrue(pg_send_query_params($second, $sql, $params));
            usleep(50_000);
            self::assertTrue(pg_connection_busy($second));
            pg_query($first, 'ROLLBACK');
            while (pg_connection_busy($second)) {
                pg_consume_input($second);
                usleep(1_000);
            }
            self::assertNotFalse(pg_get_result($second));
            $count = pg_query_params($second, 'SELECT count(*) FROM estimate_generation_ai_usage WHERE attempt_id=$1', [$attempt]);
            self::assertSame('1', pg_fetch_result($count, 0, 0));
        } finally {
            @pg_query($first, 'ROLLBACK');
            @pg_query($second, 'ROLLBACK');
        }
    }
}
