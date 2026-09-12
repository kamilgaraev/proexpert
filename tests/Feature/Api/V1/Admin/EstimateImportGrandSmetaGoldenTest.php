<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportPipelineService;
use App\Models\EstimateItem;
use App\Models\ImportSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class EstimateImportGrandSmetaGoldenTest extends TestCase
{
    use RefreshDatabase;

    private const LONG_CODE = "ГЭСН05-01-002-02\nС40.30-1-F150-W8-B25 (5шт), С90.30-8-F150-W8-B25 (5шт), С120.30-8-F150-W8-B25 (15шт)";

    public function test_grand_smeta_detection_and_preview_stay_stable(): void
    {
        Storage::fake('s3');
        Queue::fake();
        $disk = Storage::disk('s3');
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putPrivate')->once()->andReturnUsing(
            static function (string $key, mixed $contents, string $mime, string $sha256) use ($disk): CurrentStoredFile {
                $disk->put($key, $contents);

                return new CurrentStoredFile($key, 'test-etag', $disk->size($key), $sha256, $mime);
            }
        );
        $files->shouldReceive('existsCurrent')->andReturnUsing(static fn (string $key): bool => $disk->exists($key));
        $files->shouldReceive('readCurrent')->andReturnUsing(static fn (string $key) => $disk->readStream($key));
        $this->app->instance(FileService::class, $files);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $filePath = $this->createGrandSmetaSpreadsheet();

        $uploadedFile = new UploadedFile(
            $filePath,
            'grand-smeta-golden.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $service = app(EstimateImportService::class);
        $sessionId = $service->uploadFile($uploadedFile, $user->id, $organization->id);

        $type = $service->detectEstimateType($sessionId);
        $session = ImportSession::query()->findOrFail($sessionId);

        self::assertSame('grandsmeta', $type->detectedType);
        self::assertGreaterThanOrEqual(0.9, $type->confidence);
        self::assertSame('grandsmeta', $session->options['format_handler'] ?? null);

        $format = $service->detectFormat($sessionId);
        $session->refresh();

        self::assertSame('grandsmeta', $format['format']);
        self::assertSame('grandsmeta', $session->options['format_handler'] ?? null);
        self::assertSame(3, $session->options['structure']['header_row'] ?? null);
        self::assertNotEmpty($session->options['structure']['column_mapping'] ?? []);

        $preview = $service->preview($sessionId);

        self::assertSame('grandsmeta', $preview->metadata['handler'] ?? null);
        self::assertNotEmpty($preview->sections);
        self::assertNotEmpty($preview->items);
        self::assertGreaterThanOrEqual(200.0, $preview->getTotalAmount());

        $session->refresh();
        $options = $session->options ?? [];
        $options['estimate_settings'] = ['project_id' => $project->id, 'name' => 'Проверка длинного кода'];
        $session->update(['options' => $options]);
        app(ImportPipelineService::class)->run($session->fresh());
        $session->refresh();

        self::assertSame('completed', $session->status, $session->error_message ?? '');
        $item = EstimateItem::query()->where('estimate_id', $session->stats['estimate_id'])->sole();
        self::assertSame(self::LONG_CODE, $item->normative_rate_code);
        self::assertSame(self::LONG_CODE, $item->metadata['raw_data']['B']);
        self::assertEquals(200, $item->total_amount);

        $boundaryCode = str_repeat('Я', 1000);
        $item->update(['normative_rate_code' => $boundaryCode]);
        self::assertSame($boundaryCode, $item->fresh()->normative_rate_code);
        unlink($filePath);
    }

    private function createGrandSmetaSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'ГРАНД-Смета');
        $sheet->setCellValue('A3', '1');
        $sheet->setCellValue('B3', '2');
        $sheet->setCellValue('C3', '3');
        $sheet->setCellValue('D3', '4');
        $sheet->setCellValue('G3', '7');
        $sheet->setCellValue('J3', '10');
        $sheet->setCellValue('L3', '12');

        $sheet->setCellValue('A4', 'Раздел 1. Монтажные работы');
        $sheet->setCellValue('A5', '1');
        $sheet->setCellValue('B5', self::LONG_CODE);
        $sheet->setCellValue('C5', 'Погружение дизель-молотом копровой установки на базе экскаватора железобетонных свай длиной: до 6 м в грунты группы 2');
        $sheet->setCellValue('D5', 'шт');
        $sheet->setCellValue('G5', 2);
        $sheet->setCellValue('J5', 100);
        $sheet->setCellValue('L5', 200);

        $filePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'grand-smeta-golden-'.Str::uuid().'.xlsx';
        (new Xlsx($spreadsheet))->save($filePath);
        $spreadsheet->disconnectWorksheets();

        return $filePath;
    }
}
