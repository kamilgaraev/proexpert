<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementCycleStage;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementTerminalReason;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ProcurementCyclePolicyDefinition
{
    public string $formulaVersion;

    public string $sourceSchemaVersion;

    public string $eventSchemaVersion;

    public string $calendarVersion;

    public DateTimeZone $timezoneObject;

    public function __construct(
        public int $organizationId,
        public ?int $projectId,
        public string $timezone,
        public array $weeklyWindows,
        public array $exceptions,
        public array $stageSlaSeconds,
        public int $totalSlaSeconds,
        public array $terminalCancellationPolicy,
        public DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveTo = null,
        string $formulaVersion = 'procurement-cycle.v1',
        string $sourceSchemaVersion = '1.0.0',
        string $eventSchemaVersion = ProcurementProcessTransition::EVENT_VERSION,
        string $calendarVersion = 'procurement-business-calendar.v1',
    ) {
        $this->formulaVersion = $formulaVersion;
        $this->sourceSchemaVersion = $sourceSchemaVersion;
        $this->eventSchemaVersion = $eventSchemaVersion;
        $this->calendarVersion = $calendarVersion;
        $this->timezoneObject = $this->createTimezone($timezone);
        $this->assertValid();
    }

    public function canonicalHash(): string
    {
        return hash('sha256', CanonicalJson::encode($this->canonicalPayload()));
    }

    public function calendarHash(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'calendar_version' => $this->calendarVersion,
            'timezone' => $this->timezone,
            'weekly_windows' => $this->weeklyWindows,
            'exceptions' => $this->exceptions,
        ]));
    }

    public function canonicalPayload(): array
    {
        $terminalCancellationPolicy = $this->terminalCancellationPolicy;
        sort($terminalCancellationPolicy, SORT_STRING);

        return [
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'formula_version' => $this->formulaVersion,
            'source_schema_version' => $this->sourceSchemaVersion,
            'event_schema_version' => $this->eventSchemaVersion,
            'calendar_version' => $this->calendarVersion,
            'calendar_hash' => $this->calendarHash(),
            'timezone' => $this->timezone,
            'weekly_windows' => $this->weeklyWindows,
            'exceptions' => $this->exceptions,
            'stage_sla_seconds' => $this->stageSlaSeconds,
            'total_sla_seconds' => $this->totalSlaSeconds,
            'terminal_cancellation_policy' => $terminalCancellationPolicy,
            'effective_from' => $this->utc($this->effectiveFrom),
            'effective_to' => $this->effectiveTo === null ? null : $this->utc($this->effectiveTo),
        ];
    }

    public function allowsTerminalReason(ProcurementTerminalReason $reason): bool
    {
        return in_array($reason->value, $this->terminalCancellationPolicy, true);
    }

    private function assertValid(): void
    {
        if ($this->organizationId < 1
            || ($this->projectId !== null && $this->projectId < 1)
            || $this->formulaVersion !== 'procurement-cycle.v1'
            || $this->sourceSchemaVersion !== '1.0.0'
            || $this->eventSchemaVersion !== ProcurementProcessTransition::EVENT_VERSION
            || $this->calendarVersion !== 'procurement-business-calendar.v1'
            || $this->totalSlaSeconds < 1
            || ($this->effectiveTo !== null && $this->effectiveTo <= $this->effectiveFrom)) {
            throw new InvalidArgumentException('procurement_cycle_policy_invalid');
        }

        $requiredStages = array_map(
            static fn (ProcurementCycleStage $stage): string => $stage->value,
            ProcurementCycleStage::cases(),
        );
        $actualStages = array_keys($this->stageSlaSeconds);
        sort($actualStages, SORT_STRING);
        sort($requiredStages, SORT_STRING);
        if ($actualStages !== $requiredStages) {
            throw new InvalidArgumentException('procurement_cycle_policy_stage_sla_invalid');
        }
        foreach ($this->stageSlaSeconds as $seconds) {
            if (! is_int($seconds) || $seconds < 1) {
                throw new InvalidArgumentException('procurement_cycle_policy_stage_sla_invalid');
            }
        }

        foreach ($this->weeklyWindows as $weekday => $windows) {
            if (! is_int($weekday) || $weekday < 1 || $weekday > 7) {
                throw new InvalidArgumentException('procurement_cycle_policy_weekday_invalid');
            }
            $this->assertWindows($windows);
        }
        foreach ($this->exceptions as $date => $windows) {
            if (! is_string($date) || ! $this->isDate($date)) {
                throw new InvalidArgumentException('procurement_cycle_policy_exception_invalid');
            }
            $this->assertWindows($windows);
        }

        $allowedCancellationPolicies = ['order_cancelled', 'request_cancelled', 'request_rejected'];
        if ($this->terminalCancellationPolicy === []
            || array_values(array_unique($this->terminalCancellationPolicy)) !== $this->terminalCancellationPolicy
            || array_diff($this->terminalCancellationPolicy, $allowedCancellationPolicies) !== []) {
            throw new InvalidArgumentException('procurement_cycle_policy_cancellation_invalid');
        }
    }

    private function assertWindows(mixed $windows): void
    {
        if (! is_array($windows) || ! array_is_list($windows)) {
            throw new InvalidArgumentException('procurement_cycle_policy_windows_invalid');
        }

        $previousEnd = null;
        foreach ($windows as $window) {
            if (! is_array($window)
                || array_keys($window) !== [0, 1]
                || ! is_string($window[0])
                || ! is_string($window[1])
                || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $window[0]) !== 1
                || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $window[1]) !== 1
                || $window[0] >= $window[1]
                || ($previousEnd !== null && $window[0] < $previousEnd)) {
                throw new InvalidArgumentException('procurement_cycle_policy_windows_invalid');
            }
            $previousEnd = $window[1];
        }
    }

    private function createTimezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('procurement_cycle_policy_timezone_invalid', 0, $exception);
        }
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    private function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
