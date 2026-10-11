<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Jobs\PrepareDesignModelViewerJob;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelViewerPreparationService;
use App\BusinessModules\Features\DesignManagement\Services\Contracts\DesignIfcToFragmentsConverterContract;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConversionResult;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use App\Models\Project;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PrepareDesignModelViewerJobTest extends TestCase
{
    public function test_system_upgrade_is_idempotent_and_preserves_published_model_and_nullable_authors(): void
    {
        Bus::fake();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        $derivative = $this->legacyReadyDerivative($version, (int) $context->user->id);
        $derivative->forceFill(['created_by' => null, 'updated_by' => null, 'prepared_by' => null])->save();
        $published = $derivative->derivative_file_path;
        $service = $this->app->make(DesignModelViewerPreparationService::class);

        $this->assertTrue($service->queueIndexUpgrade($derivative));
        $this->assertFalse($service->queueIndexUpgrade($derivative));
        $derivative->refresh();
        $this->assertSame('ready', $derivative->status->value);
        $this->assertSame($published, $derivative->derivative_file_path);
        $this->assertSame('published', $derivative->metadata['generation']);
        $this->assertNull($derivative->created_by);
        $this->assertNull($derivative->updated_by);
        $this->assertNull($derivative->prepared_by);
        Bus::assertDispatchedTimes(PrepareDesignModelViewerJob::class, 1);
    }

    public function test_system_upgrade_rejects_foreign_scope_and_active_converter_lock(): void
    {
        Bus::fake();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        $derivative = $this->legacyReadyDerivative($version, (int) $context->user->id);
        $service = $this->app->make(DesignModelViewerPreparationService::class);
        $other = Project::factory()->create(['organization_id' => $context->organization->id]);
        $derivative->forceFill(['project_id' => $other->id])->save();
        $this->assertFalse($service->queueIndexUpgrade($derivative));
        $derivative->forceFill(['project_id' => $project->id])->save();
        $lock = Cache::lock('design-management:viewer-preparation:'.$derivative->id, 8000);
        $this->assertTrue($lock->get());
        try {
            $this->assertFalse($service->queueIndexUpgrade($derivative));
            Bus::assertNotDispatched(PrepareDesignModelViewerJob::class);
        } finally {
            $lock->release();
        }
    }

    public function test_recovered_upgrade_fences_old_generation_without_losing_published_model(): void
    {
        Bus::fake();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        $derivative = $this->legacyReadyDerivative($version, (int) $context->user->id);
        $this->mock(DesignIfcToFragmentsConverterContract::class, fn (MockInterface $mock) => $mock->shouldNotReceive('convert'));
        $service = $this->app->make(DesignModelViewerPreparationService::class);
        $this->assertTrue($service->queueIndexUpgrade($derivative));
        $oldGeneration = $derivative->refresh()->metadata['index_upgrade']['generation'];
        $derivative->forceFill(['updated_at' => now()->subSeconds(8000)])->save();
        $this->assertTrue($service->queueIndexUpgrade($derivative));
        $service->processQueuedDerivative((int) $derivative->id, $oldGeneration);
        $service->markJobFailed((int) $derivative->id, new RuntimeException('Old job'), $oldGeneration);
        $this->assertSame('queued', $derivative->refresh()->metadata['index_upgrade']['status']);
        $this->assertNotSame($oldGeneration, $derivative->metadata['index_upgrade']['generation']);
        $this->assertSame('ready', $derivative->status->value);
    }

    public function test_changed_upgrade_source_is_rejected_before_conversion_and_keeps_published_model(): void
    {
        Bus::fake();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        $derivative = $this->legacyReadyDerivative($version, (int) $context->user->id);
        $this->mock(DesignIfcToFragmentsConverterContract::class, fn (MockInterface $mock) => $mock->shouldNotReceive('convert'));
        $service = $this->app->make(DesignModelViewerPreparationService::class);
        $this->assertTrue($service->queueIndexUpgrade($derivative));
        $generation = $derivative->refresh()->metadata['index_upgrade']['generation'];
        $published = $derivative->derivative_file_path;
        $version->forceFill(['source_file_path' => 'org-'.$version->organization_id.'/changed.ifc'])->save();
        $service->processQueuedDerivative((int) $derivative->id, $generation);
        $this->assertSame('failed', $derivative->refresh()->metadata['index_upgrade']['status']);
        $this->assertSame('ready', $derivative->status->value);
        $this->assertSame($published, $derivative->derivative_file_path);
        $this->assertSame('published', $derivative->metadata['generation']);
    }

    public function test_scheduled_upgrade_scan_is_bounded_and_continues_after_unqueueable_candidates(): void
    {
        Bus::fake();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $first = $this->storedVersion($this->package($context, $project), $context->user->id);
        $first->forceFill(['source_file_path' => 'org-foreign/unavailable.ifc'])->save();
        $this->legacyReadyDerivative($first, (int) $context->user->id);
        $second = $this->storedVersion($this->package($context, $project), $context->user->id);
        $derivative = $this->legacyReadyDerivative($second, (int) $context->user->id);
        Cache::forget('design-management:ifc-index-upgrade:cursor:2');
        $this->artisan('design-management:upgrade-ifc-index', ['--limit' => 1, '--scan-limit' => 1])
            ->expectsOutput('scanned=1; queued=0; scan_limit=1')->assertSuccessful();
        $this->artisan('design-management:upgrade-ifc-index', ['--limit' => 1, '--scan-limit' => 1])
            ->expectsOutput('scanned=1; queued=1; scan_limit=1')->assertSuccessful();
        $this->assertSame('queued', $derivative->refresh()->metadata['index_upgrade']['status']);
        Bus::assertDispatchedTimes(PrepareDesignModelViewerJob::class, 1);
    }

    public function test_truncated_sidecar_rolls_back_replacement_rows_and_schema_marker(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        $derivative = $this->legacyReadyDerivative($version, (int) $context->user->id);
        $old = DesignIfcModelElement::query()->create(['organization_id' => $version->organization_id, 'project_id' => $version->project_id, 'version_id' => $version->id, 'derivative_id' => $derivative->id, 'express_id' => 9, 'name' => 'Published', 'properties' => []]);
        $path = tempnam(sys_get_temp_dir(), 'ifc-index-test-');
        $rows = [];
        for ($id = 1; $id <= 500; $id++) {
            $rows[] = json_encode(['express_id' => $id, 'name' => 'Replacement']);
        }
        file_put_contents($path, implode("\n", $rows)."\n".'{"express_id":501');
        try {
            try {
                $this->app->make(\App\BusinessModules\Features\DesignManagement\Services\DesignIfcElementIndexer::class)->index($version, $derivative, $path, ['index_schema_version' => 2, 'indexed_element_count' => 501]);
                $this->fail('Truncated IFC index was published.');
            } catch (\JsonException) {
                $this->assertSame(1, DesignIfcModelElement::query()->where('version_id', $version->id)->count());
                $this->assertSame('Published', $old->fresh()->name);
                $this->assertArrayNotHasKey('ifc_index_schema_version', $derivative->refresh()->metadata);
            }
        } finally {
            unlink($path);
        }
    }

    private function legacyReadyDerivative(DesignArtifactVersion $version, int $userId): DesignModelDerivative
    {
        $derivative = $this->queuedDerivative($version, $userId);
        $derivative->forceFill(['status' => 'ready', 'derivative_file_path' => 'org-'.$version->organization_id.'/published/model.frag', 'metadata' => ['converter_version' => DesignViewerConverter::version(), 'generation' => 'published']])->save();

        return $derivative;
    }

    public function test_successful_index_replacement_removes_obsolete_rows_and_preserves_existing_ids(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        $derivative = $this->legacyReadyDerivative($version, (int) $context->user->id);
        $retained = DesignIfcModelElement::query()->create(['organization_id' => $version->organization_id, 'project_id' => $version->project_id, 'version_id' => $version->id, 'derivative_id' => $derivative->id, 'express_id' => 1, 'name' => 'Old', 'properties' => []]);
        $obsolete = DesignIfcModelElement::query()->create(['organization_id' => $version->organization_id, 'project_id' => $version->project_id, 'version_id' => $version->id, 'derivative_id' => $derivative->id, 'express_id' => 2, 'name' => 'Obsolete', 'properties' => []]);
        $path = tempnam(sys_get_temp_dir(), 'ifc-index-test-');
        file_put_contents($path, json_encode(['express_id' => 1, 'name' => 'Replacement', 'properties' => ['_ifc' => ['schema_version' => 2]], 'quantity_units' => ['Q.Volume' => ['name' => 'CUBIC_METRE', 'source' => 'quantity_unit']]])."\n");
        try {
            $this->app->make(\App\BusinessModules\Features\DesignManagement\Services\DesignIfcElementIndexer::class)->index($version, $derivative, $path, ['index_schema_version' => 2, 'indexed_element_count' => 1]);
            $this->assertSame(1, DesignIfcModelElement::query()->where('version_id', $version->id)->count());
            $this->assertSame('Replacement', $retained->fresh()->name);
            $this->assertNull($obsolete->fresh());
            $this->assertSame(2, $derivative->refresh()->metadata['ifc_index_schema_version']);
            $this->assertSame('CUBIC_METRE', $retained->fresh()->properties['quantity_units']['Q.Volume']['name']);
        } finally {
            unlink($path);
        }
    }

    public function test_current_index_is_not_scheduled_again(): void
    {
        Bus::fake();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        $derivative = $this->legacyReadyDerivative($version, (int) $context->user->id);
        $derivative->forceFill(['metadata' => ['converter_version' => DesignViewerConverter::version(), 'ifc_index_schema_version' => 2]])->save();
        $this->assertFalse($this->app->make(DesignModelViewerPreparationService::class)->queueIndexUpgrade($derivative));
        Bus::assertNotDispatched(PrepareDesignModelViewerJob::class);
    }

    public function test_upgrade_upload_failure_keeps_published_index_and_offline_generation(): void
    {
        Bus::fake();
        $this->fakeFileStorage(corruptSidecar: true);
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        Storage::disk('s3')->put($version->source_file_path, 'IFC source');
        $derivative = $this->legacyReadyDerivative($version, (int) $context->user->id);
        $old = DesignIfcModelElement::query()->create(['organization_id' => $version->organization_id, 'project_id' => $version->project_id, 'version_id' => $version->id, 'derivative_id' => $derivative->id, 'express_id' => 9, 'name' => 'Published', 'properties' => []]);
        $published = $derivative->derivative_file_path;
        $this->mock(DesignIfcToFragmentsConverterContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('convert')->once()->andReturnUsing(static function (string $source, string $target): DesignViewerConversionResult {
                file_put_contents($target, 'fragment binary');
                file_put_contents($target.'.ifc-index.ndjson', '{"express_id":42,"properties":{"_ifc":{"schema_version":2}}}' ."\n");

                return DesignViewerConversionResult::fromPayload(['metrics' => [
                    'local_id_count' => 1, 'sample_count' => 1, 'representation_count' => 1,
                    'bounding_box' => ['min' => ['x' => 0, 'y' => 0, 'z' => 0], 'max' => ['x' => 1, 'y' => 1, 'z' => 1]],
                    'runtime' => ['fragments' => '3.4.5', 'web_ifc' => '0.0.77', 'three' => '0.184.0', 'node' => '22.0.0'],
                    'ifc_metadata' => ['index_schema_version' => 2, 'indexed_element_count' => 1],
                ]]);
            });
        });
        $service = $this->app->make(DesignModelViewerPreparationService::class);
        $this->assertTrue($service->queueIndexUpgrade($derivative));
        $service->processQueuedDerivative((int) $derivative->id, $derivative->refresh()->metadata['index_upgrade']['generation']);
        $this->assertSame('ready', $derivative->refresh()->status->value);
        $this->assertSame('failed', $derivative->metadata['index_upgrade']['status']);
        $this->assertSame($published, $derivative->derivative_file_path);
        $this->assertSame('published', $derivative->metadata['generation']);
        $this->assertArrayNotHasKey('ifc_index_schema_version', $derivative->metadata);
        $this->assertSame(1, DesignIfcModelElement::query()->where('version_id', $version->id)->count());
        $this->assertSame('Published', $old->fresh()->name);
    }

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
                    file_put_contents($outputPath.'.ifc-index.ndjson', json_encode([
                        'express_id' => 42,
                        'global_id' => '0A1B2C3D4E5F6G7H8I9J0K',
                        'category' => 'IFCWALL',
                        'name' => 'Wall 42',
                        'properties' => ['Pset_WallCommon' => ['IsExternal' => true]],
                        'quantities' => ['BaseQuantities' => ['Length' => 5000]],
                        'materials' => ['Concrete'],
                        'classifications' => ['Structural'],
                    ], JSON_THROW_ON_ERROR)."\n");

                    return DesignViewerConversionResult::fromPayload([
                        'metrics' => [
                            'format' => 'thatopen_frag',
                            'runtime' => ['fragments' => '3.4.5', 'web_ifc' => '0.0.77', 'three' => '0.184.0', 'node' => '22.0.0'],
                            'local_id_count' => 12,
                            'category_count' => 4,
                            'sample_count' => 24,
                            'representation_count' => 8,
                            'shell_count' => 8,
                            'bounding_box' => [
                                'min' => ['x' => -2.5, 'y' => -1.0, 'z' => 0.0],
                                'max' => ['x' => 8.0, 'y' => 12.0, 'z' => 3.5],
                            ],
                            'ifc_metadata' => [
                                'indexed_element_count' => 1,
                                'units' => [['type' => 'IFCSIUNIT', 'unit_type' => 'LENGTHUNIT', 'name' => 'METRE']],
                                'coordination_matrix' => [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1],
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
        $this->assertSame(DesignViewerConverter::version(), $derivative->metadata['converter_version']);
        $offline = $derivative->metadata['offline_package'];
        $this->assertSame($derivative->metadata['generation'], $offline['generation']);
        $this->assertSame($derivative->derivative_file_path, $offline['geometry']['path']);
        $this->assertSame(hash('sha256', 'fragment binary'), $offline['geometry']['sha256']);
        $this->assertSame('3.4.5', $derivative->metadata['runtime']['fragments']);
        $sidecar = Storage::disk('s3')->get($offline['properties']['path']);
        $this->assertSame(strlen($sidecar), $offline['properties']['size']);
        $this->assertSame(hash('sha256', $sidecar), $offline['properties']['sha256']);
        $canonical = json_decode(trim($sidecar), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(42, $canonical['express_id']);
        $this->assertSame(['BaseQuantities' => ['Length' => 5000]], $canonical['quantities']);
        $this->assertSame(['Concrete'], $canonical['materials']);
        $this->assertSame(['Structural'], $canonical['classifications']);
        $this->assertSame(1, $derivative->metadata['indexed_element_count']);
        $this->assertSame('LENGTHUNIT', $derivative->metadata['ifc_units'][0]['unit_type']);
        $element = DesignIfcModelElement::query()->where('version_id', $version->id)->where('express_id', 42)->firstOrFail();
        $this->assertSame('IFCWALL', $element->category);
        $this->assertSame(true, $element->properties['Pset_WallCommon']['IsExternal']);
        $this->assertSame(['Concrete'], $element->properties['materials']);
        $this->assertSame(['Structural'], $element->classifications);
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

    public function test_stale_queued_and_processing_jobs_are_dispatched_once_after_recovery(): void
    {
        $this->freezeTime();
        Bus::fake();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        $derivative = $this->queuedDerivative($version, $context->user->id);
        $service = $this->app->make(DesignModelViewerPreparationService::class);

        foreach (['queued', 'processing'] as $status) {
            Bus::fake();
            $derivative->refresh();
            $derivative->forceFill([
                'status' => $status,
                'updated_at' => now()->subSeconds(8000),
                'processing_started_at' => $status === 'processing' ? now()->subSeconds(8000) : null,
            ])->save();
            $this->assertTrue($derivative->refresh()->updated_at->lte(now()->subSeconds(8000)));

            $recovered = $service->queuePreparation($version, (int) $context->user->id);
            $service->queuePreparation($version, (int) $context->user->id);

            $this->assertSame('queued', $recovered->status->value);
            $this->assertNull($recovered->processing_started_at);
            Bus::assertDispatchedTimes(PrepareDesignModelViewerJob::class, 1);
        }
    }

    public function test_ready_fragment_without_sidecar_can_be_prepared_for_offline_use(): void
    {
        Bus::fake();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        $derivative = $this->queuedDerivative($version, $context->user->id);
        $derivative->forceFill(['status' => 'ready', 'metadata' => DesignViewerConverter::preparedMetadata()])->save();

        $result = $this->app->make(DesignModelViewerPreparationService::class)->queuePreparation($version, (int) $context->user->id);

        $this->assertSame('queued', $result->status->value);
        Bus::assertDispatchedTimes(PrepareDesignModelViewerJob::class, 1);
    }

    public function test_active_converter_lock_prevents_stale_recovery_and_a_competing_converter(): void
    {
        Bus::fake();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        $derivative = $this->queuedDerivative($version, $context->user->id);
        $derivative->forceFill(['status' => 'processing', 'updated_at' => now()->subSeconds(8000)])->save();
        $this->mock(DesignIfcToFragmentsConverterContract::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('convert');
        });

        $lock = Cache::lock('design-management:viewer-preparation:'.$derivative->id, 8000);
        $this->assertTrue($lock->get());
        try {
            $service = $this->app->make(DesignModelViewerPreparationService::class);
            $result = $service->queuePreparation($version, (int) $context->user->id);
            $service->processQueuedDerivative((int) $derivative->id);
            $service->markJobFailed((int) $derivative->id, new RuntimeException('Older job timed out'));

            $this->assertSame('processing', $result->status->value);
            $this->assertSame('processing', $derivative->refresh()->status->value);
            Bus::assertNotDispatched(PrepareDesignModelViewerJob::class);
        } finally {
            $lock->release();
        }
    }

    public function test_second_job_cannot_convert_while_the_first_converter_is_running(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        Storage::disk('s3')->put($version->source_file_path, 'IFC source');
        $derivative = $this->queuedDerivative($version, $context->user->id);

        $this->mock(DesignIfcToFragmentsConverterContract::class, function (MockInterface $mock) use ($derivative): void {
            $mock->shouldReceive('convert')->once()->andReturnUsing(function () use ($derivative): never {
                $this->app->make(DesignModelViewerPreparationService::class)->processQueuedDerivative((int) $derivative->id);
                throw new RuntimeException('First converter completed its protected section');
            });
        });

        $this->app->make(DesignModelViewerPreparationService::class)->processQueuedDerivative((int) $derivative->id);
        $this->assertSame('failed', $derivative->refresh()->status->value);
    }

    public function test_superseded_job_cannot_convert_or_fail_the_recovered_generation(): void
    {
        Bus::fake();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        $derivative = $this->queuedDerivative($version, $context->user->id);
        $derivative->forceFill(['updated_at' => now()->subSeconds(8000), 'metadata' => ['generation' => 'older-generation']])->save();
        $this->mock(DesignIfcToFragmentsConverterContract::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('convert');
        });
        $service = $this->app->make(DesignModelViewerPreparationService::class);
        $recovered = $service->queuePreparation($version, (int) $context->user->id);

        $service->processQueuedDerivative((int) $derivative->id, 'older-generation');
        $service->markJobFailed((int) $derivative->id, new RuntimeException('Older job failed'), 'older-generation');

        $this->assertSame('queued', $derivative->refresh()->status->value);
        $this->assertSame($recovered->metadata['generation'], $derivative->metadata['generation']);
        $this->assertNotSame('older-generation', $derivative->metadata['generation']);
    }

    public function test_uploaded_sidecar_hash_mismatch_never_publishes_ready_package(): void
    {
        $this->fakeFileStorage(corruptSidecar: true);
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $version = $this->storedVersion($this->package($context, $project), $context->user->id);
        Storage::disk('s3')->put($version->source_file_path, 'IFC source');
        $derivative = $this->queuedDerivative($version, $context->user->id);
        $this->mock(DesignIfcToFragmentsConverterContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('convert')->once()->andReturnUsing(static function (string $source, string $target): DesignViewerConversionResult {
                file_put_contents($target, 'fragment binary');
                file_put_contents($target.'.ifc-index.ndjson', '{"express_id":42,"properties":{},"quantities":{},"materials":[],"classifications":[]}'."\n");

                return DesignViewerConversionResult::fromPayload(['metrics' => [
                    'local_id_count' => 1, 'sample_count' => 1, 'representation_count' => 1,
                    'bounding_box' => ['min' => ['x' => 0, 'y' => 0, 'z' => 0], 'max' => ['x' => 1, 'y' => 1, 'z' => 1]],
                    'runtime' => ['fragments' => '3.4.5', 'web_ifc' => '0.0.77', 'three' => '0.184.0', 'node' => '22.0.0'],
                ]]);
            });
        });

        $this->app->make(DesignModelViewerPreparationService::class)->processQueuedDerivative((int) $derivative->id);

        $this->assertSame('failed', $derivative->refresh()->status->value);
        $this->assertNull($derivative->derivative_file_path);
        $this->assertArrayNotHasKey('offline_package', $derivative->metadata);
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

    private function fakeFileStorage(bool $corruptSidecar = false): void
    {
        Storage::fake('s3');
        $disk = Storage::disk('s3');
        $serviceDisk = $disk;
        if ($corruptSidecar) {
            $serviceDisk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
            $serviceDisk->shouldReceive('put')->andReturnUsing(static fn ($path, $contents, $options) => $disk->put($path, $contents, $options));
            $serviceDisk->shouldReceive('readStream')->andReturnUsing(static function (string $path) use ($disk): mixed {
                if (! str_ends_with($path, '/properties.ndjson')) {
                    return $disk->readStream($path);
                }
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, 'corrupted sidecar');
                rewind($stream);

                return $stream;
            });
        }

        $this->app->forgetInstance(DesignManagementService::class);
        $this->app->forgetInstance(DesignModelViewerPreparationService::class);
        $this->mock(FileService::class, function (MockInterface $mock) use ($serviceDisk): void {
            $mock->shouldReceive('disk')->andReturn($serviceDisk)->byDefault();
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
