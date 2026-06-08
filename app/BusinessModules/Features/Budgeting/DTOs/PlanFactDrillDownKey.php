<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use InvalidArgumentException;
use function trans_message;

final readonly class PlanFactDrillDownKey
{
    public function __construct(
        public array $groupBy,
        public array $dimensions,
    ) {
    }

    public static function encode(array $groupBy, array $dimensions): string
    {
        $payload = json_encode([
            'group_by' => array_values($groupBy),
            'dimensions' => $dimensions,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    public static function decode(string $key): self
    {
        $payload = base64_decode(strtr($key, '-_', '+/'), true);

        if ($payload === false) {
            throw new InvalidArgumentException(trans_message('budgeting.plan_fact.errors.drill_down_key_invalid'));
        }

        $decoded = json_decode($payload, true);

        if (!is_array($decoded) || !is_array($decoded['group_by'] ?? null) || !is_array($decoded['dimensions'] ?? null)) {
            throw new InvalidArgumentException(trans_message('budgeting.plan_fact.errors.drill_down_key_invalid'));
        }

        return new self(
            groupBy: array_values(array_filter($decoded['group_by'], 'is_string')),
            dimensions: $decoded['dimensions'],
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
