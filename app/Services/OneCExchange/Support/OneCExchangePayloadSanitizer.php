<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\Support;

use Throwable;

final class OneCExchangePayloadSanitizer
{
    private const MASKED_VALUE = '[скрыто]';

    private const DROP_KEYS = [
        'payload',
        'raw_payload',
        'request_payload',
        'response_payload',
        'raw_request',
        'raw_response',
        'stack',
        'stack_trace',
        'trace',
        'exception',
        'sql',
    ];

    private const SENSITIVE_FRAGMENTS = [
        'token',
        'password',
        'secret',
        'api_key',
        'apikey',
        'authorization',
        'auth',
        'hash',
        'signature',
    ];

    /**
     * @return array<string, mixed>
     */
    public function preview(array $payload, int $maxDepth = 4): array
    {
        return $this->sanitizeArray($payload, 0, $maxDepth);
    }

    /**
     * @return array{code: string, message: string}
     */
    public function safeError(string $message, string $code = 'exchange_failed'): array
    {
        return [
            'code' => $code,
            'message' => $this->translateSafeError($code, $message),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $payload, int $depth, int $maxDepth): array
    {
        if ($depth >= $maxDepth) {
            return ['preview' => 'Данные свернуты для безопасного просмотра.'];
        }

        $result = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = $this->normalizeKey((string) $key);

            if (in_array($normalizedKey, self::DROP_KEYS, true)) {
                continue;
            }

            if ($this->isSensitiveKey($normalizedKey)) {
                $result[$key] = self::MASKED_VALUE;
                continue;
            }

            if ($this->isBankAccountKey($normalizedKey) && is_scalar($value)) {
                $result[$key] = $this->maskBankAccount((string) $value);
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->sanitizeArray($value, $depth + 1, $maxDepth);
                continue;
            }

            if (is_string($value) && $this->looksTechnical($value)) {
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['-', ' '], '_', $key));
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function isBankAccountKey(string $key): bool
    {
        return in_array($key, ['bank_account', 'account_number', 'settlement_account', 'checking_account'], true);
    }

    private function maskBankAccount(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) < 10) {
            return self::MASKED_VALUE;
        }

        return substr($digits, 0, 6) . '******' . substr($digits, -4);
    }

    private function looksTechnical(string $value): bool
    {
        $lower = strtolower($value);

        return str_contains($lower, 'sqlstate')
            || str_contains($lower, 'stack trace')
            || str_contains($lower, 'select *')
            || str_contains($lower, ' in /');
    }

    private function translateSafeError(string $code, string $fallback): string
    {
        $fallbacks = [
            'duplicate_delivery' => 'Операция уже была получена ранее.',
            'mapping_missing' => 'Не найдено сопоставление для учетной системы.',
            'timeout' => 'Учетная система не ответила за отведенное время.',
            'server_error' => 'Учетная система временно недоступна.',
            'business_validation' => 'Учетная система отклонила документ по бизнес-правилу.',
            'validation_error' => 'Данные не прошли проверку перед обменом.',
            'value_mismatch' => 'Значения МОСТ и 1C отличаются.',
            'accounting_conflict' => 'Учетная система вернула конфликт значений.',
            'source_outdated' => 'Исходные данные изменились после подготовки обмена.',
            'duplicate_mapping' => 'Найдены неоднозначные сопоставления с учетной системой.',
            'exchange_failed' => 'Не удалось выполнить обмен с 1C.',
        ];

        $translationKey = "one_c_exchange.safe_errors.{$code}";

        try {
            $translated = trans_message($translationKey);

            if ($translated !== $translationKey && trim($translated) !== '') {
                return $translated;
            }
        } catch (Throwable) {
        }

        return $fallbacks[$code] ?? $this->stripTechnicalDetails($fallback);
    }

    private function stripTechnicalDetails(string $message): string
    {
        if ($this->looksTechnical($message)) {
            return 'Не удалось выполнить обмен с 1C.';
        }

        return mb_substr(trim($message), 0, 240);
    }
}
