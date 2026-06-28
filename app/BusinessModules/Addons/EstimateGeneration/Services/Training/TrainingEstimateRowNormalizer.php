<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Training;

final class TrainingEstimateRowNormalizer
{
    private const RESOURCE_TYPES = [
        'material',
        'equipment',
        'machinery',
        'labor',
        'machinery_labor',
        'resource',
    ];

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        $qualityFlags = [];
        $workName = trim((string) ($row['item_name'] ?? $row['name'] ?? ''));
        $normCode = $this->normalizeNormCode((string) ($row['code'] ?? $row['normative_rate_code'] ?? ''));
        $unit = $this->nullableString($row['unit'] ?? null);
        $skipReason = $this->skipReason($row);

        if ($workName === '') {
            $qualityFlags[] = 'missing_work_name';
        }

        if ($normCode === '') {
            $qualityFlags[] = 'missing_norm_code';
        }

        if ($unit === null) {
            $qualityFlags[] = 'unit_unverified';
        }

        if ($skipReason !== null) {
            $qualityFlags[] = $skipReason;
        }

        $isValid = $skipReason === null && $workName !== '' && $normCode !== '';

        if ($isValid) {
            $qualityFlags[] = 'valid_training_row';
        }

        return [
            'row_number' => $this->positiveInt($row['row_number'] ?? null),
            'section_name' => $this->nullableString($row['section_name'] ?? $row['section_title'] ?? null),
            'section_path' => $this->nullableString($row['section_path'] ?? $row['section_number'] ?? null),
            'work_name' => $workName !== '' ? $workName : 'Позиция без названия',
            'work_unit' => $unit,
            'work_quantity' => $this->nullableFloat($row['quantity_total'] ?? $row['quantity'] ?? null),
            'norm_code' => $normCode !== '' ? $normCode : null,
            'status' => $isValid ? 'accepted' : 'skipped',
            'quality_score' => $isValid ? ($unit !== null ? 0.9 : 0.75) : 0.0,
            'quality_flags' => array_values(array_unique($qualityFlags)),
            'raw_payload' => $row,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function skipReason(array $row): ?string
    {
        if ((bool) ($row['is_section'] ?? false)) {
            return 'section_row';
        }

        if ((bool) ($row['is_footer'] ?? false)) {
            return 'footer_row';
        }

        $itemType = strtolower(trim((string) ($row['item_type'] ?? '')));

        if ((bool) ($row['is_sub_item'] ?? false) && in_array($itemType, self::RESOURCE_TYPES, true)) {
            return 'resource_child_row';
        }

        if (in_array($itemType, self::RESOURCE_TYPES, true) && $this->normalizeNormCode((string) ($row['code'] ?? '')) === '') {
            return 'resource_child_row';
        }

        return null;
    }

    private function normalizeNormCode(string $code): string
    {
        $code = trim($code);
        $code = preg_replace('/^[^\d]*/u', '', $code) ?? $code;
        $code = preg_replace('/\s+/u', '', $code) ?? $code;

        return trim($code);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (!is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }
}
