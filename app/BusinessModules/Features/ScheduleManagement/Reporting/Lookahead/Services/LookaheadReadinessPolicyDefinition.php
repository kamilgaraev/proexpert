<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LookaheadReadinessPolicyDefinition
{
    public function __construct(
        public int $organizationId,
        public ?int $projectId,
        public int $horizonDays,
        public array $eligibleTaskStatuses,
        public array $mandatoryConstraintTypes,
        public array $hardSeverities,
        public bool $waiverEvidenceRequired,
        public DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveUntil,
        public string $timezone,
    ) {
        if ($organizationId < 1
            || ($projectId !== null && $projectId < 1)
            || $horizonDays < 1
            || ! array_is_list($eligibleTaskStatuses)
            || ! array_is_list($mandatoryConstraintTypes)
            || ! array_is_list($hardSeverities)
            || $eligibleTaskStatuses === []
            || $mandatoryConstraintTypes === []
            || $hardSeverities === []
            || ($effectiveUntil !== null && $effectiveUntil < $effectiveFrom)
            || trim($timezone) === ''
            || ! $this->validList($eligibleTaskStatuses)
            || ! $this->validList($mandatoryConstraintTypes)
            || ! $this->validList($hardSeverities)
        ) {
            throw new InvalidArgumentException('lookahead_policy_definition_invalid');
        }
    }

    public static function default(
        int $organizationId,
        DateTimeImmutable $effectiveFrom,
    ): self {
        return new self(
            organizationId: $organizationId,
            projectId: null,
            horizonDays: 30,
            eligibleTaskStatuses: ['not_started', 'in_progress', 'waiting', 'on_hold'],
            mandatoryConstraintTypes: [
                'access_blocked',
                'customer_decision',
                'design_question',
                'executive_doc_missing',
                'labor_missing',
                'machinery_missing',
                'material_missing',
                'other',
                'procurement',
                'quality_blocker',
                'rfi',
                'safety_permit_missing',
                'weather_risk',
            ],
            hardSeverities: ['critical', 'hard'],
            waiverEvidenceRequired: true,
            effectiveFrom: $effectiveFrom,
            effectiveUntil: null,
            timezone: 'UTC',
        );
    }

    public function canonicalIdentity(): array
    {
        return [
            'effective_from' => $this->effectiveFrom->format(DATE_ATOM),
            'effective_until' => $this->effectiveUntil?->format(DATE_ATOM),
            'eligible_task_statuses' => $this->normalized($this->eligibleTaskStatuses),
            'hard_severities' => $this->normalized($this->hardSeverities),
            'horizon_days' => $this->horizonDays,
            'mandatory_constraint_types' => $this->normalized($this->mandatoryConstraintTypes),
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'timezone' => $this->timezone,
            'waiver_evidence_required' => $this->waiverEvidenceRequired,
        ];
    }

    public function sourceHash(): string
    {
        return hash('sha256', CanonicalJson::encode($this->canonicalIdentity()));
    }

    private function validList(array $values): bool
    {
        return count($values) === count(array_unique($values, SORT_STRING))
            && array_filter(
                $values,
                static fn (mixed $value): bool => ! is_string($value) || trim($value) === '',
            ) === [];
    }

    private function normalized(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }
}
