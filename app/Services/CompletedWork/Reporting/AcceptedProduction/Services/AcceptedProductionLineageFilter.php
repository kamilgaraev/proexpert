<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final readonly class AcceptedProductionLineageFilter
{
    private const AS_OF_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        public string $asOf,
        public array $statuses,
        public array $contractorIds,
        public array $unitCodes,
        public array $zones,
        public ?string $periodFrom,
        public ?string $periodTo,
        public string $timezone,
    ) {
        $this->assertValid();
    }

    public static function fromQuery(ReportQuery $query): self
    {
        $values = $query->filters->values;
        $from = $values['period_from'] ?? null;
        $to = $values['period_to'] ?? null;

        return new self(
            asOf: $query->asOf->format(self::AS_OF_FORMAT),
            statuses: self::stringList($values['statuses'] ?? []),
            contractorIds: self::integerList($values['contractor_ids'] ?? []),
            unitCodes: self::stringList($values['unit_codes'] ?? []),
            zones: self::stringList($values['zones'] ?? []),
            periodFrom: self::nullableDate($from),
            periodTo: self::nullableDate($to),
            timezone: $query->scope->timezone->getName(),
        );
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== [
            'as_of',
            'contractor_ids',
            'period_from',
            'period_to',
            'statuses',
            'timezone',
            'unit_codes',
            'zones',
        ]) {
            throw new InvalidArgumentException('accepted_production_lineage_filter_invalid');
        }

        return new self(
            asOf: is_string($value['as_of'])
                ? $value['as_of']
                : throw new InvalidArgumentException('accepted_production_lineage_filter_invalid'),
            statuses: self::stringList($value['statuses']),
            contractorIds: self::integerList($value['contractor_ids']),
            unitCodes: self::stringList($value['unit_codes']),
            zones: self::stringList($value['zones']),
            periodFrom: self::nullableDate($value['period_from']),
            periodTo: self::nullableDate($value['period_to']),
            timezone: is_string($value['timezone'])
                ? $value['timezone']
                : throw new InvalidArgumentException('accepted_production_lineage_filter_invalid'),
        );
    }

    public function applyTo(Builder $builder): void
    {
        $builder
            ->where('recognized_at', '<=', $this->asOf)
            ->when(
                $this->statuses !== [],
                fn (Builder $query): Builder => $query->whereIn('event_type', $this->statuses),
            )
            ->when(
                $this->contractorIds !== [],
                fn (Builder $query): Builder => $query->whereIn('contractor_id', $this->contractorIds),
            )
            ->when(
                $this->unitCodes !== [],
                fn (Builder $query): Builder => $query->whereIn('unit_code', $this->unitCodes),
            )
            ->when(
                $this->zones !== [],
                fn (Builder $query): Builder => $query->whereIn('zone', $this->zones),
            )
            ->when(
                $this->periodFrom !== null,
                fn (Builder $query): Builder => $query->whereRaw(
                    '(recognized_at AT TIME ZONE ?)::date >= ?',
                    [$this->timezone, $this->periodFrom],
                ),
            )
            ->when(
                $this->periodTo !== null,
                fn (Builder $query): Builder => $query->whereRaw(
                    '(recognized_at AT TIME ZONE ?)::date <= ?',
                    [$this->timezone, $this->periodTo],
                ),
            );
    }

    public function canonicalIdentity(): array
    {
        return [
            'as_of' => $this->asOf,
            'contractor_ids' => $this->contractorIds,
            'period_from' => $this->periodFrom,
            'period_to' => $this->periodTo,
            'statuses' => $this->statuses,
            'timezone' => $this->timezone,
            'unit_codes' => $this->unitCodes,
            'zones' => $this->zones,
        ];
    }

    private function assertValid(): void
    {
        $asOf = DateTimeImmutable::createFromFormat(self::AS_OF_FORMAT, $this->asOf);
        try {
            $timezone = new DateTimeZone($this->timezone);
        } catch (\Throwable) {
            throw new InvalidArgumentException('accepted_production_lineage_filter_invalid');
        }
        if ($asOf === false
            || $asOf->format(self::AS_OF_FORMAT) !== $this->asOf
            || $timezone->getName() !== $this->timezone
            || ($this->periodFrom !== null
                && $this->periodTo !== null
                && $this->periodFrom > $this->periodTo)
        ) {
            throw new InvalidArgumentException('accepted_production_lineage_filter_invalid');
        }
    }

    private static function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('accepted_production_lineage_filter_invalid');
        }
        $result = array_values(array_unique(array_map('strval', $value)));
        sort($result);

        return $result;
    }

    private static function integerList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('accepted_production_lineage_filter_invalid');
        }
        $result = array_values(array_unique(array_map('intval', $value)));
        sort($result);
        if (array_filter($result, static fn (int $id): bool => $id < 1) !== []) {
            throw new InvalidArgumentException('accepted_production_lineage_filter_invalid');
        }

        return $result;
    }

    private static function nullableDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1
            || DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException('accepted_production_lineage_filter_invalid');
        }

        return $value;
    }
}
