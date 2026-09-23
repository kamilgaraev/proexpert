<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Dompdf\Options;

final class ContractDocumentFonts
{
    public static function bytes(string $variant, string $family = 'DejaVu Sans'): string
    {
        static $fonts = [];
        $base = match ($family) {
            'DejaVu Serif' => 'DejaVuSerif',
            'DejaVu Sans Mono' => 'DejaVuSansMono',
            default => 'DejaVuSans',
        };
        $suffix = match ($variant) {
            'bold' => '-Bold', 'italic' => $base === 'DejaVuSerif' ? '-Italic' : '-Oblique',
            'bolditalic' => $base === 'DejaVuSerif' ? '-BoldItalic' : '-BoldOblique', default => '',
        };
        $file = $base.$suffix.'.ttf';
        $key = $family.':'.$file;
        if (! isset($fonts[$key])) {
            $bytes = file_get_contents((new Options)->getFontDir().'/'.$file);
            if ($bytes === false) {
                throw new ContractBuilderException('contracts.builder_export_failed', 503);
            }
            $fonts[$key] = $bytes;
        }

        return $fonts[$key];
    }

    public static function css(array $plan): string
    {
        $variants = [];
        foreach ($plan['pages'] as $items) {
            foreach ($items as $item) {
                if ($item['type'] === 'text') {
                    $family = $item['fontFamily'] === 'Most Contract' ? 'DejaVu Sans' : $item['fontFamily'];
                    $variant = ($item['bold'] ? 'bold' : '').($item['italic'] ? 'italic' : '') ?: 'regular';
                    $variants[$family][$variant] = true;
                }
            }
        }
        $css = '';
        foreach ($variants as $family => $styles) {
            foreach (array_keys($styles) as $variant) {
                $css .= '@font-face{font-family:"'.$family.'";src:url(data:font/ttf;base64,'.base64_encode(self::bytes($variant, $family)).') format("truetype");'
                    .'font-weight:'.(str_contains($variant, 'bold') ? 'bold' : 'normal').';font-style:'.(str_contains($variant, 'italic') ? 'italic' : 'normal').'}';
            }
        }

        return $css;
    }
}
