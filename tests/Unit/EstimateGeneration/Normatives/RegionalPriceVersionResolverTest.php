<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceVersionResolver;
use PHPUnit\Framework\TestCase;

class RegionalPriceVersionResolverTest extends TestCase
{
    public function test_missing_component_is_imported_into_new_revision_without_reopening_active_base(): void
    {
        $active = $this->version(10, '2026-q2-ru-ta', RegionalPriceStatus::ACTIVE, [
            'worker_salary_imported' => true,
            'building_resources_imported' => false,
        ]);

        $key = (new RegionalPriceVersionResolver)->resolveFromVersions(
            [$active],
            '2026-q2-ru-ta',
            'building_resources_imported',
            false,
        );

        self::assertSame('2026-q2-ru-ta-r1', $key);
        self::assertSame(RegionalPriceStatus::ACTIVE, $active->status);
    }

    public function test_existing_writable_revision_is_shared_by_both_import_components(): void
    {
        $revision = $this->version(11, '2026-q2-ru-ta-r1', RegionalPriceStatus::CHECKED, [
            'worker_salary_imported' => true,
        ]);
        $active = $this->version(10, '2026-q2-ru-ta', RegionalPriceStatus::ACTIVE, [
            'worker_salary_imported' => true,
        ]);

        $key = (new RegionalPriceVersionResolver)->resolveFromVersions(
            [$revision, $active],
            '2026-q2-ru-ta',
            'building_resources_imported',
            false,
        );

        self::assertSame('2026-q2-ru-ta-r1', $key);
    }

    public function test_active_version_without_conjuncture_check_rolls_out_one_revision(): void
    {
        $active = $this->version(10, '2026-q2-ru-ta', RegionalPriceStatus::ACTIVE, [
            'worker_salary_imported' => true,
            'building_resources_imported' => true,
        ]);

        $key = (new RegionalPriceVersionResolver)->resolveFromVersions(
            [$active],
            '2026-q2-ru-ta',
            'project_material_conjuncture_checked',
            false,
        );

        self::assertSame('2026-q2-ru-ta-r1', $key);
    }

    public function test_checked_but_incomplete_conjuncture_does_not_create_revision_loop(): void
    {
        $active = $this->version(11, '2026-q2-ru-ta-r1', RegionalPriceStatus::ACTIVE, [
            'worker_salary_imported' => true,
            'building_resources_imported' => true,
            'project_material_conjuncture_checked' => true,
            'project_material_conjuncture_complete' => false,
        ]);

        $key = (new RegionalPriceVersionResolver)->resolveFromVersions(
            [$active],
            '2026-q2-ru-ta',
            'project_material_conjuncture_checked',
            false,
        );

        self::assertSame('2026-q2-ru-ta-r1', $key);
    }

    /** @param array<string, mixed> $metadata */
    private function version(
        int $id,
        string $key,
        RegionalPriceStatus $status,
        array $metadata,
    ): EstimateRegionalPriceVersion {
        $version = new EstimateRegionalPriceVersion;
        $version->setRawAttributes([
            'id' => $id,
            'version_key' => $key,
            'status' => $status->value,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ], true);

        return $version;
    }
}
