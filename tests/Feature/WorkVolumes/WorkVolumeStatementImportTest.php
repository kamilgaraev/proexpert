<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementImportService;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\Project;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeStatementImportTest extends TestCase
{
    public function test_file_preview_retains_unresolved_rows_and_registration_uses_verified_source(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $csv = "Работа;Количество;Единица;Место\nСтена;100.000001;м²;А-1\nСтена;неразборчиво;;А-2\nКабель;15;м;А-3\n";
        $file = UploadedFile::fake()->createWithContent('ВОР.csv', $csv);
        $this->mock(FileService::class)->shouldReceive('putPrivate')->once()->andReturnUsing(
            static function (string $key, mixed $stream, string $mime, string $hash) use ($context, $csv): CurrentStoredFile {
                self::assertStringStartsWith('org-'.$context->organization->id.'/work-volume-imports/', $key);
                self::assertSame($csv, stream_get_contents($stream));
                self::assertSame(hash('sha256', $csv), $hash);
                return new CurrentStoredFile($key, 'test-etag', strlen($csv), $hash, $mime);
            },
        );
        $service = app(WorkVolumeStatementImportService::class);
        $options = ['operation_key' => 'wvs-import-1', 'first_data_row' => 2, 'columns' => ['name' => 0, 'quantity' => 1, 'unit_code' => 2, 'place' => 3]];
        $import = $service->stage($context->user, $project->id, $file, $options);
        self::assertSame('draft', $import->status);
        self::assertCount(3, $import->preview_rows);
        self::assertSame('100.000001', $import->preview_rows[0]['quantity']);
        self::assertSame('неразборчиво', $import->preview_rows[1]['quantity']);
        self::assertNotEmpty($import->preview_errors);
        self::assertSame(hash('sha256', $csv), $import->source_file_hash);
        $retry = $service->stage($context->user, $project->id, $file, $options);
        self::assertSame($import->id, $retry->id);
        try {
            $service->register($context->user, $import->id, 1, ['name' => 'Импортированная ВОР']);
            self::fail('Неразобранные строки должны оставаться в черновике импорта');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        $rows = $import->preview_rows;
        $rows[1]['quantity'] = '20.000001';
        $rows[1]['unit_code'] = 'м²';
        $resolved = $service->savePreview($context->user, $import->id, 1, $rows);
        self::assertSame(2, $resolved->preview_version);
        self::assertSame([], $resolved->preview_errors);
        self::assertSame(2, $service->savePreview($context->user, $import->id, 1, $rows)->preview_version);
        foreach ([['version' => 1, 'rows' => $import->preview_rows, 'code' => 409], ['version' => 2, 'rows' => array_slice($rows, 0, 2), 'code' => 422]] as $invalid) {
            try {
                $service->savePreview($context->user, $import->id, $invalid['version'], $invalid['rows']);
                self::fail('Устаревшее исправление или пропавшая строка должны быть отклонены');
            } catch (BusinessLogicException $exception) {
                self::assertSame($invalid['code'], $exception->getCode());
            }
        }
        $statement = $service->register($context->user, $import->id, 2, ['name' => 'Импортированная ВОР']);
        self::assertSame('draft', $statement->status);
        self::assertCount(3, $statement->lines);
        self::assertSame($import->source_file_path, $statement->source_file_path);
        self::assertSame($import->source_file_hash, $statement->source_file_hash);
        self::assertSame($statement->id, $service->register($context->user, $import->id, 2, ['name' => 'Импортированная ВОР'])->id);
        self::assertSame('неразборчиво', $import->fresh()->raw_rows[1]['values'][1]);
        foreach ([
            fn () => DB::table('work_volume_statement_imports')->where('id', $import->id)->update(['source_file_hash' => str_repeat('0', 64)]),
            fn () => DB::table('work_volume_statement_imports')->where('id', $import->id)->update(['raw_rows' => '[]']),
            fn () => DB::table('work_volume_statement_imports')->where('id', $import->id)->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                self::fail('Источник зарегистрированной ведомости нельзя подменить или удалить');
            } catch (QueryException $exception) {
                self::assertSame('55000', $exception->errorInfo[0]);
            }
        }
        try {
            $service->register($context->user, $import->id, 2, ['name' => 'Подмена повтором']);
            self::fail('Повтор регистрации с другим телом должен конфликтовать');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
    }

    public function test_service_rejects_manually_supplied_source_metadata(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(422);
        app(WorkVolumeStatementService::class)->createDraft($context->user, $project->id, ['source_file_path' => 'org-999/private.xlsx', 'lines' => []]);
    }

    public function test_import_preview_recognizes_uuid_duplicates_regardless_of_case(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $key = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $csv = "Стена;10;м²;А-1;".$key."\nСтена;20;м²;А-2;".strtoupper($key)."\n";
        $this->mock(FileService::class)->shouldReceive('putPrivate')->once()->andReturnUsing(
            static fn (string $path, $stream, ?string $mime, string $hash) => new CurrentStoredFile($path, 'etag', strlen($csv), $hash, $mime),
        );
        $service = app(WorkVolumeStatementImportService::class);
        $import = $service->stage($context->user, $project->id, UploadedFile::fake()->createWithContent('duplicates.csv', $csv), [
            'operation_key' => 'duplicate-case', 'first_data_row' => 1,
            'columns' => ['name' => 0, 'quantity' => 1, 'unit_code' => 2, 'place' => 3, 'line_key' => 4],
        ]);
        self::assertContains('duplicate_row', array_column($import->preview_errors, 'code'));
        self::assertSame(strtoupper($key), $import->raw_rows[1]['values'][4]);
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(422);
        $service->register($context->user, $import->id, 1, ['name' => 'Дубли']);
    }
}
