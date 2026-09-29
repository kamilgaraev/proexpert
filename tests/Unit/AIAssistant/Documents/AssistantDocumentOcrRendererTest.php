<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentOcrRenderer;
use Dompdf\Dompdf;
use PHPUnit\Framework\TestCase;

final class AssistantDocumentOcrRendererTest extends TestCase
{
    public function test_pdf_resume_renders_only_remaining_page_and_cleans_temporary_files(): void
    {
        $pdf = new Dompdf;
        $pdf->loadHtml('<html><body><div style="height:20px"></div><div style="page-break-before:always;height:20px"></div></body></html>');
        $pdf->render();
        $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-ocr-*');
        $pages = iterator_to_array((new AssistantDocumentOcrRenderer)->pages($pdf->output(), 'application/pdf', 2, [1]));
        self::assertSame([2], array_keys($pages));
        self::assertSame('image/png', $pages[2]['mime']);
        self::assertNotFalse(getimagesizefromstring($pages[2]['content']));
        self::assertSame($before, glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-ocr-*'));
    }

    public function test_invalid_image_never_becomes_an_ocr_page(): void
    {
        $this->expectException(\RuntimeException::class);
        iterator_to_array((new AssistantDocumentOcrRenderer)->pages('audio bytes', 'audio/wav', 1));
    }
}
