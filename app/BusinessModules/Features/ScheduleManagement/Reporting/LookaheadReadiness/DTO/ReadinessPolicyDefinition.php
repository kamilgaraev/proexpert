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
    }

    public static function v1(int $organizationId): self
    {
        return new self(
            organizationId: $organizationId,
            policyCode: 'lookahead-readiness.v1',
            semanticVersion: '1.0.0',
            revision: 1,
            definition: [
                'business_calendar' => [
                    'cutoff_local_time' => '18:00:00',
                    'dst_ambiguity' => 'reject',
                    'interval' => 'left_closed_right_open',
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
                            'design' => ['absence' => 'unknown', 'hard' => true, 'evidence_required' => true],
                            'materials' => ['absence' => 'unknown', 'hard' => false, 'evidence_required' => true],
                            'permit' => ['absence' => 'unknown', 'hard' => true, 'evidence_required' => true],
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
}
