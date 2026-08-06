<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Audit;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntent;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExecutionRuntimeConfiguration;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final readonly class CoreReportAuditIntentConsumer
{
    private const SOURCE_EVENT_ID_MAX_LENGTH = 191;

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
        'report.export.expired' => ['export_id', 'run_id', 'report_code', 'status', 'format', 'storage_key', 'occurred_at'],
    ];

    private const FORBIDDEN_KEYS = [
        'rows', 'cells', 'filters', 'query', 'query_json', 'object_path', 'url',
        'credentials', 'token', 'signature', 'key_id', 'authorization',
        'transport', 'exception', 'exception_text',
    ];

    public function __construct(
        private ImmutableAuditRecorder $recorder,
        private ReportExecutionRuntimeConfiguration $runtime,
    ) {}

    public function append(ReportAuditIntent $intent): void
    {
        $event = $this->eventData($intent);

        $this->recorder->record($event);
    }

    private function eventData(ReportAuditIntent $intent): ImmutableAuditEventData
    {
        $subject = $this->validatedSubject($intent);
        $subjectType = array_key_exists('export_id', $subject)
            ? 'report_export'
            : 'report_run';
        $subjectId = $subject[$subjectType === 'report_export' ? 'export_id' : 'run_id'];
        $action = substr($intent->eventType, (int) strrpos($intent->eventType, '.') + 1);

        return new ImmutableAuditEventData(
            organizationId: $intent->organizationId,
            domain: 'reporting',
            eventType: $intent->eventType,
            action: $action,
            source: 'reporting',
            actorType: 'user',
            actorUserId: $intent->actorId,
            sourceEventId: $this->sourceEventId($intent->eventKey),
            subjectType: $subjectType,
            subjectId: $subjectId,
            domainContext: $subject,
            chainScope: "organization:{$intent->organizationId}:reporting",
            occurredAt: Carbon::instance($intent->occurredAt),
        );
    }

    private function sourceEventId(string $eventKey): string
    {
        if (strlen($eventKey) <= self::SOURCE_EVENT_ID_MAX_LENGTH) {
            return $eventKey;
        }

        return 'reporting:sha256:'.hash('sha256', $eventKey);
    }

    private function validatedSubject(ReportAuditIntent $intent): array
    {
        if (
            preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/D', $intent->id) !== 1
            || $intent->eventKey === ''
            || strlen($intent->eventKey) > 512
            || $intent->organizationId < 1
            || $intent->actorId < 1
            || $intent->attemptCount < 1
            || $intent->attemptCount > $this->runtime->auditMaxAttempts
            || ! isset(self::SUBJECT_KEYS[$intent->eventType])
            || array_is_list($intent->subject)
        ) {
            throw new InvalidArgumentException('report_core_audit_intent_invalid');
        }

        $actual = array_keys($intent->subject);
        $expected = self::SUBJECT_KEYS[$intent->eventType];
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('report_core_audit_subject_shape_invalid');
        }

        $this->assertNoForbiddenMembers($intent->subject);
        if (array_key_exists('snapshot', $intent->subject)) {
            $this->assertSnapshot($intent->subject['snapshot']);
        }
        if (array_key_exists('artifact', $intent->subject)) {
            $this->assertArtifact($intent->subject['artifact']);
        }
        if (array_key_exists('saved_view', $intent->subject)) {
            $this->assertSavedView($intent->subject['saved_view']);
        }
        if (array_key_exists('columns', $intent->subject)) {
            $this->assertColumns($intent->subject['columns']);
        }
        $expectedStatus = substr($intent->eventType, (int) strrpos($intent->eventType, '.') + 1);
        if (($intent->subject['status'] ?? null) !== $expectedStatus) {
            throw new InvalidArgumentException('report_core_audit_subject_status_invalid');
        }
        foreach (['run_id', 'export_id'] as $idKey) {
            if (
                array_key_exists($idKey, $intent->subject)
                && (! is_string($intent->subject[$idKey])
                    || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/D', $intent->subject[$idKey]) !== 1)
            ) {
                throw new InvalidArgumentException('report_core_audit_subject_id_invalid');
            }
        }
        foreach ($intent->subject as $key => $value) {
            if (str_ends_with((string) $key, '_hash')
                && (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1)) {
                throw new InvalidArgumentException('report_core_audit_subject_hash_invalid');
            }
        }
        if (isset($intent->subject['row_count'])
            && (! is_int($intent->subject['row_count']) || $intent->subject['row_count'] < 0)) {
            throw new InvalidArgumentException('report_core_audit_subject_count_invalid');
        }

        return $intent->subject;
    }

    private function assertSnapshot(mixed $snapshot): void
    {
        if (! is_array($snapshot) || array_is_list($snapshot)) {
            throw new InvalidArgumentException('report_core_audit_snapshot_invalid');
        }
        $this->assertExactKeys($snapshot, ['kind', 'id', 'classification', 'seal_digest']);
        if (
            ! is_string($snapshot['kind']) || $snapshot['kind'] === ''
            || ! is_string($snapshot['id']) || $snapshot['id'] === ''
            || ! is_string($snapshot['classification'])
            || ! in_array($snapshot['classification'], ['operational', 'official'], true)
            || ($snapshot['seal_digest'] !== null
                && (! is_string($snapshot['seal_digest'])
                    || preg_match('/\A[a-f0-9]{64}\z/D', $snapshot['seal_digest']) !== 1))
            || ($snapshot['classification'] === 'official' && $snapshot['seal_digest'] === null)
            || ($snapshot['classification'] === 'operational' && $snapshot['seal_digest'] !== null)
        ) {
            throw new InvalidArgumentException('report_core_audit_snapshot_invalid');
        }
    }

    private function assertArtifact(mixed $artifact): void
    {
        if (! is_array($artifact) || array_is_list($artifact)) {
            throw new InvalidArgumentException('report_core_audit_artifact_invalid');
        }
        $this->assertExactKeys($artifact, ['storage_key', 'etag', 'sha256', 'size_bytes', 'mime_type']);
        if (
            ! $this->boundedText($artifact['storage_key'], 1024)
            || ! $this->boundedText($artifact['etag'], 255)
            || ! is_string($artifact['sha256'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $artifact['sha256']) !== 1
            || ! is_int($artifact['size_bytes'])
            || $artifact['size_bytes'] < 1
            || ! $this->boundedText($artifact['mime_type'], 255)
        ) {
            throw new InvalidArgumentException('report_core_audit_artifact_invalid');
        }
    }

    private function assertSavedView(mixed $savedView): void
    {
        if ($savedView === null) {
            return;
        }
        if (! is_array($savedView) || array_is_list($savedView)) {
            throw new InvalidArgumentException('report_core_audit_saved_view_invalid');
        }
        $this->assertExactKeys($savedView, ['id', 'revision', 'hash']);
        if (
            ! is_string($savedView['id'])
            || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/D', $savedView['id']) !== 1
            || ! is_int($savedView['revision'])
            || $savedView['revision'] < 1
            || ! is_string($savedView['hash'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $savedView['hash']) !== 1
        ) {
            throw new InvalidArgumentException('report_core_audit_saved_view_invalid');
        }
    }

    private function assertColumns(mixed $columns): void
    {
        if (! is_array($columns) || ! array_is_list($columns)) {
            throw new InvalidArgumentException('report_core_audit_columns_invalid');
        }
        $normalized = [];
        foreach ($columns as $column) {
            if (! is_string($column) || preg_match('/\A[a-z][a-z0-9_.-]{0,127}\z/D', $column) !== 1) {
                throw new InvalidArgumentException('report_core_audit_columns_invalid');
            }
            $normalized[] = $column;
        }
        $canonical = array_values(array_unique($normalized));
        sort($canonical, SORT_STRING);
        if ($normalized !== $canonical) {
            throw new InvalidArgumentException('report_core_audit_columns_invalid');
        }
    }

    private function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('report_core_audit_nested_shape_invalid');
        }
    }

    private function boundedText(mixed $value, int $maxLength): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= $maxLength
            && preg_match('/[[:cntrl:]]/', $value) !== 1;
    }

    private function assertNoForbiddenMembers(array $value): void
    {
        foreach ($value as $key => $member) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException('report_core_audit_subject_forbidden');
            }
            if (is_array($member)) {
                $this->assertNoForbiddenMembers($member);
            } elseif (is_object($member) || is_resource($member)) {
                throw new InvalidArgumentException('report_core_audit_subject_value_invalid');
            }
        }
    }
}
