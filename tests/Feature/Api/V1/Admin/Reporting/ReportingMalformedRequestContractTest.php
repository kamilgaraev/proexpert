<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\HermeticReportingHttpHarness;

final class ReportingMalformedRequestContractTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_malformed_request_is_rejected_before_action(
        string $caseId,
        int $requestCount,
        int $assertions,
    ): void {
        $record = (new HermeticReportingHttpHarness())->runMalformedCase($caseId);

        self::assertSame([
            'case_id',
            'status',
            'request_count',
            'response_statuses',
            'response_codes',
            'action_calls',
            'actor_loads',
            'assertions',
        ], array_keys($record));
        self::assertSame($caseId, $record['case_id']);
        self::assertSame($requestCount, $record['request_count']);
        $legacy = $caseId === 'legacy_routes_absent';
        $idempotencyCase = str_contains($caseId, '_retry_idempotency_key');
        self::assertSame([
            array_fill(0, $requestCount, $legacy ? 404 : 422),
            array_fill(0, $requestCount, $legacy ? null : ($idempotencyCase ? 'REPORT_IDEMPOTENCY_KEY_INVALID' : 'REPORT_REQUEST_INVALID')),
            0,
            0,
        ], [
            $record['response_statuses'],
            $record['response_codes'],
            $record['action_calls'],
            $record['actor_loads'],
        ]);
        self::assertSame($assertions, $record['assertions']);
        self::assertSame($legacy ? 404 : 422, $record['status']);
    }

    public static function cases(): iterable
    {
        yield ['invalid_run_show_ulid', 1, 6];
        yield ['invalid_run_rows_ulid', 1, 6];
        yield ['invalid_run_drill_down_ulid', 1, 6];
        yield ['invalid_run_retry_ulid', 1, 6];
        yield ['missing_run_retry_idempotency_key', 1, 6];
        yield ['invalid_run_retry_idempotency_key', 1, 6];
        yield ['invalid_run_cancel_ulid', 1, 6];
        yield ['invalid_export_create_run_ulid', 1, 6];
        yield ['invalid_export_show_ulid', 1, 6];
        yield ['invalid_export_retry_ulid', 1, 6];
        yield ['missing_export_retry_idempotency_key', 1, 6];
        yield ['invalid_export_retry_idempotency_key', 1, 6];
        yield ['invalid_export_cancel_ulid', 1, 6];
        yield ['invalid_export_download_ulid', 1, 6];
        yield ['missing_run_as_of', 1, 6];
        yield ['rows_limit_101', 1, 6];
        yield ['missing_drill_down_token', 1, 6];
        yield ['invalid_export_format', 1, 6];
        yield ['unexpected_download_body', 1, 6];
        yield ['legacy_routes_absent', 19, 6];
    }
}
