<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractVariablePresentation
{
    public static function validate(string $type, mixed $settings): void
    {
        if (!is_array($settings) || array_diff(array_keys($settings), ['format', 'letter_case', 'prefix', 'suffix', 'minor_units']) !== []) self::invalid();
        $formats = ['default'];
        if ($type === 'text') $formats[] = 'organization_full';
        if ($type === 'money') array_push($formats, 'money_words', 'money_number_words');
        if (!in_array($settings['format'] ?? 'default', $formats, true)
            || !in_array($settings['letter_case'] ?? 'preserve', ['preserve', 'sentence', 'upper', 'lower'], true)
            || !in_array($settings['minor_units'] ?? 'digits', ['digits', 'words'], true)
            || (isset($settings['minor_units']) && $type !== 'money') || $type === 'table') self::invalid();
        foreach (['prefix', 'suffix'] as $key) {
            if (isset($settings[$key]) && (!is_string($settings[$key]) || mb_strlen($settings[$key]) > 1000)) self::invalid();
        }
        foreach ($settings as $value) if ($value === null) self::invalid();
    }

    public static function assertValue(array $definition, mixed $value): void
    {
        $settings = $definition['display']['presentation'] ?? [];
        if ($value !== null && in_array($settings['format'] ?? 'default', ['money_words', 'money_number_words'], true)) {
            ContractMoneySpelling::amount((string) $value['amount'], $value['currency'], $settings['minor_units'] ?? 'digits');
        }
    }

    public static function format(array $definition, mixed $value, string $formatted): string
    {
        $settings = $definition['display']['presentation'] ?? [];
        $format = $settings['format'] ?? 'default';
        if ($format === 'organization_full') {
            $forms = ['ООО' => 'Общество с ограниченной ответственностью', 'АО' => 'Акционерное общество',
                'ПАО' => 'Публичное акционерное общество', 'ОАО' => 'Открытое акционерное общество',
                'ЗАО' => 'Закрытое акционерное общество', 'ИП' => 'Индивидуальный предприниматель'];
            $formatted = preg_replace_callback('/^(\s*)(ООО|ПАО|ОАО|ЗАО|АО|ИП)(?=\s|[«"]|$)\s*/ui',
                static fn (array $match): string => $match[1].$forms[mb_strtoupper($match[2])].' ', $formatted, count: $expanded) ?? $formatted;
            if ($expanded > 0) $formatted = rtrim($formatted);
        } elseif (in_array($format, ['money_words', 'money_number_words'], true)) {
            $words = ContractMoneySpelling::amount((string) $value['amount'], $value['currency'], $settings['minor_units'] ?? 'digits');
            $formatted = $format === 'money_words' ? $words : $formatted.' ('.$words.')';
        }
        $formatted = match ($settings['letter_case'] ?? 'preserve') {
            'upper' => mb_strtoupper($formatted), 'lower' => mb_strtolower($formatted),
            'sentence' => mb_strtoupper(mb_substr($formatted, 0, 1)).mb_substr($formatted, 1), default => $formatted,
        };
        return ($settings['prefix'] ?? '').$formatted.($settings['suffix'] ?? '');
    }

    private static function invalid(): never
    {
        throw new ContractBuilderException('contracts.presentation_invalid', 422);
    }
}
