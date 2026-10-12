<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractDocumentLocale
{
    public const CODES = ['ru-RU', 'en-GB', 'en-US', 'de-DE', 'fr-FR'];

    public static function validate(mixed $locale): string
    {
        if (!is_string($locale) || !in_array($locale, self::CODES, true)) throw new ContractBuilderException('contracts.builder_locale_invalid', 422, [], 'document.attrs.locale');
        return $locale;
    }

    public static function decimal(string $value, bool $money, string $locale): string
    {
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $value, $parts)) return $value;
        $fraction = rtrim($parts[3] ?? '', '0');
        if ($money) $fraction = str_pad($fraction, 2, '0');
        $group = str_starts_with($locale, 'en') ? ',' : ($locale === 'de-DE' ? '.' : "\u{00A0}");
        $decimal = str_starts_with($locale, 'en') ? '.' : ',';
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', $group, $parts[2]);
        return $parts[1].$integer.($fraction !== '' ? $decimal.$fraction : '');
    }

    public static function date(string $value, string $locale): string
    {
        [$year, $month, $day] = explode('-', $value);
        return match ($locale) { 'en-US' => $month.'/'.$day.'/'.$year, 'en-GB', 'fr-FR' => $day.'/'.$month.'/'.$year, default => $day.'.'.$month.'.'.$year };
    }

    public static function boolean(bool $value, string $locale): string
    {
        return match ($locale) { 'de-DE' => $value ? 'Ja' : 'Nein', 'fr-FR' => $value ? 'Oui' : 'Non', 'en-US', 'en-GB' => $value ? 'Yes' : 'No', default => $value ? 'Да' : 'Нет' };
    }
}
