<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractTypography
{
    public const FONTS = ['Most Contract', ...ContractFontCatalog::FAMILIES];

    public static function validate(mixed $format, bool $paragraph = false): void
    {
        $keys = ['fontFamily', 'fontSize', 'color'];
        if ($paragraph) {
            array_push($keys, 'align', 'lineHeight', 'firstLineIndent', 'spaceAfter');
        }
        $invalid = !is_array($format) || array_diff(array_keys($format), $keys) !== [];
        if (!$invalid) {
            $invalid = (isset($format['fontFamily']) && !in_array($format['fontFamily'], self::FONTS, true))
                || (isset($format['color']) && (!is_string($format['color']) || preg_match('/^#[a-f0-9]{6}$/iD', $format['color']) !== 1))
                || (isset($format['align']) && !in_array($format['align'], ['left', 'center', 'right', 'justify'], true));
            foreach (['fontSize' => [8, 72], 'lineHeight' => [1, 3], 'firstLineIndent' => [0, 50], 'spaceAfter' => [0, 50]] as $key => [$min, $max]) {
                if (array_key_exists($key, $format)) {
                    $value = $format[$key];
                    $invalid = $invalid || (!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max;
                }
            }
            foreach ($format as $value) {
                $invalid = $invalid || $value === null;
            }
        }
        if ($invalid) {
            throw new ContractBuilderException('contracts.builder_typography_invalid', 422);
        }
    }

    public static function style(array $format): string
    {
        $style = '';
        if (isset($format['fontFamily'])) {
            $style .= 'font-family:'.ContractFontCatalog::css($format['fontFamily']).';';
        }
        foreach (['fontSize' => ['font-size', 'pt'], 'color' => ['color', ''], 'align' => ['text-align', ''],
            'lineHeight' => ['line-height', ''], 'firstLineIndent' => ['text-indent', 'mm'], 'spaceAfter' => ['margin-bottom', 'mm']] as $key => [$property, $unit]) {
            if (isset($format[$key])) {
                $style .= $property.':'.$format[$key].$unit.';';
            }
        }
        return $style;
    }
}
