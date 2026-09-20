<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

final class ContractPositionedExporter
{
    public function render(array $plan, string $format): string
    {
        return $format === 'pdf' ? $this->pdf($plan) : $this->docx($plan);
    }

    private function pdf(array $plan): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $pdf = new Dompdf($options);
        $pdf->setPaper([0, 0, $plan['width'], $plan['height']]);
        $pdf->loadHtml('<html><body></body></html>');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $metrics = $pdf->getFontMetrics();
        foreach ($plan['pages'] as $page => $items) {
            if ($page > 0) {
                $canvas->new_page();
            }
            foreach ($items as $item) {
                if ($item['type'] === 'rect') {
                    $canvas->rectangle($item['x'], $item['y'], $item['width'], $item['height'], [.67, .70, .75], .5);
                } elseif ($item['type'] === 'anchor') {
                    $canvas->add_named_dest($item['name']);
                } else {
                    $font = $metrics->getFont('DejaVu Sans', ($item['bold'] ? 'bold' : '').($item['italic'] ? 'italic' : '') ?: 'normal');
                    $canvas->text($item['x'], $item['y'], $item['text'], $font, $item['fontSize'], [.09, .125, .2], $item['wordSpacing'], $item['letterSpacing']);
                    if ($item['href'] !== null) {
                        $canvas->add_link($item['href'], $item['x'], $item['y'], $item['width'], $item['height']);
                    }
                    if ($item['underline'] || $item['strike']) {
                        $baseline = $metrics->getFontBaseline($font, $item['fontSize']);
                        if ($item['underline']) {
                            $canvas->line($item['x'], $item['y'] + $baseline + .5, $item['x'] + $item['width'], $item['y'] + $baseline + .5, [.09, .125, .2], .5);
                        }
                        if ($item['strike']) {
                            $canvas->line($item['x'], $item['y'] + $baseline * .6, $item['x'] + $item['width'], $item['y'] + $baseline * .6, [.09, .125, .2], .5);
                        }
                    }
                }
            }
        }

        return $pdf->output();
    }

    private function docx(array $plan): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName('DejaVu Sans');
        $word->setDefaultFontSize(10);
        $word->setDefaultParagraphStyle(['spaceBefore' => 0, 'spaceAfter' => 0]);
        $margins = $plan['page']['margins'];
        foreach ($plan['pages'] as $items) {
            $section = $word->addSection(['pageSizeW' => (int) round($plan['width'] * 20), 'pageSizeH' => (int) round($plan['height'] * 20),
                'orientation' => $plan['page']['orientation'], 'marginTop' => (int) round($margins['top'] * 1440 / 25.4),
                'marginRight' => (int) round($margins['right'] * 1440 / 25.4), 'marginBottom' => (int) round($margins['bottom'] * 1440 / 25.4),
                'marginLeft' => (int) round($margins['left'] * 1440 / 25.4), 'breakType' => 'nextPage']);
            foreach ($items as $item) {
                if ($item['type'] === 'anchor') {
                    $section->addBookmark($this->bookmark($item['name']));

                    continue;
                }
                $style = ['width' => $item['width'] + ($item['type'] === 'text' ? 4 : 0), 'height' => $item['height'] + ($item['type'] === 'text' ? 4 : 0),
                    'left' => $item['x'], 'top' => $item['y'], 'unit' => 'pt', 'pos' => 'absolute',
                    'hPos' => 'absolute', 'vPos' => 'absolute', 'hPosRelTo' => 'page', 'vPosRelTo' => 'page',
                    'wrap' => 'infront', 'innerMargin' => 0, 'borderSize' => $item['type'] === 'rect' ? 1 : 0, 'borderColor' => $item['type'] === 'rect' ? 'AAB3BF' : null];
                $box = $section->addTextBox($style);
                if ($item['type'] !== 'text') {
                    continue;
                }
                $font = ['name' => 'DejaVu Sans', 'size' => $item['fontSize'], 'bold' => $item['bold'], 'italic' => $item['italic'],
                    'underline' => $item['underline'] ? 'single' : 'none', 'strikethrough' => $item['strike']];
                $paragraph = ['spaceBefore' => 0, 'spaceAfter' => 0, 'lineHeight' => 1];
                if ($item['href'] !== null) {
                    $internal = str_starts_with($item['href'], '#');
                    $box->addLink($internal ? $this->bookmark(substr($item['href'], 1)) : $item['href'], $item['text'], $font, $paragraph, $internal);
                } else {
                    $box->addText($item['text'], $font, $paragraph);
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'most-positioned-');
        if ($path === false) {
            throw new ContractBuilderException('contracts.builder_export_failed', 503);
        }
        $escaping = Settings::isOutputEscapingEnabled();
        try {
            Settings::setOutputEscapingEnabled(true);
            IOFactory::createWriter($word, 'Word2007')->save($path);
            (new ContractPositionedWordPackage)->finish($path);
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new ContractBuilderException('contracts.builder_export_failed', 503);
            }

            return $bytes;
        } finally {
            Settings::setOutputEscapingEnabled($escaping);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function bookmark(string $id): string
    {
        return 'clause_'.substr(hash('sha256', $id), 0, 24);
    }
}
