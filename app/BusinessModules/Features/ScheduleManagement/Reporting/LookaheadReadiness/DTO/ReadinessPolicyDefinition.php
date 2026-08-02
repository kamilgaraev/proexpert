<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LookaheadReadinessCanonicalJson;
use InvalidArgumentException;

final readonly class ReadinessPolicyDefinition
{
    public function __construct(
        public int $organizationId,
        public string $policyCode,
        public string $semanticVersion,
        public int $revision,
        private array $definition,
    ) {
        if ($organizationId <= 0 || $revision <= 0 || $policyCode === '' || $semanticVersion === '') {
            throw new InvalidArgumentException('lookahead_readiness_policy_identity_invalid');
        }
        $this->assertValidDefinition($definition);
    }

    public static function v1(int $organizationId): self
    {
        return new self(
            organizationId: $organizationId,
            policyCode: 'lookahead-readiness.v1',
            semanticVersion: '1.0.0',
            revision: 1,
            definition: [
                'schema_version' => 1,
                'business_calendar' => [
                    'cutoff_local_time' => '18:00:00',
                    'dst_ambiguity' => 'reject',
                    'dst_gap' => 'reject',
                    'interval' => 'left_closed_right_open',
                    'start_date_inclusion' => 'committed_start_in_window',
                    'timezone_source' => 'commitment',
                ],
                'deterministic_order' => ['occurred_at_utc', 'event_id'],
                'evidence_types' => ['document', 'inspection', 'permit', 'system_fact'],
                'redaction_labels' => [
                    'blocker_description' => 'sensitive',
                    'blocker_title' => 'restricted',
                    'evidence_locator' => 'sensitive',
                    'owner_ref' => 'restricted',
                    'vendor_ref' => 'commercial',
                ],
                'task_classes' => [
                    'standard' => [
                        'required' => [
                            'design' => [
                                'absence' => 'unknown',
                                'allowed_evidence_types' => ['document', 'inspection', 'system_fact'],
                                'evidence_required' => true,
                                'expiry_threshold_hours' => 24,
                                'hard' => true,
                                'not_applicable' => false,
                            ],
                            'materials' => [
                                'absence' => 'unknown',
                                'allowed_evidence_types' => ['document', 'inspection', 'system_fact'],
                                'evidence_required' => true,
                                'expiry_threshold_hours' => 24,
                                'hard' => false,
                                'not_applicable' => false,
                            ],
                            'permit' => [
                                'absence' => 'unknown',
                                'allowed_evidence_types' => ['document', 'permit'],
                                'evidence_required' => true,
                                'expiry_threshold_hours' => 48,
                                'hard' => true,
                                'not_applicable' => false,
                            ],
                        ],
                    ],
                ],
                'waiver' => [
                    'allowed_categories' => ['design', 'materials', 'permit'],
                    'approver_permission' => 'schedule.readiness.waivers.approve',
                    'cross_schedule_revision' => false,
                    'max_validity_hours' => 168,
                    'reason_required' => true,
                    'evidence_required' => true,
                    'revalidation' => 'on_schedule_or_policy_revision',
                ],
            ],
        );
    }

    public static function fromCanonicalDefinition(array $canonical): self
    {
        $organizationId = filter_var($canonical['organization_id'] ?? null, FILTER_VALIDATE_INT);
        $revision = filter_var($canonical['revision'] ?? null, FILTER_VALIDATE_INT);
        $policyCode = $canonical['policy_code'] ?? null;
        $semanticVersion = $canonical['semantic_version'] ?? null;
        if (! is_int($organizationId)
            || ! is_int($revision)
            || ! is_string($policyCode)
            || ! is_string($semanticVersion)) {
            throw new InvalidArgumentException('lookahead_readiness_policy_identity_invalid');
        }
        unset(
            $canonical['organization_id'],
            $canonical['revision'],
            $canonical['policy_code'],
            $canonical['semantic_version'],
        );

        return new self($organizationId, $policyCode, $semanticVersion, $revision, $canonical);
    }

    public function canonicalDefinition(): array
    {
        return LookaheadReadinessCanonicalJson::sort([
            ...$this->definition,
            'organization_id' => (string) $this->organizationId,
            'policy_code' => $this->policyCode,
            'revision' => $this->revision,
            'semantic_version' => $this->semanticVersion,
        ]);
    }

    public function hash(): string
    {
        return LookaheadReadinessCanonicalJson::hash($this->canonicalDefinition());
    }

    public function intentHash(): string
    {
        return LookaheadReadinessCanonicalJson::hash([
            ...$this->definition,
            'organization_id' => (string) $this->organizationId,
            'policy_code' => $this->policyCode,
            'semantic_version' => $this->semanticVersion,
        ]);
    }

    public function withRevision(int $revision): self
    {
        return new self(
            $this->organizationId,
            $this->policyCode,
            $this->semanticVersion,
            $revision,
            $this->definition,
        );
    }

    public function requiredPrerequisites(string $taskClass): array
    {
        $required = $this->definition['task_classes'][$taskClass]['required'] ?? null;

        if (! is_array($required) || $required === []) {
            throw new InvalidArgumentException('lookahead_readiness_task_class_unknown');
        }

        return $required;
    }

    public function waiverPolicy(): array
    {
        return $this->definition['waiver'];
    }

    public function evidenceTypes(): array
    {
        return $this->definition['evidence_types'];
    }

    private function assertValidDefinition(array $definition): void
    {
        if (! $this->exactKeys($definition, [
            'business_calendar',
            'deterministic_order',
            'evidence_types',
            'redaction_labels',
            'schema_version',
            'task_classes',
            'waiver',
        ])
            || ($definition['schema_version'] ?? null) !== 1
            || ! is_array($definition['business_calendar'] ?? null)
            || ! $this->exactKeys($definition['business_calendar'], [
                'cutoff_local_time',
                'dst_ambiguity',
                'dst_gap',
                'interval',
                'start_date_inclusion',
                'timezone_source',
            ])
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $definition['business_calendar']['cutoff_local_time'] ?? '') !== 1
            || ! in_array($definition['business_calendar']['dst_ambiguity'] ?? null, ['reject', 'earlier', 'later'], true)
            || ! in_array($definition['business_calendar']['dst_gap'] ?? null, ['reject', 'next_valid'], true)
            || ($definition['business_calendar']['interval'] ?? null) !== 'left_closed_right_open'
            || ($definition['business_calendar']['start_date_inclusion'] ?? null) !== 'committed_start_in_window'
            || ($definition['business_calendar']['timezone_source'] ?? null) !== 'commitment'
            || ($definition['deterministic_order'] ?? null) !== ['occurred_at_utc', 'event_id']
            || ! $this->stringList($definition['evidence_types'] ?? null)
            || ! is_array($definition['redaction_labels'] ?? null)
            || ! is_array($definition['task_classes'] ?? null)
            || $definition['task_classes'] === []
            || ! is_array($definition['waiver'] ?? null)
            || ! $this->validWaiverSchema($definition['waiver'], $definition['evidence_types'])) {
            throw new InvalidArgumentException('lookahead_readiness_policy_schema_invalid');
        }

        foreach ($definition['task_classes'] as $taskClass => $classDefinition) {
            if (! is_string($taskClass)
                || $taskClass === ''
                || ! is_array($classDefinition)
                || ! $this->exactKeys($classDefinition, ['required'])
                || ! is_array($classDefinition['required'])
                || $classDefinition['required'] === []) {
                throw new InvalidArgumentException('lookahead_readiness_policy_schema_invalid');
            }
            foreach ($classDefinition['required'] as $category => $rule) {
                if (! is_string($category)
                    || $category === ''
                    || ! is_array($rule)
                    || ! $this->exactKeys($rule, [
                        'absence',
                        'allowed_evidence_types',
                        'evidence_required',
                        'expiry_threshold_hours',
                        'hard',
                        'not_applicable',
                    ])
                    || ! in_array($rule['absence'] ?? null, ['unknown', 'blocked', 'not_applicable'], true)
                    || ! is_bool($rule['evidence_required'] ?? null)
                    || ! is_bool($rule['hard'] ?? null)
                    || ! is_bool($rule['not_applicable'] ?? null)
                    || ! is_int($rule['expiry_threshold_hours'] ?? null)
                    || $rule['expiry_threshold_hours'] < 0
                    || ! $this->stringList($rule['allowed_evidence_types'] ?? null)
                    || array_diff($rule['allowed_evidence_types'], $definition['evidence_types']) !== []) {
                    throw new InvalidArgumentException('lookahead_readiness_policy_schema_invalid');
                }
            }
        }
    }

    private function validWaiverSchema(array $waiver, array $evidenceTypes): bool
    {
        return $this->exactKeys($waiver, [
            'allowed_categories',
            'approver_permission',
            'cross_schedule_revision',
            'evidence_required',
            'max_validity_hours',
            'reason_required',
            'revalidation',
        ])
            && $this->stringList($waiver['allowed_categories'] ?? null)
            && ($waiver['approver_permission'] ?? null) === 'schedule.readiness.waivers.approve'
            && ($waiver['cross_schedule_revision'] ?? null) === false
            && ($waiver['evidence_required'] ?? null) === true
            && is_int($waiver['max_validity_hours'] ?? null)
            && $waiver['max_validity_hours'] > 0
            && ($waiver['reason_required'] ?? null) === true
            && ($waiver['revalidation'] ?? null) === 'on_schedule_or_policy_revision'
            && $evidenceTypes !== [];
    }

    private function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function stringList(mixed $value): bool
    {
        return is_array($value)
            && $value !== []
            && array_is_list($value)
            && count(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== '')) === count($value)
            && count(array_unique($value)) === count($value);
    }
}
