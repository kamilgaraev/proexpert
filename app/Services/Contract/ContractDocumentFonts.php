<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Dompdf\Options;

final class ContractDocumentFonts
{
    public static function bytes(string $variant): string
    {
        static $fonts = [];
        $file = match ($variant) {
            'bold' => 'DejaVuSans-Bold.ttf', 'italic' => 'DejaVuSans-Oblique.ttf', 'bolditalic' => 'DejaVuSans-BoldOblique.ttf', default => 'DejaVuSans.ttf'
        };
        if (! isset($fonts[$file])) {
            $bytes = file_get_contents((new Options)->getFontDir().'/'.$file);
            if ($bytes === false) {
                throw new ContractBuilderException('contracts.builder_export_failed', 503);
            }
            $fonts[$file] = $bytes;
        }

        return $fonts[$file];
    }

    public static function css(array $plan): string
    {
        $variants = [];
        foreach ($plan['pages'] as $items) {
            foreach ($items as $item) {
                if ($item['type'] === 'text') {
                    $variants[($item['bold'] ? 'bold' : '').($item['italic'] ? 'italic' : '')] = true;
                }
            }
        }
        $css = '';
        foreach (array_keys($variants) as $variant) {
            $css .= '@font-face{font-family:MostContract;src:url(data:font/ttf;base64,'.base64_encode(self::bytes($variant)).') format("truetype");'
                .'font-weight:'.(str_contains($variant, 'bold') ? 'bold' : 'normal').';font-style:'.(str_contains($variant, 'italic') ? 'italic' : 'normal').'}';
        }

        return $css;
    }
}
