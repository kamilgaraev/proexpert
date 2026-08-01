<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Audit;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditIntentStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use DateTimeImmutable;
use InvalidArgumentException;

final class OutboxReportTransitionAudit implements ReportTransitionAudit
{
    private const SUBJECT_KEYS = [
        'report.run.queued' => ['run_id', 'report_code', 'status', 'definition_hash', 'query_hash', 'contract_version', 'formula_version', 'source_schema_version', 'renderer_version', 'saved_view'],
        'report.run.materializing' => ['run_id', 'report_code', 'status', 'definition_hash', 'query_hash'],
        'report.run.ready' => ['run_id', 'report_code', 'status', 'definition_hash', 'query_hash', 'source_hash', 'result_hash', 'snapshot', 'data_classification', 'row_count', 'contract_version', 'formula_version', 'source_schema_version', 'renderer_version'],
        'report.run.failed' => ['run_id', 'report_code', 'status', 'definition_hash', 'query_hash', 'error_code'],
        'report.run.cancelled' => ['run_id', 'report_code', 'status', 'definition_hash', 'query_hash'],
        'report.run.expired' => ['run_id', 'report_code', 'status', 'definition_hash', 'query_hash', 'source_hash', 'result_hash', 'snapshot_id', 'expired_at'],
        'report.export.queued' => ['export_id', 'run_id', 'report_code', 'status', 'definition_hash', 'query_hash', 'source_hash', 'result_hash', 'snapshot_id', 'snapshot_classification', 'data_classification', 'format', 'columns', 'locale', 'timezone', 'renderer_version'],
        'report.export.running' => ['export_id', 'run_id', 'report_code', 'status', 'format'],
        'report.export.uploading' => ['export_id', 'run_id', 'report_code', 'status', 'format'],
        'report.export.ready' => ['export_id', 'run_id', 'report_code', 'status', 'definition_hash', 'query_hash', 'source_hash', 'result_hash', 'snapshot_id', 'format', 'renderer_version', 'row_count', 'artifact'],
        'report.export.failed' => ['export_id', 'run_id', 'report_code', 'status', 'format', 'error_code'],
        'report.export.cancelled' => ['export_id', 'run_id', 'report_code', 'status', 'format'],
        'report.export.expired' => ['export_id', 'run_id', 'report_code', 'status', 'format', 'version_id', 'occurred_at'],
        'report.export.artifact_deleted' => ['export_id', 'run_id', 'report_code', 'status', 'format', 'version_id', 'occurred_at'],
    ];

    private const FORBIDDEN_KEYS = [
        'rows', 'cells', 'filters', 'query', 'query_json', 'object_path', 'url', 'credentials',
        'token', 'signature', 'key_id', 'authorization', 'transport', 'exception', 'exception_text',
    ];

    public function __construct(private readonly ReportAuditIntentStore $store) {}

    public function append(
        string $eventId,
        string $eventType,
        ReportExecutionContext $context,
        array $subject,
        DateTimeImmutable $occurredAt,
    ): void {
        if ($eventId === '' || strlen($eventId) > 512 || !isset(self::SUBJECT_KEYS[$eventType])) {
            throw new InvalidArgumentException('report_audit_event_invalid');
        }

        $this->assertClosedObject($subject, self::SUBJECT_KEYS[$eventType]);
        $this->assertSubjectValues($eventType, $subject);
        $this->store->add($eventId, $eventType, $context, $subject, $occurredAt);
    }

