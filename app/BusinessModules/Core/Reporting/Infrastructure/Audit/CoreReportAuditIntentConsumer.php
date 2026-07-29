<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Audit;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntent;
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
        'report.export.expired' => ['export_id', 'run_id', 'report_code', 'status', 'format', 'version_id', 'occurred_at'],
        'report.export.artifact_deleted' => ['export_id', 'run_id', 'report_code', 'status', 'format', 'version_id', 'occurred_at'],
    ];

    private const FORBIDDEN_KEYS = [
        'rows', 'cells', 'filters', 'query', 'query_json', 'object_path', 'url',
        'credentials', 'token', 'signature', 'key_id', 'authorization',
        'transport', 'exception', 'exception_text',
    ];

    public function __construct(private ImmutableAuditRecorder $recorder) {}

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
            || $intent->attemptCount > 12
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
        $expectedStatus = $intent->eventType === 'report.export.artifact_deleted'
            ? 'expired'
            : substr($intent->eventType, (int) strrpos($intent->eventType, '.') + 1);
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
