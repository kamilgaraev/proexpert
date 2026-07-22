<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\DTO;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ProjectCommandCenterPeriod
{
    public function __construct(
        public string $preset,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
    ) {
        if (($from === null) !== ($to === null)) {
            throw new InvalidArgumentException('Period bounds must be provided together.');
        }

        if ($from !== null && $from->gt($to)) {
            throw new InvalidArgumentException('Period start must not be after its end.');
        }
    }

    public static function resolve(
        string $preset,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $projectStart,
        ?string $projectEnd,
        CarbonImmutable $asOf,
    ): self {
        return match ($preset) {
            'month' => new self('month', $asOf->startOfMonth(), $asOf->endOfMonth()),
            'quarter' => new self('quarter', $asOf->firstOfQuarter(), $asOf->lastOfQuarter()),
            'custom' => new self('custom', CarbonImmutable::parse((string) $dateFrom)->startOfDay(), CarbonImmutable::parse((string) $dateTo)->endOfDay()),
            'project' => self::project($projectStart, $projectEnd),
            default => throw new InvalidArgumentException('Unknown command-center period preset.'),
        };
    }

    public function hasRange(): bool
    {
        return $this->from !== null;
    }

    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'date_from' => $this->from?->toDateString(),
            'date_to' => $this->to?->toDateString(),
        ];
    }

    private static function project(?string $start, ?string $end): self
    {
        if ($start === null || $end === null) {
            return new self('project', null, null);
        }

        return new self('project', CarbonImmutable::parse($start)->startOfDay(), CarbonImmutable::parse($end)->endOfDay());
    }
}
