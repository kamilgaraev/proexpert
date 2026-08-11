<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ReconcileOrganizationAssetsTest extends TestCase
{
    public function test_reconcile_returns_machine_readable_success_report_for_fully_linked_sources(): void
    {
        $context = AdminApiTestContext::create();
        $this->createMachineryAsset((int) $context->organization->id, 'RC-LINKED', 'INV-RC-LINKED');
        self::assertSame(0, Artisan::call('assets:backfill', ['--format' => 'json']));

        $exitCode = Artisan::call('assets:reconcile', ['--format' => 'json']);

        self::assertSame(0, $exitCode);
        self::assertSame([
            'legacy' => 1,
            'linked' => 1,
            'missing' => 0,
            'duplicates' => 0,
            'field_conflicts' => 0,
        ], $this->jsonOutput());
    }

    public function test_reconcile_fails_when_a_legacy_source_is_missing(): void
    {
        $context = AdminApiTestContext::create();
        $this->createMachineryAsset((int) $context->organization->id, 'RC-MISSING', 'INV-RC-MISSING');

        $exitCode = Artisan::call('assets:reconcile', ['--format' => 'json']);
        $report = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertSame(1, $report['legacy']);
        self::assertSame(0, $report['linked']);
        self::assertSame(1, $report['missing']);
        self::assertSame(0, $report['duplicates']);
    }

    public function test_reconcile_detects_duplicate_source_mapping_and_field_conflicts(): void
    {
        $context = AdminApiTestContext::create();
        $legacy = $this->createMachineryAsset((int) $context->organization->id, 'RC-DUP', 'INV-RC-DUP');
        self::assertSame(0, Artisan::call('assets:backfill', ['--format' => 'json']));
        $canonical = OrganizationAsset::query()->sole();
        $canonical->update(['name' => 'Несовпадающее имя']);
        OrganizationAsset::query()->create([
            'organization_id' => $context->organization->id,
            'name' => $legacy->name,
            'inventory_number' => 'INV-RC-DUP-COPY',
            'metadata' => ['legacy_source' => ['table' => 'machinery_assets', 'id' => $legacy->id]],
        ]);

        $exitCode = Artisan::call('assets:reconcile', ['--format' => 'json']);
        $report = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertSame(1, $report['legacy']);
        self::assertSame(0, $report['linked']);
        self::assertSame(0, $report['missing']);
        self::assertSame(1, $report['duplicates']);
        self::assertSame(1, $report['field_conflicts']);
    }

    /**
     * @return array<string, int>
     */
    private function jsonOutput(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function createMachineryAsset(int $organizationId, string $assetCode, string $inventoryNumber): MachineryAsset
    {
        return MachineryAsset::query()->create([
            'organization_id' => $organizationId,
            'asset_code' => $assetCode,
            'name' => 'Техника '.$assetCode,
            'inventory_number' => $inventoryNumber,
            'ownership_type' => 'owned',
            'status' => 'available',
            'operating_cost_per_hour' => 1000,
        ]);
    }
}
