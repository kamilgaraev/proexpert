<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Dompdf\Options;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;

final class ContractDocumentFonts
{
    public static function bytes(string $variant, string $family = 'DejaVu Sans'): string
    {
        static $fonts = [];
        $family = ContractFontCatalog::family($family);
        $file = self::path($variant, $family);
        if (isset($fonts[$file])) return $fonts[$file];
        $bytes = file_get_contents($file);
        if ($bytes === false) throw new ContractBuilderException('contracts.builder_export_failed', 503);
        if (!isset(ContractFontCatalog::FILES[$family])) $fonts[$file] = $bytes;
        return $bytes;
    }

    public static function path(string $variant, string $family = 'DejaVu Sans'): string
    {
        $family = ContractFontCatalog::family($family);
        if (!in_array($family, ContractFontCatalog::FAMILIES, true)) throw new ContractBuilderException('contracts.builder_export_failed', 503);
        $base = match ($family) {
            'DejaVu Serif' => 'DejaVuSerif',
            'DejaVu Sans Mono' => 'DejaVuSansMono',
            default => 'DejaVuSans',
        };
        $suffix = match ($variant) {
            'bold' => '-Bold', 'italic' => $base === 'DejaVuSerif' ? '-Italic' : '-Oblique',
            'bolditalic' => $base === 'DejaVuSerif' ? '-BoldItalic' : '-BoldOblique', default => '',
        };
        $file = ContractFontCatalog::FILES[$family][$variant] ?? $base.$suffix.'.ttf';
        $directory = isset(ContractFontCatalog::FILES[$family]) ? dirname(__DIR__, 3).'/resources/fonts/contracts' : (new Options)->getFontDir();
        return $directory.'/'.$file;
    }

    public static function pdf(array $families = []): Dompdf
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $families = array_unique(array_map(ContractFontCatalog::family(...), $families));
        $custom = array_intersect($families, array_keys(ContractFontCatalog::FILES));
        if ($custom === []) return new Dompdf($options);
        $directory = dirname(__DIR__, 3).'/resources/fonts/contracts';
        $cache = dirname(__DIR__, 3).'/storage/framework/cache/contract-fonts/'.hash_file('sha256', $directory.'/SOURCES.json');
        if (!is_dir($cache) && !mkdir($cache, 0770, true) && !is_dir($cache)) throw new ContractBuilderException('contracts.builder_export_failed', 503);
        $options->setFontDir($cache);
        $options->setFontCache($cache);
        $options->setChroot(array_unique([...$options->getChroot(), $directory]));
        $lock = fopen($cache.'/registration.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new ContractBuilderException('contracts.builder_export_failed', 503);
        try {
            $pdf = new Dompdf($options);
            foreach ($custom as $family) {
                foreach (ContractFontCatalog::FILES[$family] as $variant => $file) {
                    $style = ['family' => $family, 'weight' => str_contains($variant, 'bold') ? 'bold' : 'normal', 'style' => str_contains($variant, 'italic') ? 'italic' : 'normal'];
                    $url = 'file://'.str_replace('\\', '/', $directory.'/'.$file);
                    if (!$pdf->getFontMetrics()->registerFont($style, $url)) throw new ContractBuilderException('contracts.builder_export_failed', 503);
                }
            }
            return $pdf;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function families(string $html): array
    {
        preg_match_all('/font-family\s*:\s*([^;]+)/i', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches);
        $families = ['DejaVu Sans'];
        foreach ($matches[1] as $stack) {
            foreach (explode(',', $stack) as $family) {
                $family = ContractFontCatalog::family(trim($family, " \t\n\r\0\x0B'\""));
                if (in_array($family, ContractFontCatalog::FAMILIES, true)) $families[] = $family;
            }
        }
        return array_unique($families);
    }

    public static function actualFamily(FontMetrics $metrics, string $path): string
    {
        $families = $metrics->getFontFamilies();
        foreach (ContractFontCatalog::FAMILIES as $family) {
            foreach ($families[mb_strtolower($family)] ?? [] as $fontPath) {
                if (str_replace('\\', '/', $path) === str_replace('\\', '/', $fontPath)) return $family;
            }
        }
        throw new ContractBuilderException('contracts.builder_export_failed', 503);
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
