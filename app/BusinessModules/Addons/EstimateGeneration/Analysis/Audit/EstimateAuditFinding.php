<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use InvalidArgumentException;

final readonly class EstimateAuditFinding
{
    private const TYPES = [
        'omission', 'duplicate', 'invalid_unit', 'quantity_mismatch', 'coverage_gap',
        'suspicious_zero', 'companion_work_missing', 'unjustified_expense',
    ];

    public function __construct(
        public string $findingId,
        public string $type,
        public string $severity,
        public ?string $itemKey,
        public array $sourceFactIds,
        public array $sourceLocator,
        public string $reason,
        public string $impact,
        public string $recommendation,
        public array $correction,
    ) {}

    public static function fromArray(array $data): self
    {
        $expected = [
            'finding_id', 'type', 'severity', 'item_key', 'source_fact_ids', 'source_locator',
            'reason', 'impact', 'recommendation', 'correction',
        ];
        $actual = array_keys($data);
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('estimate_audit_finding_shape_invalid');
        }
        if (! is_string($data['finding_id']) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $data['finding_id']) !== 1
            || ! is_string($data['type']) || ! in_array($data['type'], self::TYPES, true)) {
            throw new InvalidArgumentException('estimate_audit_finding_type_invalid');
        }
        if (! in_array($data['severity'], ['material', 'advisory'], true)
            || ($data['item_key'] !== null && (! is_string($data['item_key']) || trim($data['item_key']) === ''))
            || ! is_array($data['source_fact_ids']) || ! array_is_list($data['source_fact_ids'])
            || $data['source_fact_ids'] === [] || count($data['source_fact_ids']) > 256
            || ! is_array($data['source_locator']) || $data['source_locator'] === []
            || strlen(json_encode($data['source_locator'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 8192
            || ! is_array($data['correction'])) {
            throw new InvalidArgumentException('estimate_audit_finding_invalid');
        }
        foreach ($data['source_fact_ids'] as $factId) {
            if (! is_string($factId) || trim($factId) === '' || strlen($factId) > 160) {
                throw new InvalidArgumentException('estimate_audit_finding_invalid');
            }
        }
        foreach (['reason', 'impact', 'recommendation'] as $field) {
            $text = is_string($data[$field]) ? trim($data[$field]) : '';
            if (mb_strlen($text) < 12 || mb_strlen($text) > 1000
                || preg_match('/\p{Cyrillic}/u', $text) !== 1
                || preg_match('/^нужно уточнить[.!]?$/iu', $text) === 1) {
                throw new InvalidArgumentException('estimate_audit_finding_invalid');
            }
        }
        self::validateCorrection($data['correction']);

        return new self(
            $data['finding_id'], $data['type'], $data['severity'], $data['item_key'],
            array_values(array_unique($data['source_fact_ids'])), $data['source_locator'],
            trim($data['reason']), trim($data['impact']), trim($data['recommendation']), $data['correction'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'finding_id' => $this->findingId,
            'type' => $this->type,
            'severity' => $this->severity,
            'item_key' => $this->itemKey,
            'source_fact_ids' => $this->sourceFactIds,
            'source_locator' => $this->sourceLocator,
            'reason' => $this->reason,
            'impact' => $this->impact,
            'recommendation' => $this->recommendation,
            'correction' => $this->correction,
        ];
    }

    private static function validateCorrection(array $correction): void
    {
        $operation = $correction['operation'] ?? null;
        if ($operation === 'operator_review' && array_keys($correction) === ['operation']) {
            return;
        }
        $keys = array_keys($correction);
        sort($keys);
        $expected = ['expected_retained_fingerprint', 'expected_target_fingerprint', 'operation', 'retained_item_key', 'target_item_key'];
        sort($expected);
        if ($operation !== 'remove_exact_duplicate' || $keys !== $expected) {
            throw new InvalidArgumentException('estimate_audit_correction_invalid');
        }
        foreach (['target_item_key', 'retained_item_key'] as $key) {
            if (! is_string($correction[$key]) || trim($correction[$key]) === '' || strlen($correction[$key]) > 160) {
                throw new InvalidArgumentException('estimate_audit_correction_invalid');
            }
        }
        foreach (['expected_target_fingerprint', 'expected_retained_fingerprint'] as $key) {
            if (! is_string($correction[$key]) || preg_match('/^[a-f0-9]{64}$/D', $correction[$key]) !== 1) {
                throw new InvalidArgumentException('estimate_audit_correction_invalid');
            }
        }
    }
}
