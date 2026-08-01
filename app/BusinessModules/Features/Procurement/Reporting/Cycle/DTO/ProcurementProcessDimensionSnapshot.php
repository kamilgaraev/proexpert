<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ProcurementProcessDimensionSnapshot
{
    public const SCHEMA_VERSION = 'procurement-process-dimensions.v1';

    private const REQUIRED_IDENTIFIERS = [
        'organization_id',
        'purchase_request_id',
        'purchase_request_line_id',
    ];

    private const NULLABLE_INTEGER_KEYS = [
        'project_id',
        'requester_id',
        'buyer_id',
        'material_id',
        'material_category_id',
        'supplier_party_id',
        'awarded_supplier_party_id',
        'policy_version_id',
    ];

    private const NULLABLE_STRING_KEYS = [
        'request_number',
        'material_name',
        'material_category_name',
        'priority',
        'quantity',
        'unit',
        'needed_by',
        'awarded_amount',
        'currency',
        'quality_status',
        'policy_hash',
        'calendar_version',
        'calendar_hash',
    ];

    private const ALLOWED_KEYS = [
        'schema_version',
        'organization_id',
        'project_id',
        'purchase_request_id',
        'purchase_request_line_id',
        'request_number',
        'requester_id',
        'buyer_id',
        'material_id',
        'material_name',
        'material_category_id',
        'material_category_name',
        'priority',
        'quantity',
        'unit',
        'needed_by',
        'supplier_party_id',
        'awarded_supplier_party_id',
        'awarded_amount',
        'currency',
        'policy_version_id',
        'policy_hash',
        'calendar_version',
        'calendar_hash',
        'quality_status',
        'gap_codes',
    ];

    private const FORBIDDEN_KEY_PARTS = [
        'address',
        'audit_payload',
        'comment',
        'email',
        'external_link',
        'notes',
        'password',
        'public_url',
        'secret',
        'supplier_snapshot',
        'token',
        'url',
    ];

    private function __construct(public array $values)
    {
    }

    public static function fromArray(array $values): self
    {
        if (($values['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('procurement_process_dimension_schema_invalid');
        }

        foreach (array_keys($values) as $key) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_KEYS, true)) {
                throw new InvalidArgumentException('procurement_process_dimension_key_forbidden');
            }
        }

        foreach (self::REQUIRED_IDENTIFIERS as $key) {
            if (! is_int($values[$key] ?? null) || $values[$key] < 1) {
                throw new InvalidArgumentException('procurement_process_dimension_lineage_invalid');
            }
        }

        if (! array_key_exists('project_id', $values)) {
            throw new InvalidArgumentException('procurement_process_dimension_project_lineage_required');
        }

        foreach (self::NULLABLE_INTEGER_KEYS as $key) {
            if (array_key_exists($key, $values)
                && $values[$key] !== null
                && (! is_int($values[$key]) || $values[$key] < 1)) {
                throw new InvalidArgumentException('procurement_process_dimension_integer_invalid');
            }
        }
        foreach (self::NULLABLE_STRING_KEYS as $key) {
            if (array_key_exists($key, $values)
                && $values[$key] !== null
                && (! is_string($values[$key]) || trim($values[$key]) === '')) {
                throw new InvalidArgumentException('procurement_process_dimension_string_invalid');
            }
        }

        if (isset($values['quantity'])
            && preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/D', $values['quantity']) !== 1) {
            throw new InvalidArgumentException('procurement_process_dimension_quantity_invalid');
        }
        if (isset($values['awarded_amount'])
            && preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/D', $values['awarded_amount']) !== 1) {
            throw new InvalidArgumentException('procurement_process_dimension_amount_invalid');
        }

        $gapCodes = $values['gap_codes'] ?? [];
        if (! is_array($gapCodes)
            || ! array_is_list($gapCodes)
            || $gapCodes !== array_values(array_unique($gapCodes))) {
            throw new InvalidArgumentException('procurement_process_dimension_gaps_invalid');
        }
        foreach ($gapCodes as $gapCode) {
            if (! is_string($gapCode)
                || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $gapCode) !== 1) {
                throw new InvalidArgumentException('procurement_process_dimension_gaps_invalid');
            }
        }

        if (in_array('missing_request_created_event', $gapCodes, true)) {
            $requiredQuarantineGaps = [
                'missing_policy_version',
                'missing_project_lineage',
                'missing_request_created_event',
            ];
            $quarantineKeys = [
                'schema_version',
                'organization_id',
                'project_id',
                'purchase_request_id',
                'purchase_request_line_id',
                'quality_status',
                'gap_codes',
            ];
            if (($values['project_id'] ?? null) !== null
                || ($values['quality_status'] ?? null) !== 'PARTIAL'
                || array_diff($requiredQuarantineGaps, $gapCodes) !== []
                || array_diff(array_keys($values), $quarantineKeys) !== []) {
                throw new InvalidArgumentException('procurement_process_dimension_quarantine_invalid');
            }
        }

        if (($values['project_id'] ?? null) === null
            && (($values['quality_status'] ?? null) !== 'PARTIAL'
                || ! in_array('missing_project_lineage', $gapCodes, true))) {
            throw new InvalidArgumentException('procurement_process_dimension_project_gap_required');
        }
        if (isset($values['quality_status'])
            && ! in_array($values['quality_status'], ['FULL', 'PARTIAL'], true)) {
            throw new InvalidArgumentException('procurement_process_dimension_quality_invalid');
        }
        if (($values['quality_status'] ?? null) === 'PARTIAL' && $gapCodes === []) {
            throw new InvalidArgumentException('procurement_process_dimension_partial_gaps_required');
        }

        foreach (['policy_hash', 'calendar_hash'] as $hashKey) {
            if (isset($values[$hashKey])
                && preg_match('/^[a-f0-9]{64}$/D', $values[$hashKey]) !== 1) {
                throw new InvalidArgumentException('procurement_process_dimension_pin_hash_invalid');
            }
        }

        $hasPolicy = ($values['policy_version_id'] ?? null) !== null;
        $hasCompletePins = isset(
            $values['policy_hash'],
            $values['calendar_version'],
            $values['calendar_hash'],
        );
        if ($hasPolicy !== $hasCompletePins) {
            throw new InvalidArgumentException('procurement_process_dimension_policy_pins_incomplete');
        }
        if (! $hasPolicy
            && (($values['quality_status'] ?? null) !== 'PARTIAL'
                || ! in_array('missing_policy_version', $gapCodes, true))) {
            throw new InvalidArgumentException('procurement_process_dimension_policy_gap_required');
        }

        self::assertSafe($values);

        return new self($values);
    }

    public function canonicalHash(): string
    {
        return hash('sha256', CanonicalJson::encode($this->values));
    }

    private static function assertSafe(mixed $value, ?string $key = null): void
    {
        if (is_string($key)) {
            $normalizedKey = strtolower($key);
            foreach (self::FORBIDDEN_KEY_PARTS as $forbidden) {
                if (str_contains($normalizedKey, $forbidden)) {
                    throw new InvalidArgumentException('procurement_process_dimension_secret_forbidden');
                }
            }
        }

        if (is_string($value)) {
            if (preg_match('~(?:https?://|mailto:|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})~i', $value) === 1) {
                throw new InvalidArgumentException('procurement_process_dimension_secret_forbidden');
            }

            return;
        }

        if (is_object($value) || is_resource($value) || is_float($value)) {
            throw new InvalidArgumentException('procurement_process_dimension_value_invalid');
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $nestedKey => $nestedValue) {
            self::assertSafe($nestedValue, is_string($nestedKey) ? $nestedKey : null);
        }
    }
}
