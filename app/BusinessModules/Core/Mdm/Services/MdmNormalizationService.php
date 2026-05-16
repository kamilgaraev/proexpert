<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

class MdmNormalizationService
{
    public function normalizeRecord(string $entityType, array $attributes): array
    {
        $normalized = [
            'name' => $this->normalizeText($attributes['name'] ?? null),
            'code' => $this->normalizeCode($attributes['code'] ?? null),
            'external_code' => $this->normalizeCode($attributes['external_code'] ?? null),
            'inn' => $this->normalizeDigits($attributes['inn'] ?? $attributes['tax_number'] ?? null),
            'tax_number' => $this->normalizeDigits($attributes['tax_number'] ?? $attributes['inn'] ?? null),
            'kpp' => $this->normalizeDigits($attributes['kpp'] ?? null),
            'ogrn' => $this->normalizeDigits($attributes['ogrn'] ?? null),
            'email' => $this->normalizeEmail($attributes['email'] ?? null),
            'phone' => $this->normalizePhone($attributes['phone'] ?? null),
            'short_name' => $this->normalizeText($attributes['short_name'] ?? null),
            'type' => $this->normalizeCode($attributes['type'] ?? null),
            'measurement_unit_id' => $this->normalizeInteger($attributes['measurement_unit_id'] ?? null),
            'work_type_id' => $this->normalizeInteger($attributes['work_type_id'] ?? null),
            'parent_id' => $this->normalizeInteger($attributes['parent_id'] ?? null),
        ];

        $normalized['normalized_key'] = $this->buildKey($entityType, $normalized);
        $normalized['fingerprint'] = hash('sha256', $entityType . '|' . ($normalized['normalized_key'] ?? ''));

        return array_filter($normalized, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $text = str_replace('ё', 'е', mb_strtolower($text));
        $text = preg_replace('/\s+/u', ' ', $text);

        return $text === null ? null : trim($text);
    }

    public function normalizeCode(mixed $value): ?string
    {
        $text = $this->normalizeText($value);

        return $text === null ? null : preg_replace('/\s+/u', '', $text);
    }

    public function normalizeDigits(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    public function normalizeEmail(mixed $value): ?string
    {
        $email = $this->normalizeCode($value);

        return $email === null ? null : mb_strtolower($email);
    }

    public function normalizePhone(mixed $value): ?string
    {
        $phone = $this->normalizeDigits($value);

        if ($phone === null) {
            return null;
        }

        if (strlen($phone) === 10) {
            return '7' . $phone;
        }

        if (strlen($phone) === 11 && str_starts_with($phone, '8')) {
            return '7' . substr($phone, 1);
        }

        return $phone;
    }

    private function normalizeInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function buildKey(string $entityType, array $normalized): ?string
    {
        if (in_array($entityType, ['contractor', 'supplier'], true)) {
            if (!empty($normalized['inn'])) {
                return $entityType . ':' . $normalized['inn'] . ':' . ($normalized['kpp'] ?? '');
            }

            if (!empty($normalized['email'])) {
                return $entityType . ':email:' . $normalized['email'];
            }
        }

        if (in_array($entityType, ['material', 'work_type', 'estimate_position'], true)) {
            if (!empty($normalized['code'])) {
                return $entityType . ':' . $normalized['code'] . ':' . ($normalized['measurement_unit_id'] ?? '');
            }
        }

        if ($entityType === 'measurement_unit' && !empty($normalized['short_name'])) {
            return $entityType . ':' . $normalized['short_name'] . ':' . ($normalized['type'] ?? '');
        }

        if ($entityType === 'cost_category' && !empty($normalized['code'])) {
            return $entityType . ':' . $normalized['code'] . ':' . ($normalized['parent_id'] ?? '');
        }

        if (!empty($normalized['name'])) {
            return $entityType . ':name:' . $normalized['name'] . ':' . ($normalized['parent_id'] ?? '');
        }

        return null;
    }
}
