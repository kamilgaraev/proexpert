<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Dompdf\Dompdf;

final class ContractPageDecorations
{
    public static function defaultLayout(): array
    {
        return ['version' => 1, 'page' => ['format' => 'A4', 'orientation' => 'portrait', 'margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
            'grid' => ['size' => 5, 'snap' => true, 'visible' => true]];
    }

    public function validate(mixed $value, array $page): void
    {
        $this->keys($value, ['header', 'footer', 'pageNumbers']);
        foreach (['header', 'footer'] as $band) {
            if (!array_key_exists($band, $value)) { continue; }
            $this->keys($value[$band], ['text', 'align']);
            if (!is_string($value[$band]['text'] ?? null) || mb_strlen($value[$band]['text']) > 500
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value[$band]['text']) === 1
                || !in_array($value[$band]['align'] ?? null, ['left', 'center', 'right'], true)) { $this->invalid(); }
        }
        if (array_key_exists('pageNumbers', $value)) {
            $number = $value['pageNumbers'];
            $this->keys($number, ['enabled', 'position', 'align', 'format', 'startAt', 'hideFirst']);
            if (!is_bool($number['enabled'] ?? null) || !is_bool($number['hideFirst'] ?? null)
                || !in_array($number['position'] ?? null, ['header', 'footer'], true)
                || !in_array($number['align'] ?? null, ['left', 'center', 'right'], true)
                || !in_array($number['format'] ?? null, ['current', 'current_total'], true)
                || !is_int($number['startAt'] ?? null) || $number['startAt'] < 1 || $number['startAt'] > 9999) { $this->invalid(); }
        }
        [$width, $height] = (new ContractDocumentPrintLayout)->size($page);
        $plan = ['width' => $width, 'height' => $height, 'page' => $page, 'pageDecorations' => $value, 'pages' => [[]]];
        $this->items($plan, 0, true);
    }

    public function items(array $plan, int $pageIndex, bool $validateSpace = false): array
    {
        $value = $plan['pageDecorations'] ?? [];
        if ($value === []) { return []; }
        $number = $value['pageNumbers'] ?? null;
        $unit = ContractDocumentPrintMeasure::PT_PER_MM;
        $left = $plan['page']['margins']['left'] * $unit;
        $width = $plan['width'] - $left - $plan['page']['margins']['right'] * $unit;
        $metrics = (new Dompdf)->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans', 'normal');
        $result = [];
        foreach (['header' => 'top', 'footer' => 'bottom'] as $band => $edge) {
            $lines = [];
            foreach (explode("\n", trim($value[$band]['text'] ?? '')) as $paragraph) {
                if ($paragraph === '') { continue; }
                $line = '';
                foreach (preg_split('//u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) as $character) {
                    if ($line !== '' && $metrics->getTextWidth($line.$character, $font, 9) > $width) { $lines[] = ['text' => $line, 'align' => $value[$band]['align']]; $line = ''; }
                    $line .= $character;
                }
                if ($line !== '') { $lines[] = ['text' => $line, 'align' => $value[$band]['align']]; }
            }
            if (($number['enabled'] ?? false) && $number['position'] === $band && ($validateSpace || !$number['hideFirst'] || $pageIndex > 0)) {
                $current = $number['startAt'] + $pageIndex;
                $lines[] = ['text' => $number['format'] === 'current_total' ? $current.' / '.($number['startAt'] + count($plan['pages']) - 1) : (string) $current, 'align' => $number['align']];
            }
            if ($lines === []) { continue; }
            $height = count($lines) * 12;
            $margin = $plan['page']['margins'][$edge] * $unit;
            if ($height + 12 > $margin) { $this->invalid(); }
            $top = $band === 'header' ? 6 : $plan['height'] - $margin + 6;
            foreach ($lines as $index => $line) {
                $textWidth = $metrics->getTextWidth($line['text'], $font, 9);
                $x = $left + match ($line['align']) { 'center' => ($width - $textWidth) / 2, 'right' => $width - $textWidth, default => 0 };
                $result[] = ['type' => 'text', 'x' => $x, 'y' => $top + $index * 12, 'width' => $textWidth, 'height' => 12,
                    'text' => $line['text'], 'fontFamily' => 'DejaVu Sans', 'fontSize' => 9, 'bold' => false, 'italic' => false,
                    'underline' => false, 'strike' => false, 'wordSpacing' => 0, 'letterSpacing' => 0, 'href' => null, 'color' => '#51606c'];
            }
        }

        return $result;
    }

    private function keys(mixed $value, array $allowed): void
    {
        if (!is_array($value) || array_diff(array_keys($value), $allowed) !== []) { $this->invalid(); }
    }

    private function invalid(): never
    {
        throw new ContractBuilderException('contracts.builder_page_decorations_invalid', 422, [], 'document.attrs.pageDecorations');
    }
}
