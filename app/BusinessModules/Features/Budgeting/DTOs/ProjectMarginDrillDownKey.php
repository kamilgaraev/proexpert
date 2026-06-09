<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use InvalidArgumentException;

final readonly class ProjectMarginDrillDownKey
{
    public const INVALID_KEY_MESSAGE = 'budgeting.project_margin.errors.drill_down_key_invalid';

    public function __construct(
        public array $groupBy,
        public array $dimensions,
    ) {
    }

    public static function encode(array $groupBy, array $dimensions): string
    {
        $encoded = json_encode([
            'group_by' => array_values($groupBy),
            'dimensions' => $dimensions,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($encoded), '+/', '-_'), '=');
    }

    public static function decode(string $key): self
    {
        $decoded = base64_decode(strtr($key, '-_', '+/'), true);

        if ($decoded === false) {
            throw new InvalidArgumentException(self::INVALID_KEY_MESSAGE);
        }

        $data = json_decode($decoded, true);

        if (!is_array($data) || !is_array($data['group_by'] ?? null) || !is_array($data['dimensions'] ?? null)) {
            throw new InvalidArgumentException(self::INVALID_KEY_MESSAGE);
        }

        $month = $data['dimensions'][ProjectMarginReportFilters::GROUP_MONTH] ?? null;
        if ($month !== null) {
            $isValidMonth = is_string($month)
                && preg_match('/^\d{4}-\d{2}$/', $month) === 1
                && checkdate((int) mb_substr($month, 5, 2), 1, (int) mb_substr($month, 0, 4));

            if (!$isValidMonth) {
                throw new InvalidArgumentException(self::INVALID_KEY_MESSAGE);
            }
        }

        return new self(
            groupBy: array_values(array_filter($data['group_by'], 'is_string')),
            dimensions: $data['dimensions'],
        );
    }

    public function hasDimension(string $dimension): bool
    {
        return array_key_exists($dimension, $this->dimensions);
    }

    public function value(string $dimension): mixed
    {
        return $this->dimensions[$dimension] ?? null;
    }
}
