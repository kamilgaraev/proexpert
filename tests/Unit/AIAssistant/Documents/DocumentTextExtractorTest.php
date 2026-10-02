<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use App\BusinessModules\Features\AIAssistant\Services\Documents\DocumentTextExtractor;
use PHPUnit\Framework\TestCase;

final class DocumentTextExtractorTest extends TestCase
{
    public function test_plain_text_keeps_text_and_provenance(): void
    {
        $result = (new DocumentTextExtractor)->extract("Строка 1\nСтрока 2", 'text/plain', 'notes.txt');
        self::assertSame('ready', $result['status']); self::assertSame('full', $result['coverage']);
        self::assertSame('Строка 1'."\n".'Строка 2', $result['text']); self::assertSame('text', $result['units'][0]['type']);
    }
    public function test_unknown_file_is_explicitly_unsupported(): void
    {
        $result = (new DocumentTextExtractor)->extract('binary', 'application/octet-stream', 'macro.exe');
        self::assertSame('unsupported', $result['status']); self::assertSame('unsupported', $result['coverage']); self::assertSame([], $result['units']);
    }
    public function test_image_requires_approved_ocr(): void
    {
        $result = (new DocumentTextExtractor)->extract(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='), 'image/png', 'scan.png');
        self::assertSame('ocr_quote_required', $result['status']); self::assertSame('image_ocr_required', $result['coverage']);
    }

    public function test_damaged_image_and_pdf_are_explicit(): void
    {
        foreach ([['image/png', 'scan.png'], ['application/pdf', 'broken.pdf']] as [$mime, $name]) {
            $result = (new DocumentTextExtractor)->extract('not-a-document', $mime, $name);
            self::assertSame('damaged', $result['status']);
            self::assertSame([], $result['units']);
        }
    }

    public function test_audio_and_unsupported_image_are_never_sent_to_ocr(): void
    {
        foreach ([['audio/wav', 'voice.wav'], ['image/svg+xml', 'vector.svg'], ['application/msword', 'legacy.doc']] as [$mime, $name]) {
            self::assertSame('unsupported', (new DocumentTextExtractor)->extract('content', $mime, $name)['status']);
        }
    }

    public function test_pdf_keeps_page_numbers_and_detects_missing_text_layer(): void
    {
        $fixture = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/ocr/vector-plan.pdf';
        $result = (new DocumentTextExtractor)->extract(file_get_contents($fixture), 'application/pdf', 'plan.pdf');
        self::assertNotSame('damaged', $result['status']);
        self::assertGreaterThan(0, $result['page_count']);
        foreach ($result['units'] as $unit) self::assertSame($unit['index'], $unit['provenance']['page']);
    }

    public function test_csv_preserves_quoted_rows_and_provenance(): void
    {
        $result = (new DocumentTextExtractor)->extract("Наименование;Количество\n\"Бетон; марка\";25", 'text/csv', 'materials.csv');
        self::assertSame('ready', $result['status']);
        self::assertSame('Бетон; марка | 25', $result['units'][1]['text']);
        self::assertSame(['row' => 2, 'kind' => 'csv'], $result['units'][1]['provenance']);
    }

    public function test_scanned_pdf_with_more_than_six_pages_stays_supported(): void
    {
        $pdf = new \Dompdf\Dompdf;
        $html = '<html><body>';
        for ($page = 1; $page <= 7; $page++) {
            $html .= '<div style="'.($page > 1 ? 'page-break-before:always;' : '').'height:20px;"><svg width="10" height="10"><rect width="10" height="10" fill="black"/></svg></div>';
        }
        $pdf->loadHtml($html.'</body></html>');
        $pdf->render();
        $result = (new DocumentTextExtractor)->extract($pdf->output(), 'application/pdf', 'archive.pdf');
        self::assertSame('ocr_quote_required', $result['status']);
        self::assertSame(7, $result['page_count']);
    }
}
