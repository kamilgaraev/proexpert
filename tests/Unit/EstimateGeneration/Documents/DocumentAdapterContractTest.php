<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Documents\Cad\CadDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Documents\Spreadsheet\SpreadsheetDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\PdfDocumentAdapter as ApplicationPdfDocumentAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SeekableDocumentSource;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\StoredDocumentArtifact;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryWorker;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentAdapterContractTest extends TestCase
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
                    'max_spreadsheet_rows' => 20,
                    'max_spreadsheet_columns' => 10,
                    'languages' => ['ru'],
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
    public function spreadsheet_preserves_native_cell_addresses_values_formulas_and_headings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'most-native-spreadsheet-');
        self::assertIsString($path);
        $xlsxPath = $path.'.xlsx';
        rename($path, $xlsxPath);
        $spreadsheet = new Spreadsheet;

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Смета');
            $sheet->fromArray([['Наименование', 'Количество', 'Стоимость'], ['Бетон', 3, '=B2*100']]);
            (new Xlsx($spreadsheet))->save($xlsxPath);

            $document = new EstimateGenerationDocument([
                'filename' => 'смета.xlsx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'meta' => ['original_extension' => 'xlsx'],
            ]);
            $page = (new SpreadsheetDocumentExtractor)->extractFile($document, $xlsxPath)->pages[0];
            $payload = (new SpreadsheetDocumentAdapter)->extract($page);

            self::assertSame('available', $page->rawPayload['native_structure']['status']);
            self::assertSame('spreadsheet', $payload['source_kind']);
            self::assertSame($page->rawPayload['native_structure'], $payload['native_structure']);
            self::assertSame(['A1', 'B1', 'C1'], $page->rawPayload['native_structure']['headings']);
            self::assertSame([
                ['address' => 'A2', 'value' => 'Бетон', 'formula' => null],
                ['address' => 'B2', 'value' => '3', 'formula' => null],
                ['address' => 'C2', 'value' => '300', 'formula' => '=B2*100'],
            ], array_slice($page->rawPayload['native_structure']['cells'], 3));
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($xlsxPath);
        }
    }

    #[Test]
    public function cad_adapter_reports_native_layers_blocks_polylines_text_and_dimensions(): void
    {
        $payload = (new CadDocumentAdapter)->extract($this->cadGeometry());

        self::assertSame('available', $payload['native_structure']['status']);
        self::assertSame([
            'layers' => 'available',
            'blocks' => 'available',
            'polylines' => 'available',
            'texts' => 'available',
            'dimensions' => 'available',
        ], $payload['native_structure']['capabilities']);
        self::assertSame('Стены', $payload['native_structure']['layers'][0]['name']);
        self::assertSame('polyline', $payload['native_structure']['polylines'][0]['type']);
        self::assertSame('1000', $payload['native_structure']['dimensions'][0]['text']);
    }

    #[Test]
    public function cad_adapter_does_not_claim_unimplemented_dwg_capabilities(): void
    {
        $data = $this->cadGeometry();
        $dwg = new VectorGeometryData(
            $data->schemaVersion,
            'cad-geometry:v1;libredwg:0.13.4',
            $data->sourceFingerprint,
            $data->sourceUnit,
            $data->unitStatus,
            $data->bounds,
            $data->layers,
            [],
            $data->entities,
            $data->texts,
            [],
            $data->pages,
            $data->scaleCandidates,
            $data->warnings,
        );

        $payload = (new CadDocumentAdapter)->extract($dwg);

        self::assertSame('partial', $payload['native_structure']['status']);
        self::assertSame('unavailable', $payload['native_structure']['capabilities']['blocks']);
        self::assertSame('unavailable', $payload['native_structure']['capabilities']['dimensions']);
    }

    #[Test]
    public function pdf_keeps_text_geometry_and_high_detail_render_as_separate_sources(): void
    {
        $storage = new class implements DocumentSourceManifestStorage
        {
            /** @var array<string, string> */
            public array $contents = [];

            public function open(EstimateGenerationDocument $document, string $sourceVersion): SeekableDocumentSource
            {
                $stream = tmpfile();
                fwrite($stream, 'pdf');
                rewind($stream);

                return new SeekableDocumentSource($stream, 3);
            }

            public function put(EstimateGenerationDocument $document, string $sourceVersion, DocumentUnitType $type, int $index, string $content, string $contentType = 'text/plain'): StoredDocumentArtifact
            {
                $this->contents[$type->value.':'.$index] = $content;

                return new StoredDocumentArtifact(
                    sprintf('org-1/documents/%s-%d', $type->value, $index),
                    max(1, strlen($content)),
                    'sha256:'.hash('sha256', $content),
                    sprintf('%s-%d', $type->value, $index),
                    $contentType,
                );
            }
        };
        $text = new class extends PdfTextLayerExtractor
        {
            public function __construct() {}

            public function extractFile(string $path, ?string $filename = null): ?OcrRecognitionResult
            {
                return new OcrRecognitionResult('pdf_text_layer', 'embedded_text', [new OcrPageResult(1, 'План этажа')]);
            }
        };
        $geometry = new PdfGeometryExtractor(new class extends PdfGeometryWorker
        {
            public function extractFile(string $sourcePath, ?string $filename = null, ?callable $previewPublisher = null): array
            {
                $preview = tempnam(sys_get_temp_dir(), 'most-pdf-preview-');
                file_put_contents($preview, 'png');

                try {
                    return ['pages' => [[
                        'page_number' => 1,
                        'width' => 200,
                        'height' => 100,
                        'page_role' => 'plan',
                        'text_blocks' => [],
                        'vector_elements' => [],
                        'visual_metrics' => [],
                        'preview' => $previewPublisher(1, $preview, ['width' => 200, 'height' => 100]),
                    ]]];
                } finally {
                    unlink($preview);
                }
            }
        });
        $document = new EstimateGenerationDocument(['filename' => 'plan.pdf', 'mime_type' => 'application/pdf']);

        (new ApplicationPdfDocumentAdapter($storage, $text, $geometry))->detect($document, 'sha256:'.str_repeat('b', 64));
        $payload = json_decode($storage->contents['pdf_page:1'], true, 64, JSON_THROW_ON_ERROR);

        self::assertSame('available', $payload['sources']['text_layer']['status']);
        self::assertSame('available', $payload['sources']['geometry']['status']);
        self::assertSame(['status' => 'available', 'detail' => 'high'], $payload['sources']['render']);
    }

    private function cadGeometry(): VectorGeometryData
    {
        return VectorGeometryData::fromArray([
            'schema_version' => 1,
            'runtime_version' => 'cad-geometry:v1;ezdxf:1.4.4',
            'source_fingerprint' => 'sha256:'.str_repeat('a', 64),
            'source_unit' => 'mm',
            'unit_status' => 'confirmed',
            'bounds' => [0, 0, 1000, 1000],
            'layers' => [['name' => 'Стены', 'visible' => true]],
            'blocks' => [['name' => 'Окно', 'handle' => 'B1', 'owner' => '0', 'entities' => ['E1']]],
            'entities' => [[
                'handle' => 'E1',
                'type' => 'polyline',
                'layer' => 'Стены',
                'points' => [[0, 0], [1000, 0]],
                'closed' => false,
            ]],
            'texts' => [[
                'handle' => 'T1',
                'type' => 'text',
                'layer' => 'Стены',
                'text' => 'Ось А',
                'position' => [0, 0],
                'layout' => 'Model',
            ]],
            'dimensions' => [[
                'handle' => 'D1',
                'type' => 'linear',
                'layer' => 'Стены',
                'text' => '1000',
                'layout' => 'Model',
                'definition_points' => [[0, 0], [1000, 0]],
            ]],
            'pages' => [],
            'scale_candidates' => [],
            'warnings' => [],
        ]);
    }
}
