<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Jobs\PrepareDesignModelViewerJob;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelViewerPreparationService;
use App\BusinessModules\Features\DesignManagement\Services\Contracts\DesignIfcToFragmentsConverterContract;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConversionResult;
use App\Models\Project;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PrepareDesignModelViewerJobTest extends TestCase
{
    public function test_job_converts_ifc_to_frag_and_marks_derivative_ready(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = $this->package($context, $project);
        $version = $this->storedVersion($package, $context->user->id);
        Storage::disk('s3')->put($version->source_file_path, 'IFC source');
        $derivative = $this->queuedDerivative($version, $context->user->id);

        $this->mock(DesignIfcToFragmentsConverterContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('convert')
                ->once()
                ->andReturnUsing(static function (string $sourcePath, string $outputPath, callable $progress): DesignViewerConversionResult {
                    self::assertFileExists($sourcePath);
                    self::assertSame('IFC source', file_get_contents($sourcePath));

                    $progress(45, 'converting');
                    file_put_contents($outputPath, 'fragment binary');

                    return DesignViewerConversionResult::fromPayload([
                        'metrics' => [
                            'format' => 'thatopen_frag',
                            'local_id_count' => 12,
                            'category_count' => 4,
                            'sample_count' => 24,
                            'representation_count' => 8,
                            'shell_count' => 8,
                            'bounding_box' => [
                                'min' => ['x' => -2.5, 'y' => -1.0, 'z' => 0.0],
                                'max' => ['x' => 8.0, 'y' => 12.0, 'z' => 3.5],
                            ],
                        ],
                    ]);
                });
        });

        (new PrepareDesignModelViewerJob((int) $derivative->id))->handle(
            $this->app->make(DesignModelViewerPreparationService::class)
        );

        $derivative->refresh();
        $this->assertSame('ready', $derivative->status->value);
        $this->assertSame(100, $derivative->progress_percent);
        $this->assertSame('ready', $derivative->processing_stage);
        $this->assertNotNull($derivative->prepared_at);
        $this->assertNotNull($derivative->processing_finished_at);
        $this->assertIsString($derivative->derivative_file_path);
        Storage::disk('s3')->assertExists($derivative->derivative_file_path);
        $this->assertSame('fragment binary', Storage::disk('s3')->get($derivative->derivative_file_path));
        $this->assertSame(strlen('IFC source'), $derivative->metadata['source_size_bytes']);
        $this->assertSame(strlen('fragment binary'), $derivative->metadata['derivative_size_bytes']);
        $this->assertSame(4, $derivative->metadata['converter_version']);
        $this->assertSame(12, $derivative->metadata['geometry']['local_id_count']);
        $this->assertSame(24, $derivative->metadata['geometry']['sample_count']);
        $this->assertSame(8, $derivative->metadata['geometry']['representation_count']);
        $this->assertEquals(
            ['x' => -2.5, 'y' => -1.0, 'z' => 0.0],
            $derivative->metadata['geometry']['bounding_box']['min']
        );
    }

    public function test_job_marks_derivative_failed_when_converter_fails(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = $this->package($context, $project);
        $version = $this->storedVersion($package, $context->user->id);
        Storage::disk('s3')->put($version->source_file_path, 'IFC source');
        $derivative = $this->queuedDerivative($version, $context->user->id);

        $this->mock(DesignIfcToFragmentsConverterContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('convert')
                ->once()
                ->andThrow(new RuntimeException('node process failed'));
        });

        (new PrepareDesignModelViewerJob((int) $derivative->id))->handle(
            $this->app->make(DesignModelViewerPreparationService::class)
        );

        $derivative->refresh();
        $this->assertSame('failed', $derivative->status->value);
        $this->assertLessThan(100, $derivative->progress_percent);
        $this->assertSame('failed', $derivative->processing_stage);
        $this->assertSame(
            trans_message('design_management.errors.viewer_preparation_failed'),
            $derivative->failed_reason
        );
        $this->assertNotNull($derivative->processing_finished_at);
        $this->assertNull($derivative->derivative_file_path);
    }

    public function test_job_marks_derivative_failed_when_converter_creates_empty_file(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = $this->package($context, $project);
        $version = $this->storedVersion($package, $context->user->id);
        Storage::disk('s3')->put($version->source_file_path, 'IFC source');
        $derivative = $this->queuedDerivative($version, $context->user->id);

        $this->mock(DesignIfcToFragmentsConverterContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('convert')
                ->once()
                ->andReturnUsing(static function (string $sourcePath, string $outputPath, callable $progress): void {
                    self::assertFileExists($sourcePath);
                    $progress(45, 'converting');
                    file_put_contents($outputPath, '');
                });
        });

        (new PrepareDesignModelViewerJob((int) $derivative->id))->handle(
            $this->app->make(DesignModelViewerPreparationService::class)
        );

        $derivative->refresh();
        $this->assertSame('failed', $derivative->status->value);
        $this->assertSame('failed', $derivative->processing_stage);
        $this->assertNull($derivative->derivative_file_path);
    }

    public function test_job_marks_derivative_failed_when_prepared_file_has_no_renderable_geometry(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = $this->package($context, $project);
        $version = $this->storedVersion($package, $context->user->id);
        Storage::disk('s3')->put($version->source_file_path, 'IFC source');
        $derivative = $this->queuedDerivative($version, $context->user->id);

        $this->mock(DesignIfcToFragmentsConverterContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('convert')
                ->once()
                ->andReturnUsing(static function (string $sourcePath, string $outputPath, callable $progress): DesignViewerConversionResult {
                    self::assertFileExists($sourcePath);
                    $progress(45, 'converting');
                    file_put_contents($outputPath, 'fragment binary');

                    return DesignViewerConversionResult::fromPayload([
                        'metrics' => [
                            'format' => 'thatopen_frag',
                            'local_id_count' => 0,
                            'sample_count' => 0,
                            'representation_count' => 0,
                            'shell_count' => 0,
                            'bounding_box' => null,
                        ],
                    ]);
                });
        });

        (new PrepareDesignModelViewerJob((int) $derivative->id))->handle(
            $this->app->make(DesignModelViewerPreparationService::class)
        );

        $derivative->refresh();
        $this->assertSame('failed', $derivative->status->value);
        $this->assertSame('failed', $derivative->processing_stage);
        $this->assertNull($derivative->derivative_file_path);
        Storage::disk('s3')->assertMissing(
            "org-{$version->organization_id}/pir/projects/{$version->project_id}/packages/{$package->id}/models/{$version->id}/viewer/model.frag"
        );
    }

    private function fakeFileStorage(): void
    {
        Storage::fake('s3');
        $disk = Storage::disk('s3');

        $this->app->forgetInstance(DesignManagementService::class);
        $this->app->forgetInstance(DesignModelViewerPreparationService::class);
        $this->mock(FileService::class, function (MockInterface $mock) use ($disk): void {
            $mock->shouldReceive('disk')->andReturn($disk)->byDefault();
            $mock->shouldReceive('temporaryUrl')->andReturnUsing(
                static fn (?string $path, int $minutes = 5, mixed $organization = null): ?string => $path
                    ? 'https://files.example.test/' . ltrim($path, '/')
                    : null
            )->byDefault();
        });
        $this->app->forgetInstance(DesignManagementService::class);
        $this->app->forgetInstance(DesignModelViewerPreparationService::class);
    }

    private function package(AdminApiTestContext $context, Project $project): DesignPackage
    {
        return DesignPackage::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
            'title' => 'Раздел АР',
            'status' => 'draft',
            'metadata' => [],
        ]);
    }

    private function storedVersion(DesignPackage $package, int $userId): DesignArtifactVersion
    {
        $artifact = $package->artifacts()->create([
            'organization_id' => $package->organization_id,
            'project_id' => $package->project_id,
            'created_by' => $userId,
            'updated_by' => $userId,
            'artifact_type' => 'model',
            'title' => 'Архитектурная модель',
            'status' => 'active',
            'metadata' => [],
        ]);

        return $artifact->versions()->create([
            'organization_id' => $package->organization_id,
            'project_id' => $package->project_id,
            'created_by' => $userId,
            'updated_by' => $userId,
            'uploaded_by' => $userId,
            'title' => 'Архитектурная модель',
            'version_number' => '1',
            'source_format' => 'ifc',
            'source_file_path' => "org-{$package->organization_id}/pir/projects/{$package->project_id}/packages/{$package->id}/models/1/source/building.ifc",
            'source_original_name' => 'building.ifc',
            'source_mime_type' => 'application/octet-stream',
            'source_size_bytes' => 12_000_000,
            'status' => 'uploaded',
            'is_current' => true,
            'metadata' => [],
        ]);
    }

    private function queuedDerivative(DesignArtifactVersion $version, int $userId): DesignModelDerivative
    {
        return DesignModelDerivative::query()->create([
            'organization_id' => $version->organization_id,
            'project_id' => $version->project_id,
            'version_id' => $version->id,
            'created_by' => $userId,
            'updated_by' => $userId,
            'prepared_by' => $userId,
            'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag',
            'status' => 'queued',
            'progress_percent' => 0,
            'processing_stage' => 'queued',
            'metadata' => ['prepared_on' => 'server'],
        ]);
    }
}
