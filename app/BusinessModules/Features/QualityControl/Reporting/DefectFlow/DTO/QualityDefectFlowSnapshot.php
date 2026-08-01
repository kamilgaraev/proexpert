<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO;

use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectFlowCanonicalJson;
use InvalidArgumentException;

final readonly class QualityDefectFlowSnapshot
{
    public const SCHEMA_VERSION = 'quality-defect-flow-snapshot.v1';

    private const KEYS = [
        'schema_version',
        'organization_id',
        'project_id',
        'quality_defect_id',
        'contractor_id',
        'schedule_task_id',
        'severity',
        'due_date',
        'has_due_date',
        'inspection_required',
        'assignee_id',
        'source_link',
    ];

    private function __construct(public array $values) {}

    public static function fromArray(array $values): self
    {
        $unknown = array_diff(array_keys($values), self::KEYS);
        $missing = array_diff(self::KEYS, array_keys($values));
        if ($unknown !== [] || $missing !== []) {
            throw new InvalidArgumentException('quality_defect_flow_snapshot_fields_invalid');
        }
        if ($values['schema_version'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('quality_defect_flow_snapshot_version_invalid');
        }
        foreach (['organization_id', 'project_id', 'quality_defect_id'] as $key) {
            self::assertPositiveDecimalString($values[$key], $key);
        }
        foreach (['contractor_id', 'schedule_task_id', 'assignee_id'] as $key) {
            if ($values[$key] !== null) {
                self::assertPositiveDecimalString($values[$key], $key);
            }
        }
        if (! is_string($values['severity']) || ! in_array($values['severity'], ['minor', 'major', 'critical'], true)) {
            throw new InvalidArgumentException('quality_defect_flow_snapshot_severity_invalid');
        }
        if (! is_bool($values['has_due_date']) || ! is_bool($values['inspection_required'])) {
            throw new InvalidArgumentException('quality_defect_flow_snapshot_flags_invalid');
        }
        if (($values['due_date'] !== null) !== $values['has_due_date']) {
            throw new InvalidArgumentException('quality_defect_flow_snapshot_due_date_invalid');
        }
        if ($values['due_date'] !== null
            && (! is_string($values['due_date']) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $values['due_date']) !== 1)) {
            throw new InvalidArgumentException('quality_defect_flow_snapshot_due_date_invalid');
        }
        self::assertSourceLink($values['source_link']);

        return new self(QualityDefectFlowCanonicalJson::sort($values));
    }

    public function canonical(): array
    {
        return $this->values;
    }

    public function hash(): string
    {
        return QualityDefectFlowCanonicalJson::hash($this->values);
    }

    private static function assertPositiveDecimalString(mixed $value, string $key): void
    {
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException("quality_defect_flow_snapshot_{$key}_invalid");
        }
    }

    private static function assertSourceLink(mixed $sourceLink): void
    {
        if (! is_array($sourceLink) || ! is_string($sourceLink['classification'] ?? null)) {
            throw new InvalidArgumentException('quality_defect_flow_snapshot_source_link_invalid');
        }

        if (in_array($sourceLink['classification'], ['quality_defect', 'work_constraint'], true)) {
            if (array_keys($sourceLink) !== ['classification']) {
                throw new InvalidArgumentException('quality_defect_flow_snapshot_source_link_invalid');
            }

            return;
        }

        if ($sourceLink['classification'] !== 'acceptance_finding'
            || array_diff(array_keys($sourceLink), [
                'classification',
                'acceptance_scope_id',
                'acceptance_session_id',
            ]) !== []
            || array_diff(['classification', 'acceptance_scope_id', 'acceptance_session_id'], array_keys($sourceLink)) !== []) {
            throw new InvalidArgumentException('quality_defect_flow_snapshot_source_link_invalid');
        }

        self::assertPositiveDecimalString($sourceLink['acceptance_scope_id'], 'acceptance_scope_id');
        self::assertPositiveDecimalString($sourceLink['acceptance_session_id'], 'acceptance_session_id');
    }
}
