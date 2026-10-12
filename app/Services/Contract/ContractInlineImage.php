<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractInlineImage
{
    public const MAX_BYTES = 512000;

    public static function bytes(mixed $source): string
    {
        if (!is_string($source) || strlen($source) > 683000 || preg_match('~^data:image/(png|jpeg);base64,([A-Za-z0-9+/]+={0,2})$~D', $source, $match) !== 1) { self::invalid(); }
        $bytes = base64_decode($match[2], true);
        if ($bytes === false || strlen($bytes) < 10 || strlen($bytes) > self::MAX_BYTES || base64_encode($bytes) !== $match[2]) { self::invalid(); }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || !in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
            || $size[2] !== ($match[1] === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG)
            || $size[0] < 1 || $size[1] < 1 || $size[0] > 4096 || $size[1] > 4096 || $size[0] * $size[1] > 12000000) { self::invalid(); }
        if (!function_exists('imagecreatefromstring')) { throw new ContractBuilderException('contracts.builder_export_failed', 503); }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) { self::invalid(); }
        imagedestroy($image);

        return $bytes;
    }

    public static function attributes(array $attrs): int
    {
        if (!is_string($attrs['alt'] ?? null) || mb_strlen($attrs['alt']) > 255) { self::invalid(); }
        foreach (['width' => 297, 'height' => 200] as $field => $maximum) {
            $value = $attrs[$field] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 5 || $value > $maximum) { self::invalid(); }
        }
        return strlen(self::bytes($attrs['src'] ?? null));
    }

    private static function invalid(): never
    {
        throw new ContractBuilderException('contracts.builder_image_invalid', 422);
    }
}
