<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\HermeticReportingHttpHarness;

final class ReportingAuthorizationMatrixTest extends TestCase
{
    public function test_client_cannot_supply_authorization_target_or_scope_facts(): void
    {
        $forbidden = ['operation', 'definition_hash', 'snapshot_id', 'holding_organization_ids', 'allowed_project_ids', 'resources'];
        self::assertCount(6, array_unique($forbidden));
    }

    #[DataProvider('cases')]
    public function test_authorization_case_is_execution_derived(
        string $caseId,
        int $requestCount,
        array $statuses,
        array $codes,
        int $actionCalls,
        int $actorLoads,
        int $assertions,
    ): void {
        $record = (new HermeticReportingHttpHarness)->runAuthorizationCase($caseId);

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
        self::assertSame(
            [$statuses, $codes, $actionCalls, $actorLoads],
            [
                $record['response_statuses'],
                $record['response_codes'],
                $record['action_calls'],
                $record['actor_loads'],
            ],
        );
        self::assertSame($assertions, $record['assertions']);
        self::assertSame($statuses[array_key_last($statuses)], $record['status']);
    }

    public static function cases(): iterable
    {
        yield ['unauthenticated_catalog_denied', 1, [401], [null], 0, 0, 6];
        yield ['non_admin_catalog_denied', 1, [403], [null], 0, 0, 6];
        yield ['module_disabled_catalog_denied', 1, [403], ['REPORT_SCOPE_FORBIDDEN'], 0, 0, 6];
        yield ['missing_global_permission_catalog_denied', 1, [403], ['REPORT_SCOPE_FORBIDDEN'], 0, 1, 6];
        yield ['view_actor_catalog_allowed', 1, [200], [null], 1, 1, 6];
        yield ['view_actor_run_status_allowed', 1, [200], [null], 1, 1, 6];
        yield ['view_actor_rows_allowed', 1, [200], [null], 1, 1, 6];
        yield ['view_actor_run_create_denied', 1, [403], ['REPORT_SCOPE_FORBIDDEN'], 0, 1, 6];
        yield ['view_actor_export_create_denied', 1, [403], ['REPORT_SCOPE_FORBIDDEN'], 0, 1, 6];
        yield ['view_actor_download_denied', 1, [403], ['REPORT_SCOPE_FORBIDDEN'], 0, 1, 6];
        yield ['runner_run_create_allowed', 1, [201], [null], 1, 1, 6];
        yield ['runner_run_retry_allowed', 1, [202], [null], 1, 1, 6];
        yield ['runner_run_cancel_allowed', 1, [200], [null], 1, 1, 6];
        yield ['runner_export_denied', 1, [403], ['REPORT_SCOPE_FORBIDDEN'], 0, 1, 6];
        yield ['exporter_export_allowed', 1, [201], [null], 1, 1, 6];
        yield ['exporter_download_denied', 1, [403], ['REPORT_SCOPE_FORBIDDEN'], 0, 1, 6];
        yield ['downloader_revoked_definition_denied', 1, [403], ['REPORT_SCOPE_FORBIDDEN'], 0, 1, 6];
        yield ['manage_does_not_expand_operational_permissions', 3, [403, 403, 403], ['REPORT_SCOPE_FORBIDDEN', 'REPORT_SCOPE_FORBIDDEN', 'REPORT_SCOPE_FORBIDDEN'], 0, 3, 6];
        yield ['foreign_and_nonexistent_filter_indistinguishable', 2, [422, 422], ['REPORT_FILTER_VALUE_NOT_FOUND', 'REPORT_FILTER_VALUE_NOT_FOUND'], 2, 2, 6];
        yield ['foreign_and_nonexistent_source_indistinguishable', 2, [422, 422], ['REPORT_FILTER_VALUE_NOT_FOUND', 'REPORT_FILTER_VALUE_NOT_FOUND'], 2, 2, 6];
        yield ['blocked_actor_denied_after_context_reload', 2, [200, 403], [null, 'REPORT_SCOPE_FORBIDDEN'], 1, 2, 6];
        yield ['deleted_actor_denied_after_context_reload', 2, [200, 403], [null, 'REPORT_SCOPE_FORBIDDEN'], 1, 2, 6];
    }
}