    private function assertSubjectValues(string $eventType, array $subject): void
    {
        $expectedStatus = match ($eventType) {
            'report.export.artifact_deleted' => 'expired',
            default => substr($eventType, strrpos($eventType, '.') + 1),
        };
        if (($subject['status'] ?? null) !== $expectedStatus) {
            throw new InvalidArgumentException('report_audit_subject_status_invalid');
        }

        foreach ($subject as $key => $value) {
            if (in_array($key, self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException('report_audit_subject_forbidden');
            }
            if (in_array($key, ['run_id', 'export_id'], true) && !$this->isUlid($value)) {
                throw new InvalidArgumentException('report_audit_subject_id_invalid');
            }
            if (str_ends_with($key, '_hash') && !$this->isHash($value)) {
                throw new InvalidArgumentException('report_audit_subject_hash_invalid');
            }
            if (in_array($key, ['report_code', 'snapshot_id', 'version_id', 'format', 'locale', 'timezone'], true) && !$this->isSafeString($value)) {
                throw new InvalidArgumentException('report_audit_subject_value_invalid');
            }
            if (str_ends_with($key, '_version') && !$this->isVersion($value)) {
                throw new InvalidArgumentException('report_audit_subject_version_invalid');
            }
            if (in_array($key, ['row_count', 'size'], true) && (!is_int($value) || $value < 0)) {
                throw new InvalidArgumentException('report_audit_subject_count_invalid');
            }
        }

        if (
            isset($subject['error_code'])
            && (!is_string($subject['error_code']) || ReportErrorCode::tryFrom($subject['error_code']) === null)
        ) {
            throw new InvalidArgumentException('report_audit_subject_error_invalid');
        }
        if (
            isset($subject['snapshot_classification'])
            && !in_array($subject['snapshot_classification'], ['operational', 'official'], true)
        ) {
            throw new InvalidArgumentException('report_audit_snapshot_classification_invalid');
        }
        if (
            isset($subject['data_classification'])
            && !in_array($subject['data_classification'], ['standard', 'sensitive'], true)
        ) {
            throw new InvalidArgumentException('report_audit_data_classification_invalid');
        }
        foreach (['expired_at', 'occurred_at'] as $instantKey) {
            if (isset($subject[$instantKey]) && !$this->isCanonicalInstant($subject[$instantKey])) {
                throw new InvalidArgumentException('report_audit_subject_instant_invalid');
            }
        }
        if (isset($subject['columns'])) {
            $this->assertColumns($subject['columns']);
        }
        if (array_key_exists('saved_view', $subject) && $subject['saved_view'] !== null) {
            if (!is_array($subject['saved_view'])) {
                throw new InvalidArgumentException('report_audit_saved_view_invalid');
            }
            $this->assertClosedObject($subject['saved_view'], ['id', 'revision', 'hash']);
            if (
                !$this->isUlid($subject['saved_view']['id'])
                || !is_int($subject['saved_view']['revision'])
                || $subject['saved_view']['revision'] < 1
                || !$this->isHash($subject['saved_view']['hash'])
            ) {
                throw new InvalidArgumentException('report_audit_saved_view_invalid');
            }
        }
        if (isset($subject['snapshot'])) {
            if (!is_array($subject['snapshot'])) {
                throw new InvalidArgumentException('report_audit_snapshot_invalid');
            }
            $this->assertClosedObject($subject['snapshot'], ['kind', 'id', 'classification', 'seal_digest']);
            if (
                !$this->isSafeString($subject['snapshot']['kind'])
                || !$this->isSafeString($subject['snapshot']['id'])
                || !in_array($subject['snapshot']['classification'], ['operational', 'official'], true)
                || ($subject['snapshot']['seal_digest'] !== null && !$this->isHash($subject['snapshot']['seal_digest']))
            ) {
                throw new InvalidArgumentException('report_audit_snapshot_invalid');
            }
        }
        if (isset($subject['artifact'])) {
            if (!is_array($subject['artifact'])) {
                throw new InvalidArgumentException('report_audit_artifact_invalid');
            }
            $this->assertClosedObject($subject['artifact'], ['version_id', 'etag', 'checksum', 'size', 'mime']);
            if (
                !$this->isSafeString($subject['artifact']['version_id'])
                || !$this->isSafeString($subject['artifact']['etag'])
                || !$this->isHash($subject['artifact']['checksum'])
                || !is_int($subject['artifact']['size'])
                || $subject['artifact']['size'] < 0
                || !$this->isSafeString($subject['artifact']['mime'])
            ) {
                throw new InvalidArgumentException('report_audit_artifact_invalid');
            }
        }

        $this->assertNoForbiddenRecursive($subject);
    }

    private function assertClosedObject(array $value, array $expectedKeys): void
    {
        if (array_is_list($value)) {
            throw new InvalidArgumentException('report_audit_subject_object_required');
        }
        $actual = array_keys($value);
        sort($actual);
        sort($expectedKeys);
        if ($actual !== $expectedKeys) {
            throw new InvalidArgumentException('report_audit_subject_shape_invalid');
        }
    }

    private function assertColumns(mixed $columns): void
    {
        if (!is_array($columns) || !array_is_list($columns)) {
            throw new InvalidArgumentException('report_audit_columns_invalid');
        }
        $normalized = [];
        foreach ($columns as $column) {
            if (!is_string($column) || preg_match('/\A[a-z][a-z0-9_.-]{0,127}\z/D', $column) !== 1) {
                throw new InvalidArgumentException('report_audit_columns_invalid');
            }
            $normalized[] = $column;
        }
        $sorted = array_values(array_unique($normalized));
        sort($sorted, SORT_STRING);
        if ($normalized !== $sorted) {
            throw new InvalidArgumentException('report_audit_columns_invalid');
        }
    }

    private function assertNoForbiddenRecursive(array $value): void
    {
        foreach ($value as $key => $member) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException('report_audit_subject_forbidden');
            }
            if (is_array($member)) {
                $this->assertNoForbiddenRecursive($member);
            } elseif (is_object($member) || is_resource($member)) {
                throw new InvalidArgumentException('report_audit_subject_value_invalid');
            }
        }
    }

    private function isUlid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/D', $value) === 1;
    }

    private function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private function isSafeString(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 255 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function isVersion(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9A-Za-z][0-9A-Za-z._-]{0,63}\z/D', $value) === 1;
    }

    private function isCanonicalInstant(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $value) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new \DateTimeZone('UTC'));

        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d\TH:i:s.u\Z') === $value;
    }
}
