<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Modules\Core\ModuleScanner;
use App\Modules\Events\ModuleDiscovered;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use stdClass;
use Tests\TestCase;

final class ModuleScannerOrganizationProjectionTest extends TestCase
{
    public function test_auto_activation_reads_only_organization_ids_and_preserves_fresh_rescans(): void
    {
        Event::fake([ModuleDiscovered::class]);
        $active = $this->createOrganization();
        $existingOrganization = $this->createOrganization();
        $inactive = $this->createOrganization(active: false);
        $deleted = $this->createOrganization(deleted: true);
        $slug = 'scanner-organization-projection-fixture';
        $configuration = [
            'name' => 'Scanner organization projection fixture',
            'slug' => $slug,
            'version' => '1.0.0',
            'type' => 'core',
            'billing_model' => 'free',
            'class_name' => stdClass::class,
            'auto_activate' => true,
        ];
        $module = Module::query()->create(collect($configuration)->except('auto_activate')->all());
        $activation = OrganizationModuleActivation::query()->create([
            'organization_id' => $existingOrganization->id,
            'module_id' => $module->id,
            'status' => 'suspended',
            'activated_at' => now()->subMonth(),
            'expires_at' => now()->subWeek(),
            'cancelled_at' => now()->subDay(),
            'cancellation_reason' => 'projection-fixture',
            'module_settings' => ['preserve' => true],
            'usage_stats' => ['preserve' => 17],
        ]);
        $originalActivation = $activation->refresh()->getRawOriginal();
        $capture = false;
        $projections = [];
        $createdOrganizationIds = [];
        DB::listen(static function (QueryExecuted $query) use (&$capture, &$projections): void {
            if (! $capture || $query->bindings !== [] || preg_match('/^select (.+?) from "organizations"(?:\s|$)/i', $query->sql, $matches) !== 1) {
                return;
            }

            $projections[] = array_map(static fn (string $column): string => trim($column, ' "'), explode(',', $matches[1]));
        });
        OrganizationModuleActivation::created(static function (OrganizationModuleActivation $created) use (&$capture, &$createdOrganizationIds, $module): void {
            if ($capture && (int) $created->module_id === (int) $module->id) {
                $createdOrganizationIds[] = (int) $created->organization_id;
            }
        });
        $configurationPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-module-scanner-'.bin2hex(random_bytes(12));
        $moduleListPath = $configurationPath.DIRECTORY_SEPARATOR.'ModuleList';
        $fixturePath = $moduleListPath.DIRECTORY_SEPARATOR.'projection.json';
        $originalConfigurationPath = $this->app->configPath();
        $this->assertTrue(mkdir($configurationPath));
        $this->assertTrue(mkdir($moduleListPath));

        try {
            $this->assertNotFalse(file_put_contents($fixturePath, json_encode($configuration, JSON_THROW_ON_ERROR)));
            $this->app->useConfigPath($configurationPath);
            $scanner = new ModuleScanner;
            $capture = true;
            $firstScan = $scanner->scanAndRegister();
            $capture = false;
            $this->assertSame([$slug], $firstScan->pluck('slug')->all());
            $this->assertCount(1, $projections);

            $lateOrganization = $this->createOrganization();
            $expectedOrganizationIds = Organization::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $capture = true;
            $secondScan = $scanner->scanAndRegister();
            $capture = false;
            $this->assertSame([$slug], $secondScan->pluck('slug')->all());
            $this->assertCount(2, $projections);
            foreach ([$active, $inactive, $lateOrganization] as $organization) {
                $this->assertDatabaseHas('organization_module_activations', [
                    'organization_id' => $organization->id,
                    'module_id' => $module->id,
                    'status' => 'active',
                    'expires_at' => null,
                ]);
            }
            $this->assertDatabaseMissing('organization_module_activations', [
                'organization_id' => $deleted->id,
                'module_id' => $module->id,
            ]);
            $this->assertSame($originalActivation, $activation->refresh()->getRawOriginal());
            $this->assertSame(4, OrganizationModuleActivation::query()->where('module_id', $module->id)
                ->whereIn('organization_id', [$active->id, $existingOrganization->id, $inactive->id, $deleted->id, $lateOrganization->id])->count());
            $this->assertEqualsCanonicalizing(
                $expectedOrganizationIds,
                OrganizationModuleActivation::query()->where('module_id', $module->id)
                    ->pluck('organization_id')->map(static fn ($id): int => (int) $id)->all(),
            );
            $this->assertEqualsCanonicalizing(
                array_values(array_diff($expectedOrganizationIds, [(int) $existingOrganization->id])),
                $createdOrganizationIds,
            );
            Event::assertDispatchedTimes(ModuleDiscovered::class, 2);
            Event::assertDispatched(ModuleDiscovered::class, static fn (ModuleDiscovered $event): bool => $event->moduleSlug === $slug);
            foreach ($projections as $projection) {
                $this->assertSame(['id'], $projection);
            }
        } finally {
            $capture = false;
            $this->app->useConfigPath($originalConfigurationPath);
            if (is_file($fixturePath)) {
                unlink($fixturePath);
            }
            rmdir($moduleListPath);
            rmdir($configurationPath);
        }
    }

    private function createOrganization(bool $active = true, bool $deleted = false): Organization
    {
        $organization = Organization::factory()->makeOne([
            'is_active' => $active,
            'deleted_at' => $deleted ? now() : null,
            'description' => str_repeat('projection-fixture-', 4096),
            'multi_org_settings' => ['payload' => str_repeat('organization-settings-', 4096)],
        ]);
        $organization->saveQuietly();

        return $organization;
    }
}
