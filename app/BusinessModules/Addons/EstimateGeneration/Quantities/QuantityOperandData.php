<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use Brick\Math\BigDecimal;

final readonly class QuantityOperandData
{
    /** @param array<int, string> $evidenceIds @param array<int, string> $assumptions */
    public function __construct(
        public BigDecimal $value,
        public string $unit,
        public QuantitySource $source,
        public array $evidenceIds,
        public string $contextId,
        public array $assumptions,
        public string $provenanceVersion,
        public string $role,
        public string $compatibilityGroup,
    ) {}

    /** @return array{role: string, value: string, unit: string, source: string, evidence_ids: array<int, string>, assumptions: array<int, string>, context_id: string, provenance_version: string} */
    public function toFormulaOperand(): array
    {
        return [
            'role' => $this->role, 'value' => (string) $this->value, 'unit' => $this->unit,
            'source' => $this->source->value, 'evidence_ids' => $this->evidenceIds,
            'assumptions' => $this->assumptions, 'context_id' => $this->contextId,
            'provenance_version' => $this->provenanceVersion,
        ];
    }

    /** @param array<string, mixed> $record */
    public static function fromRecord(
        array $record,
        string $field,
        string $unit,
        string $modelVersion,
        bool $scaleConfirmed,
        string $role,
        string $compatibilityGroup,
    ): self {
        if (! in_array($unit, ['m', 'm2', 'm3', 'count'], true)) {
            throw new \InvalidArgumentException('unit');
        }
        $raw = $record[$field] ?? null;
        $typed = is_array($raw);
        if (! $scaleConfirmed && ! $typed) {
            throw new \InvalidArgumentException('scale');
        }
        $payload = $typed ? $raw : $record;
        $value = $typed ? ($raw['value'] ?? null) : $raw;
        if (! is_string($value) && ! is_int($value)) {
            throw new \InvalidArgumentException('value');
        }
        try {
            $decimal = BigDecimal::of($value);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('value');
        }
        if ($decimal->isLessThanOrEqualTo(0)) {
            throw new \InvalidArgumentException('value');
        }
        if ($typed && ($payload['unit'] ?? null) !== $unit) {
            throw new \InvalidArgumentException('unit');
        }
        $sourceValue = $payload['source'] ?? null;
        if ($sourceValue === null && ! $typed) {
            $sourceValue = ($payload['assumptions'] ?? []) === [] ? 'evidenced' : 'estimated';
        }
        if (! in_array($sourceValue, ['evidenced', 'estimated'], true)) {
            throw new \InvalidArgumentException('source');
        }
        if (! $scaleConfirmed && $sourceValue === 'evidenced' && ($payload['metric_independent'] ?? false) !== true) {
            throw new \InvalidArgumentException('metric_independent');
        }
        $rawEvidence = is_array($payload['evidence_ids'] ?? null)
            ? array_map(static fn (mixed $value): string => trim((string) $value), $payload['evidence_ids']) : [];
        if (count($rawEvidence) !== count(array_unique($rawEvidence))) {
            throw new \InvalidArgumentException('duplicate_evidence');
        }
        $evidence = self::strings($payload['evidence_ids'] ?? []);
        $assumptions = self::strings($payload['assumptions'] ?? []);
        if ($sourceValue === 'evidenced' && $evidence === []) {
            throw new \InvalidArgumentException('evidence');
        }
        if ($sourceValue === 'estimated' && $assumptions === []) {
            throw new \InvalidArgumentException('assumption');
        }
        $context = $payload['context'] ?? null;
        $contextId = $typed ? (is_array($context) ? trim((string) ($context['id'] ?? '')) : '') : 'model:'.$modelVersion;
        if ($contextId === '') {
            throw new \InvalidArgumentException('context');
        }
        $version = $typed ? trim((string) ($payload['provenance_version'] ?? '')) : $modelVersion;
        if ($version === '') {
            throw new \InvalidArgumentException('provenance_version');
        }

        return new self($decimal, $unit, QuantitySource::from($sourceValue), $evidence, $contextId, $assumptions, $version, $role, $compatibilityGroup);
    }

    /** @return array<int, string> */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $result = array_values(array_unique(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $values))));
        sort($result, SORT_STRING);

        return $result;
    }
}
