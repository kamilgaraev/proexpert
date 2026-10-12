<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractMoneySpelling
{
    private const SMALL = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять', 'десять',
        'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
    private const TENS = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
    private const HUNDREDS = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];
    private const SCALES = [[], ['тысяча', 'тысячи', 'тысяч'], ['миллион', 'миллиона', 'миллионов'],
        ['миллиард', 'миллиарда', 'миллиардов'], ['триллион', 'триллиона', 'триллионов'], ['квадриллион', 'квадриллиона', 'квадриллионов'],
        ['квинтиллион', 'квинтиллиона', 'квинтиллионов'], ['секстиллион', 'секстиллиона', 'секстиллионов']];
    public const CURRENCIES = ['RUB', 'USD', 'EUR'];

    public static function amount(string $amount, string $currency, string $minorStyle = 'digits'): string
    {
        if (!in_array($currency, self::CURRENCIES, true)) throw new ContractBuilderException('contracts.presentation_currency_unsupported', 422);
        if (preg_match('/^(-?)(\d{1,24})(?:\.(\d{1,12}))?$/D', $amount, $parts) !== 1) throw new ContractBuilderException('contracts.presentation_amount_invalid', 422);
        $fraction = $parts[3] ?? '';
        if (trim(substr($fraction, 2), '0') !== '') throw new ContractBuilderException('contracts.presentation_amount_precision', 422);
        $integer = ltrim($parts[2], '0') ?: '0';
        $minor = str_pad(substr($fraction, 0, 2), 2, '0');
        [$majorForms, $minorForms, $female] = match ($currency) {
            'RUB' => [['рубль', 'рубля', 'рублей'], ['копейка', 'копейки', 'копеек'], true],
            'USD' => [['доллар США', 'доллара США', 'долларов США'], ['цент', 'цента', 'центов'], false],
            'EUR' => [['евро', 'евро', 'евро'], ['евроцент', 'евроцента', 'евроцентов'], false],
        };
        $negative = $parts[1] === '-' && ($integer !== '0' || $minor !== '00');
        $text = ($negative ? 'минус ' : '').self::integer($integer).' '.self::form($integer, $majorForms).' '
            .($minorStyle === 'words' ? self::integer($minor, $female) : $minor).' '.self::form($minor, $minorForms);
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    private static function integer(string $digits, bool $female = false): string
    {
        $digits = ltrim($digits, '0') ?: '0';
        if ($digits === '0') return 'ноль';
        $chunks = array_reverse(str_split(str_pad($digits, (int) (ceil(strlen($digits) / 3) * 3), '0', STR_PAD_LEFT), 3));
        $words = [];
        foreach ($chunks as $level => $chunk) {
            $number = (int) $chunk;
            if ($number === 0) continue;
            $triad = [];
            if ($number >= 100) $triad[] = self::HUNDREDS[intdiv($number, 100)];
            $rest = $number % 100;
            $unitFemale = $level === 1 || ($level === 0 && $female);
            if ($rest > 0 && $rest < 20) $triad[] = $unitFemale && $rest === 1 ? 'одна' : ($unitFemale && $rest === 2 ? 'две' : self::SMALL[$rest]);
            elseif ($rest >= 20) {
                $triad[] = self::TENS[intdiv($rest, 10)];
                $unit = $rest % 10;
                if ($unit > 0) $triad[] = $unitFemale && $unit === 1 ? 'одна' : ($unitFemale && $unit === 2 ? 'две' : self::SMALL[$unit]);
            }
            if ($level > 0) $triad[] = self::form($chunk, self::SCALES[$level]);
            array_unshift($words, implode(' ', $triad));
        }
        return implode(' ', $words);
    }

    private static function form(string $digits, array $forms): string
    {
        $last = (int) substr($digits, -2);
        if ($last >= 11 && $last <= 19) return $forms[2];
        return match ($last % 10) { 1 => $forms[0], 2, 3, 4 => $forms[1], default => $forms[2] };
    }
}
