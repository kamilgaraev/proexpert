<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** An elapsed-time interval. Legacy clock values belong to work_date in timezone. */
final class WorkTimeInterval
{
    private function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly string $timezone,
        public readonly float $breakMinutes,
        public readonly int $breakMicroseconds,
        public readonly array $breakIntervals = [],
        public readonly bool $requiresReconciliation = false,
        public readonly bool $timezoneRequiresReconciliation = false,
    ) {}

    public static function fromInput(array $data, ?string $defaultTimezone = null): self
    {
        $timezoneRequiresReconciliation = array_key_exists('timezone_requires_reconciliation', $data)
            ? (bool) $data['timezone_requires_reconciliation'] : empty($data['timezone']);
        $zone = new DateTimeZone((string) ($data['timezone'] ?? $defaultTimezone ?? 'UTC'));
        $start = self::boundary($data['start_at'] ?? null, $data['work_date'] ?? null, $data['start_time'] ?? null, $zone);
        $end = self::boundary($data['end_at'] ?? null, $data['end_date'] ?? $data['work_date'] ?? null, $data['end_time'] ?? null, $zone);
        if (empty($data['end_at']) && empty($data['end_date']) && $end < $start) {
            $end = $end->modify('+1 day');
        }
        if (! empty($data['work_date']) && $start->setTimezone($zone)->format('Y-m-d') !== substr((string) $data['work_date'], 0, 10)) {
            throw new InvalidArgumentException('work_date');
        }
        $declaredBreakMinutes = array_key_exists('break_minutes', $data)
            ? (float) $data['break_minutes'] : (float) ($data['break_time'] ?? 0) * 60;
        if (! is_finite($declaredBreakMinutes) || $declaredBreakMinutes < 0) {
            throw new InvalidArgumentException('duration');
        }
        $breakMicroseconds = (int) round($declaredBreakMinutes * 60_000_000);
        $breakIntervals = [];
        foreach ($data['break_intervals'] ?? [] as $interval) {
            $breakStart = self::boundary($interval['start_at'] ?? null, null, null, $zone);
            $breakEnd = self::boundary($interval['end_at'] ?? null, null, null, $zone);
            if ($breakStart < $start || $breakEnd > $end || $breakEnd <= $breakStart) {
                throw new InvalidArgumentException('break_intervals');
            }
            $breakIntervals[] = ['start' => $breakStart, 'end' => $breakEnd];
        }
        usort($breakIntervals, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $previous = null;
        $explicitBreakMicroseconds = 0;
        foreach ($breakIntervals as $interval) {
            if ($previous !== null && $interval['start'] < $previous) {
                throw new InvalidArgumentException('break_intervals');
            }
            $explicitBreakMicroseconds += self::timestampMicroseconds($interval['end']) - self::timestampMicroseconds($interval['start']);
            $previous = $interval['end'];
        }
        if ($breakIntervals !== []) {
            // Legacy break_time is stored with two decimal hours; explicit timestamps preserve the exact break.
            $tolerance = array_key_exists('break_minutes', $data) ? 1 : 18_000_001;
            if ((array_key_exists('break_time', $data) || array_key_exists('break_minutes', $data)) && abs($breakMicroseconds - $explicitBreakMicroseconds) > $tolerance) {
                throw new InvalidArgumentException('break_intervals');
            }
            $breakMicroseconds = $explicitBreakMicroseconds;
        }
        $grossMicroseconds = self::timestampMicroseconds($end) - self::timestampMicroseconds($start);
        if ($grossMicroseconds <= $breakMicroseconds) {
            throw new InvalidArgumentException('duration');
        }

        return new self($start->setTimezone(new DateTimeZone('UTC')), $end->setTimezone(new DateTimeZone('UTC')), $zone->getName(), $breakMicroseconds / 60_000_000, $breakMicroseconds, $breakIntervals, ($breakMicroseconds > 0 && $breakIntervals === []) || $timezoneRequiresReconciliation, $timezoneRequiresReconciliation);
    }

    public static function normalizeStart(array $data, ?string $defaultTimezone = null): array
    {
        $timezoneRequiresReconciliation = array_key_exists('timezone_requires_reconciliation', $data)
            ? (bool) $data['timezone_requires_reconciliation'] : empty($data['timezone']);
        $zone = new DateTimeZone((string) ($data['timezone'] ?? $defaultTimezone ?? 'UTC'));
        $start = self::boundary($data['start_at'] ?? null, $data['work_date'] ?? null, $data['start_time'] ?? null, $zone);
        if (! empty($data['work_date']) && $start->setTimezone($zone)->format('Y-m-d') !== substr((string) $data['work_date'], 0, 10)) {
            throw new InvalidArgumentException('work_date');
        }

        return ['start_at' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.uP'), 'timezone' => $zone->getName(), 'timezone_requires_reconciliation' => $timezoneRequiresReconciliation, 'work_date' => $start->setTimezone($zone)->format('Y-m-d'), 'start_time' => $start->setTimezone($zone)->format('H:i')];
    }

    private static function boundary(mixed $timestamp, mixed $date, mixed $clock, DateTimeZone $zone): DateTimeImmutable
    {
        if (is_string($timestamp) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(?:\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})?$/D', $timestamp)) {
            $value = new DateTimeImmutable($timestamp, $zone);
        } elseif (is_string($date) && is_string($clock)) {
            $text = substr($date, 0, 10).' '.substr($clock, 0, 5);
            $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $text, $zone);
            if (! $value || $value->format('Y-m-d H:i') !== $text) {
                throw new InvalidArgumentException('time');
            }
        } else {
            throw new InvalidArgumentException('time');
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException('time');
        }

        $hasExplicitOffset = is_string($timestamp) && preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $timestamp) === 1;
        if (! $hasExplicitOffset) {
            if (is_string($timestamp) && $value->format('Y-m-d H:i') !== str_replace('T', ' ', substr($timestamp, 0, 16))) {
                throw new InvalidArgumentException('time');
            }
            $wallClock = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
            $transitions = $zone->getTransitions($value->getTimestamp() - 86400, $value->getTimestamp() + 86400);
            $previousOffset = null;
            foreach ($transitions ?: [] as $transition) {
                if ($previousOffset !== null && $transition['offset'] < $previousOffset
                    && $wallClock->getTimestamp() >= $transition['ts'] + $transition['offset']
                    && $wallClock->getTimestamp() < $transition['ts'] + $previousOffset) {
                    throw new InvalidArgumentException('ambiguous_local_time');
                }
                $previousOffset = $transition['offset'];
            }
        }

        return $value;
    }

    private static function timestampMicroseconds(DateTimeImmutable $value): int
    {
        return (int) $value->format('U') * 1_000_000 + (int) $value->format('u');
    }

    public function netMicroseconds(): int
    {
        return self::timestampMicroseconds($this->end) - self::timestampMicroseconds($this->start) - $this->breakMicroseconds;
    }

    public function netMinutes(): float
    {
        return $this->netMicroseconds() / 60_000_000;
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function persistence(): array
    {
        $zone = new DateTimeZone($this->timezone);

        return [
            'start_at' => $this->start->format('Y-m-d\\TH:i:s.uP'),
            'end_at' => $this->end->format('Y-m-d\\TH:i:s.uP'),
            'timezone' => $this->timezone,
            'timezone_requires_reconciliation' => $this->timezoneRequiresReconciliation,
            'work_date' => $this->start->setTimezone($zone)->format('Y-m-d'),
            'start_time' => $this->start->setTimezone($zone)->format('H:i'),
            'end_time' => $this->end->setTimezone($zone)->format('H:i'),
            'break_time' => $this->breakMinutes / 60,
            'net_minutes' => $this->netMinutes(),
            'net_microseconds' => $this->netMicroseconds(),
            'break_intervals' => array_map(static fn (array $interval): array => ['start_at' => $interval['start']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'), 'end_at' => $interval['end']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z')], $this->breakIntervals),
            'hours_worked' => $this->netMinutes() / 60,
        ];
    }

    /** Measured daily minutes; unknown break placement cannot produce a legal daily fact. */
    public function splitByLocalDate(): array
    {
        if ($this->requiresReconciliation) {
            throw new InvalidArgumentException($this->timezoneRequiresReconciliation ? 'timezone_unknown' : 'break_placement_unknown');
        }
        $microsecondsByDate = [];
        foreach ($this->segments() as $segment) {
            $date = $segment['date'];
            $microsecondsByDate[$date] = ($microsecondsByDate[$date] ?? 0) + $segment['microseconds'];
        }

        return array_map(static fn (int $microseconds): float => $microseconds / 60_000_000, $microsecondsByDate);
    }

    /** Measured intervals split at midnight, 06:00 and 22:00, after subtracting explicit breaks. */
    public function segments(): array
    {
        if ($this->requiresReconciliation) {
            throw new InvalidArgumentException($this->timezoneRequiresReconciliation ? 'timezone_unknown' : 'break_placement_unknown');
        }
        $zone = new DateTimeZone($this->timezone);
        $cursor = $this->start->setTimezone($zone);
        $result = [];
        while ($cursor < $this->end) {
            $hour = (int) $cursor->format('H');
            $next = $hour < 6 ? $cursor->setTime(6, 0)
                : ($hour < 22 ? $cursor->setTime(22, 0) : $cursor->modify('tomorrow')->setTime(0, 0));
            if ($next > $this->end) {
                $next = $this->end->setTimezone($zone);
            }
            $microseconds = self::timestampMicroseconds($next) - self::timestampMicroseconds($cursor);
            foreach ($this->breakIntervals as $break) {
                $left = max(self::timestampMicroseconds($cursor), self::timestampMicroseconds($break['start']));
                $right = min(self::timestampMicroseconds($next), self::timestampMicroseconds($break['end']));
                $microseconds -= max(0, $right - $left);
            }
            $result[] = ['date' => $cursor->format('Y-m-d'), 'night' => $hour < 6 || $hour >= 22,
                'minutes' => (float) $microseconds / 60_000_000, 'microseconds' => $microseconds, 'start_at' => $cursor, 'end_at' => $next];
            $cursor = $next;
        }

        return $result;
    }
}
