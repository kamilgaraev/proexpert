<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Services;

final class CrmTextNormalizer
{
    public function email(?string $value): ?string
    {
        $normalized = $this->text($value);

        return $normalized === null ? null : mb_strtolower($normalized);
    }

    public function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if ($digits === null || $digits === '') {
            return null;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            return '7' . substr($digits, 1);
        }

        return $digits;
    }

    public function inn(?string $value): ?string
    {
        $digits = $this->digits($value);

        if ($digits === null) {
            return null;
        }

        return in_array(strlen($digits), [10, 12], true) ? $digits : $digits;
    }

    public function domain(?string $value): ?string
    {
        $normalized = $this->text($value);

        if ($normalized === null) {
            return null;
        }

        $normalized = preg_replace('/^https?:\/\//i', '', mb_strtolower($normalized)) ?? $normalized;
        $normalized = preg_replace('/^www\./i', '', $normalized) ?? $normalized;

        return trim($normalized, " \t\n\r\0\x0B/");
    }

    public function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $normalized === '' ? null : $normalized;
    }

    public function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits === null || $digits === '' ? null : $digits;
    }

    public function contactPoint(string $type, ?string $value): ?string
    {
        return match ($type) {
            'email' => $this->email($value),
            'phone', 'whatsapp', 'telegram' => $this->phone($value),
            'website' => $this->domain($value),
            default => $this->text($value),
        };
    }
}
