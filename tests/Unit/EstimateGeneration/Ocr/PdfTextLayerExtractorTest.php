<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor;
use Dompdf\Dompdf;
use Tests\TestCase;

class PdfTextLayerExtractorTest extends TestCase
{
    public function test_it_extracts_embedded_text_from_pdf_pages(): void
    {
        $result = app(PdfTextLayerExtractor::class)->extract($this->pdfContent([
            'Общая площадь дома 151,76 м2',
            'Жилая площадь 80,21 м2',
        ]), 'project.pdf');

        $this->assertNotNull($result);
        $this->assertSame(PdfTextLayerExtractor::PROVIDER, $result->provider);
        $this->assertCount(2, $result->pages);
        $this->assertStringContainsString('Общая площадь дома 151,76 м2', $result->pages[0]->text);
        $this->assertStringContainsString('Жилая площадь 80,21 м2', $result->pages[1]->text);
    }

    public function test_it_returns_null_when_pdf_has_no_useful_text(): void
    {
        config()->set('estimate-generation.ocr.pdf_text_layer_min_chars', 100);

        $result = app(PdfTextLayerExtractor::class)->extract($this->pdfContent(['']), 'empty.pdf');

        $this->assertNull($result);
    }

    /**
     * @param array<int, string> $pages
     */
    private function pdfContent(array $pages): string
    {
        $html = '<html><meta charset="utf-8"><style>body { font-family: DejaVu Sans, sans-serif; }</style><body>';

        foreach ($pages as $index => $text) {
            $style = $index + 1 < count($pages) ? ' style="page-break-after: always;"' : '';
            $html .= '<div' . $style . '><p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></div>';
        }

        $html .= '</body></html>';

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }
}
