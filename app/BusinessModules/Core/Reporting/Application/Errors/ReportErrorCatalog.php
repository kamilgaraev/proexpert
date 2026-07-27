<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Errors;

final class ReportErrorCatalog
{
    public static function descriptor(ReportErrorCode $code): ReportErrorDescriptor
    {
        return match ($code) {
            ReportErrorCode::REPORT_NOT_FOUND => new ReportErrorDescriptor($code, 404, false, 'reports.errors.report_not_found'),
            ReportErrorCode::REPORT_SCOPE_FORBIDDEN => new ReportErrorDescriptor($code, 403, false, 'reports.errors.report_scope_forbidden'),
            ReportErrorCode::REPORT_REQUEST_INVALID => new ReportErrorDescriptor($code, 422, false, 'reports.errors.report_request_invalid'),
            ReportErrorCode::REPORT_FILTER_UNSUPPORTED => new ReportErrorDescriptor($code, 422, false, 'reports.errors.report_filter_unsupported'),
            ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND => new ReportErrorDescriptor($code, 422, false, 'reports.errors.report_filter_value_not_found'),
            ReportErrorCode::REPORT_FILTER_RANGE_INVALID => new ReportErrorDescriptor($code, 422, false, 'reports.errors.report_filter_range_invalid'),
            ReportErrorCode::REPORT_SORT_UNSUPPORTED => new ReportErrorDescriptor($code, 422, false, 'reports.errors.report_sort_unsupported'),
            ReportErrorCode::REPORT_CURSOR_INVALID => new ReportErrorDescriptor($code, 422, false, 'reports.errors.report_cursor_invalid'),
            ReportErrorCode::REPORT_IDEMPOTENCY_KEY_INVALID => new ReportErrorDescriptor($code, 422, false, 'reports.errors.report_idempotency_key_invalid'),
            ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT => new ReportErrorDescriptor($code, 409, false, 'reports.errors.report_idempotency_conflict'),
            ReportErrorCode::REPORT_SNAPSHOT_NOT_READY => new ReportErrorDescriptor($code, 409, true, 'reports.errors.report_snapshot_not_ready'),
            ReportErrorCode::REPORT_EXPORT_NOT_READY => new ReportErrorDescriptor($code, 409, true, 'reports.errors.report_export_not_ready'),
            ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED => new ReportErrorDescriptor($code, 409, false, 'reports.errors.report_official_snapshot_unsealed'),
            ReportErrorCode::REPORT_SNAPSHOT_EXPIRED => new ReportErrorDescriptor($code, 410, false, 'reports.errors.report_snapshot_expired'),
            ReportErrorCode::REPORT_EXPORT_EXPIRED => new ReportErrorDescriptor($code, 410, false, 'reports.errors.report_export_expired'),
            ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED => new ReportErrorDescriptor($code, 413, false, 'reports.errors.report_export_limit_exceeded'),
            ReportErrorCode::REPORT_RATE_LIMITED => new ReportErrorDescriptor($code, 429, true, 'reports.errors.report_rate_limited'),
            ReportErrorCode::REPORT_SOURCE_UNAVAILABLE => new ReportErrorDescriptor($code, 503, true, 'reports.errors.report_source_unavailable'),
            ReportErrorCode::REPORT_DEPENDENCY_FAILED => new ReportErrorDescriptor($code, 503, true, 'reports.errors.report_dependency_failed'),
            ReportErrorCode::REPORT_INTERNAL_ERROR => new ReportErrorDescriptor($code, 500, true, 'reports.errors.report_internal_error'),
        };
    }
}
