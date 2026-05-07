<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\EstimateNormativeQualityService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EstimateNormativeQualityServiceTest extends TestCase
{
    public function test_quality_service_reports_linked_and_unlinked_resources(): void
    {
        $sourceVersionId = $this->createVersion('fsnb_2022', '2026-05-07');
        $ksrVersionId = $this->createVersion('ksr', '2026-02-13');
        $resourceId = $this->createConstructionResource($ksrVersionId);
        $collectionId = $this->createCollection($sourceVersionId);
        $sectionId = $this->createSection($collectionId);
        $normId = $this->createNorm($collectionId, $sectionId);
        $this->createNorm($collectionId, $sectionId, '01-01-001-02');

        $this->createNormResource($normId, $resourceId, '01.1.01.01-0002');
        $this->createNormResource($normId, null, '1-100-34');
        $this->createResourcePrice($sourceVersionId, $resourceId);

        $report = app(EstimateNormativeQualityService::class)->analyze('fsnb_2022', '2026-05-07', 10);

        $this->assertSame(1, $report['totals']['collections']);
        $this->assertSame(1, $report['totals']['sections']);
        $this->assertSame(2, $report['totals']['norms']);
        $this->assertSame(1, $report['totals']['norms_without_resources']);
        $this->assertSame(2, $report['totals']['norm_resources']);
        $this->assertSame(1, $report['totals']['linked_norm_resources']);
        $this->assertSame(1, $report['totals']['unlinked_norm_resources']);
        $this->assertSame(50.0, $report['totals']['link_rate_percent']);
        $this->assertSame('1-100-34', $report['top_unlinked_resources'][0]['resource_code']);
    }

    public function test_quality_command_accepts_existing_version(): void
    {
        $sourceVersionId = $this->createVersion('fsnb_2022', '2026-05-07');
        $collectionId = $this->createCollection($sourceVersionId);
        $sectionId = $this->createSection($collectionId);
        $this->createNorm($collectionId, $sectionId);

        $this->artisan('estimates:normatives:quality', [
            '--source' => 'fsnb_2022',
            '--version-key' => '2026-05-07',
            '--limit' => 5,
        ])->assertExitCode(0);
    }

    private function createVersion(string $sourceType, string $versionKey): int
    {
        return (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => $sourceType,
            'version_key' => $versionKey,
            'bucket' => 'test-bucket',
            'prefix' => 'test-prefix',
            'status' => 'parsed',
            'files_count' => 1,
            'rows_read' => 1,
            'rows_imported' => 1,
            'errors_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createConstructionResource(int $versionId): int
    {
        return (int) DB::table('construction_resources')->insertGetId([
            'dataset_version_id' => $versionId,
            'ksr_code' => '01.1.01.01-0002',
            'name' => 'Материал',
            'unit' => '100 компл',
            'resource_type' => 'material',
            'okpd2_code' => '23.65.12.190',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCollection(int $versionId): int
    {
        return (int) DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $versionId,
            'code' => 'gesn',
            'name' => 'ГЭСН',
            'norm_type' => 'gesn',
            'source_file' => 'ГЭСН.xml',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSection(int $collectionId): int
    {
        return (int) DB::table('estimate_norm_sections')->insertGetId([
            'collection_id' => $collectionId,
            'parent_id' => null,
            'code' => '01',
            'name' => 'Сборник',
            'section_type' => 'Сборник',
            'depth' => 0,
            'path' => 'section-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createNorm(int $collectionId, int $sectionId, string $code = '01-01-001-01'): int
    {
        return (int) DB::table('estimate_norms')->insertGetId([
            'collection_id' => $collectionId,
            'section_id' => $sectionId,
            'code' => $code,
            'name' => 'Работа',
            'unit' => 'шт',
            'section_code' => '01-01-001',
            'section_name' => 'Таблица',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createNormResource(int $normId, ?int $resourceId, string $code): void
    {
        DB::table('estimate_norm_resources')->insert([
            'estimate_norm_id' => $normId,
            'construction_resource_id' => $resourceId,
            'resource_code' => $code,
            'resource_name' => 'Ресурс ' . $code,
            'unit' => 'шт',
            'quantity' => 1,
            'resource_type' => 'material',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createResourcePrice(int $versionId, int $resourceId): void
    {
        DB::table('estimate_resource_prices')->insert([
            'dataset_version_id' => $versionId,
            'construction_resource_id' => $resourceId,
            'resource_code' => '01.1.01.01-0002',
            'resource_name' => 'Материал',
            'unit' => '100 компл',
            'base_price' => 123.45,
            'price_type' => 'material',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
