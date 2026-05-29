<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrPreflightService;
use Dompdf\Dompdf;
use Tests\TestCase;

class OcrPreflightServiceTest extends TestCase
{
    public function test_it_detects_pdf_page_count_without_counting_pages_dictionary(): void
    {
        $document = new EstimateGenerationDocument([
            'filename' => 'project.pdf',
            'mime_type' => 'application/pdf',
            'meta' => ['original_extension' => 'pdf'],
        ]);

        $content = "%PDF-1.4\n<< /Type /Pages >>\n<< /Type /Page >>\n<< /Type /Page >>";

        $this->assertSame(2, app(OcrPreflightService::class)->validatePdfPageCount($document, $content));
    }

    public function test_it_detects_pdf_page_count_from_real_pdf_structure(): void
    {
        $document = new EstimateGenerationDocument([
            'filename' => 'project.pdf',
            'mime_type' => 'application/pdf',
            'meta' => ['original_extension' => 'pdf'],
        ]);

        $this->assertSame(2, app(OcrPreflightService::class)->validatePdfPageCount($document, $this->pdfContent([
            'Первая страница',
            'Вторая страница',
        ])));
    }

    public function test_it_rejects_pdf_with_more_pages_than_provider_limit(): void
    {
        config()->set('estimate-generation.ocr.max_pdf_pages', 2);

        $document = new EstimateGenerationDocument([
            'filename' => 'project.pdf',
            'mime_type' => 'application/pdf',
            'meta' => ['original_extension' => 'pdf'],
        ]);

        try {
            app(OcrPreflightService::class)->validatePdfPageCount(
                $document,
                "%PDF-1.4\n<< /Type /Page >>\n<< /Type /Page >>\n<< /Type /Page >>"
            );

            $this->fail('Expected OCR provider exception.');
        } catch (OcrProviderException $exception) {
            $this->assertSame('estimate_generation.ocr_pdf_too_many_pages', $exception->messageKey);
            $this->assertSame('pdf_page_limit_exceeded', $exception->providerCode);
            $this->assertSame(3, $exception->context['page_count'] ?? null);
            $this->assertSame(2, $exception->context['max_pdf_pages'] ?? null);
        }
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
