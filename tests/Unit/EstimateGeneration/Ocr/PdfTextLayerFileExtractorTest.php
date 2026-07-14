<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfParserRuntime;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor;
use Dompdf\Dompdf;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdfTextLayerFileExtractorTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository([
            'estimate-generation' => [
                'ocr' => [
                    'pdf_parser_memory_limit' => '512M',
                    'pdf_text_layer_min_chars' => 3,
                ],
            ],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    #[Test]
    public function extracts_text_directly_from_a_spooled_pdf_file(): void
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<p>house plan 151</p>');
        $dompdf->render();
        $source = tmpfile();

        try {
            self::assertIsResource($source);
            fwrite($source, $dompdf->output());
            fflush($source);
            $path = stream_get_meta_data($source)['uri'] ?? null;
            self::assertIsString($path);

            $result = (new PdfTextLayerExtractor(new PdfParserRuntime))->extractFile($path, 'plan.pdf');

            self::assertNotNull($result);
            self::assertStringContainsString('house plan 151', $result->pages[0]->text);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }
}
