<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Monitoring;

use Carbon\CarbonImmutable;

final readonly class DashboardFilters
{
    public const MAX_DAYS = 93;

    public const MAX_CURRENCY_SERIES = 4;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public ?int $organizationId,
        public ?int $projectId,
        public ?string $provider,
        public ?string $model,
        public ?string $stage,
        public ?string $status,
        public ?string $documentType,
        public ?string $mode,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now('UTC');
        $from = self::date($input['date_from'] ?? null, $now->subDays(29)->startOfDay());
        $dateTo = self::date($input['date_to'] ?? null, $now->startOfDay());
        $until = $dateTo->addDay();
        if ($from >= $until) {
            $from = $until->subDays(30);
        }
        if ($from->diffInDays($until) > self::MAX_DAYS) {
            $from = $until->subDays(self::MAX_DAYS);
        }

        return new self(
            $from,
            $until,
            self::positiveInt($input['organization_id'] ?? null),
            self::positiveInt($input['project_id'] ?? null),
            self::text($input['provider'] ?? null),
            self::text($input['model'] ?? null),
            self::text($input['stage'] ?? null),
            self::text($input['status'] ?? null),
            self::text($input['document_type'] ?? null),
            self::text($input['mode'] ?? null),
        );
    }

    private static function date(mixed $value, CarbonImmutable $default): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return CarbonImmutable::parse($value, 'UTC')->startOfDay();
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
