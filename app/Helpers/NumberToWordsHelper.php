<?php

namespace App\Helpers;

class NumberToWordsHelper
{
    /**
     * Склонения для числовых разрядов
     */
    private static $morph = [
        'units' => [
            ['', '', ''],
            ['тысяча', 'тысячи', 'тысяч'],
            ['миллион', 'миллиона', 'миллионов'],
            ['миллиард', 'миллиарда', 'миллиардов'],
            ['триллион', 'триллиона', 'триллионов'],
        ],
        'currency' => [
            ['рубль', 'рубля', 'рублей'],
            ['копейка', 'копейки', 'копеек'],
        ],
    ];

    /**
     * Числа прописью
     */
    private static $numbers = [
        0 => 'ноль',
        1 => 'один',
        2 => 'два',
        3 => 'три',
        4 => 'четыре',
        5 => 'пять',
        6 => 'шесть',
        7 => 'семь',
        8 => 'восемь',
        9 => 'девять',
        10 => 'десять',
        11 => 'одиннадцать',
        12 => 'двенадцать',
        13 => 'тринадцать',
        14 => 'четырнадцать',
        15 => 'пятнадцать',
        16 => 'шестнадцать',
        17 => 'семнадцать',
        18 => 'восемнадцать',
        19 => 'девятнадцать',
        20 => 'двадцать',
        30 => 'тридцать',
        40 => 'сорок',
        50 => 'пятьдесят',
        60 => 'шестьдесят',
        70 => 'семьдесят',
        80 => 'восемьдесят',
        90 => 'девяносто',
        100 => 'сто',
        200 => 'двести',
        300 => 'триста',
        400 => 'четыреста',
        500 => 'пятьсот',
        600 => 'шестьсот',
        700 => 'семьсот',
        800 => 'восемьсот',
        900 => 'девятьсот',
    ];

    /**
     * Преобразовать сумму в текст (рубли и копейки)
     *
     * @param float $amount Сумма
     * @return string
     */
    public static function amountToWords(float $amount): string
    {
        $rubles = floor($amount);
        $kopecks = round(($amount - $rubles) * 100);
        
        if ($kopecks == 100) {
            $rubles++;
            $kopecks = 0;
        }

        $rublesText = self::numberToWords($rubles);
        $kopecksText = str_pad($kopecks, 2, '0', STR_PAD_LEFT);
        
        $rublesMorph = self::morph($rubles, self::$morph['currency'][0]);
        $kopecksMorph = self::morph($kopecks, self::$morph['currency'][1]);

        return mb_ucfirst($rublesText) . ' ' . $rublesMorph . ' ' . $kopecksText . ' ' . $kopecksMorph;
    }

    /**
     * Преобразовать число в текст
     *
     * @param int $number
     * @return string
     */
    private static function numberToWords(int $number): string
    {
        if ($number == 0) {
            return 'ноль';
        }

        $words = [];
        $level = 0;

        while ($number > 0) {
            $triad = $number % 1000;
            $number = floor($number / 1000);

            if ($triad > 0) {
                $triadText = self::triadToWords($triad, $level);
                $morph = self::morph($triad, self::$morph['units'][$level]);
                
                // Если это тысячи, то добавляем слово тысячи
                if ($level > 0) {
                    array_unshift($words, $triadText . ' ' . $morph);
                } else {
                    array_unshift($words, $triadText);
                }
            }
            
            $level++;
        }

        return implode(' ', $words);
    }

    /**
     * Триада в текст
     *
     * @param int $triad
     * @param int $level
     * @return string
     */
    private static function triadToWords(int $triad, int $level): string
    {
        $words = [];
        $hundreds = floor($triad / 100) * 100;
        $tens = $triad % 100;

        if ($hundreds > 0) {
            $words[] = self::$numbers[$hundreds];
        }

        if ($tens > 0) {
            if (isset(self::$numbers[$tens])) {
                $word = self::$numbers[$tens];
                if ($level == 1) { // Тысячи (женский род)
                    if ($tens == 1) $word = 'одна';
                    if ($tens == 2) $word = 'две';
                }
                $words[] = $word;
            } else {
                $ten = floor($tens / 10) * 10;
                $unit = $tens % 10;
                
                if ($ten > 0) {
                    $words[] = self::$numbers[$ten];
                }
                
                if ($unit > 0) {
                    $word = self::$numbers[$unit];
                    if ($level == 1) { // Тысячи
                        if ($unit == 1) $word = 'одна';
                        if ($unit == 2) $word = 'две';
                    }
                    $words[] = $word;
                }
            }
        }

        return implode(' ', $words);
    }

    /**
     * Склонение
     *
     * @param int $n
     * @param array $forms
     * @return string
     */
    private static function morph(int $n, array $forms): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        
        if ($n > 10 && $n < 20) return $forms[2];
        if ($n1 > 1 && $n1 < 5) return $forms[1];
        if ($n1 == 1) return $forms[0];
        
        return $forms[2];
    }
}

if (!function_exists('mb_ucfirst')) {
    function mb_ucfirst($str) {
        return mb_strtoupper(mb_substr($str, 0, 1)) . mb_substr($str, 1);
    }
}

