<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceMaterializer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceVerifier;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\SessionBaseInputVersionResolver;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Services\AuthoritativePackagePricingGuard;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class PackagePersistenceStaleFenceTest extends TestCase
{
    private InMemoryEvidenceRepository $evidence;

    private string $connectionName;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $database = $this->app->make('db');
        $this->connectionName = $database->getDefaultConnection();
        $database->purge($this->connectionName);
        $database->extend($this->connectionName, static function (array $config): SQLiteConnection {
            $connection = (new SQLiteConnector)->connect($config);

            return new FinalizerTrackingSqliteConnection(
                $connection,
                (string) ($config['database'] ?? ''),
                (string) ($config['prefix'] ?? ''),
                $config,
            );
        });
        $database->connection($this->connectionName);
        FinalizerTrackingSqliteConnection::$finalizerCalls = 0;
        FinalizerTrackingSqliteConnection::$driverName = 'sqlite';
        FinalizerTrackingSqliteConnection::$forceCardinalityMismatch = false;
        Schema::create('estimate_generation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->timestamps();
        });
        Schema::create('estimate_generation_packages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->string('input_version')->nullable();
            $table->string('key');
            $table->string('title');
            $table->string('scope_type');
            $table->string('status');
            $table->string('generation_stage');
            $table->unsignedInteger('generation_progress');
            $table->unsignedInteger('target_items_min');
            $table->unsignedInteger('target_items_max');
            $table->unsignedInteger('actual_items_count');
            $table->json('totals');
            $table->json('quality_summary');
            $table->json('assumptions');
            $table->json('source_refs');
            $table->json('metadata');
            $table->unsignedInteger('sort_order');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'key']);
        });
        Schema::create('estimate_generation_package_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_id');
            $table->string('key');
            $table->string('logical_key')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('item_type');
            $table->string('parent_key')->nullable();
            $table->unsignedInteger('level')->default(0);
            $table->string('name');
            $table->string('unit')->nullable();
            $table->decimal('quantity', 20, 6)->nullable();
            $table->json('quantity_basis')->nullable();
            $table->string('price_source')->nullable();
            $table->json('price_snapshot')->nullable();
            $table->unsignedBigInteger('quantity_evidence_id')->nullable();
            $table->string('quantity_evidence_fingerprint')->nullable();
            $table->unsignedBigInteger('estimate_norm_id')->nullable();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->unsignedBigInteger('price_zone_id')->nullable();
            $table->unsignedBigInteger('period_id')->nullable();
            $table->unsignedBigInteger('regional_price_version_id')->nullable();
            $table->timestamp('pricing_finalized_at')->nullable();
            $table->string('normative_status')->nullable();
            $table->decimal('normative_confidence', 8, 6)->nullable();
            $table->decimal('unit_price', 20, 6)->default(0);
            $table->decimal('direct_cost', 20, 2)->default(0);
            $table->decimal('overhead_cost', 20, 2)->default(0);
            $table->decimal('profit_cost', 20, 2)->default(0);
            $table->decimal('total_cost', 20, 2)->default(0);
            $table->json('resources');
            $table->json('flags');
            $table->json('metadata');
            $table->unsignedInteger('sort_order');
            $table->unsignedBigInteger('supersedes_item_id')->nullable();
            $table->timestamps();
        });
        Schema::create('estimate_generation_package_item_price_inputs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_item_id');
            $table->unsignedInteger('ordinal');
            $table->unsignedBigInteger('norm_resource_id');
            $table->unsignedBigInteger('resource_price_id');
            $table->unsignedBigInteger('unit_conversion_id')->nullable();
            $table->unsignedBigInteger('pinned_abstract_resource_conversion_id')->nullable();
            $table->timestamps();
        });
        Schema::create('estimate_norm_resources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('estimate_norm_id');
            $table->decimal('quantity', 20, 6);
            $table->string('resource_type');
        });
        Schema::create('estimate_generation_project_material_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('catalog_version');
            $table->string('work_item_key');
            $table->string('assumption_code');
            $table->string('material_unit');
            $table->string('source_unit');
            $table->decimal('quantity_per_work_unit', 30, 12);
            $table->decimal('price_factor', 30, 12);
        });
        Schema::create('estimate_generation_package_item_project_price_inputs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_item_id');
            $table->unsignedBigInteger('project_material_rule_id');
            $table->unsignedBigInteger('resource_price_id');
            $table->unsignedInteger('ordinal');
            $table->json('selection');
            $table->timestamps();
        });
        DB::table('estimate_generation_project_material_rules')->insert([
            'id' => 501,
            'catalog_version' => 'residential_project_material:v3',
            'work_item_key' => 'lighting.fixtures',
            'assumption_code' => 'residential_led_ceiling_luminaire_18w',
            'material_unit' => 'pcs',
            'source_unit' => 'pcs',
            'quantity_per_work_unit' => '1',
            'price_factor' => '1',
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('estimate_generation_package_item_project_price_inputs');
        Schema::dropIfExists('estimate_generation_project_material_rules');
        Schema::dropIfExists('estimate_norm_resources');
        Schema::dropIfExists('estimate_generation_package_item_price_inputs');
        Schema::dropIfExists('estimate_generation_package_items');
        Schema::dropIfExists('estimate_generation_packages');
        Schema::dropIfExists('estimate_generation_sessions');
        $database = $this->app->make('db');
        if ($database instanceof DatabaseManager) {
            $database->purge($this->connectionName);
            $database->forgetExtension($this->connectionName);
        }
        parent::tearDown();
    }

    #[Test]
    public function stale_draft_a_is_blocked_under_locked_current_version_b_before_pricing_or_finalization(): void
    {
        $current = 'sha256:'.str_repeat('b', 64);
        [$session, $resolver, $service] = $this->fixture($current);

        $service->syncFromDraft($session, $this->draft('sha256:'.str_repeat('a', 64), [$this->acceptedWorkItem($session, $current)]));

        $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->where('key', 'stale-package')->sole();
        self::assertSame(1, $resolver->calls);
        self::assertSame([1], $resolver->transactionLevels);
        self::assertSame($current, $package->input_version);
        self::assertSame('blocked', $package->status);
        self::assertSame(['stale_input_version'], $package->quality_summary['critical_flags']);
        self::assertSame(0, $package->items()->count());
        self::assertSame(0, FinalizerTrackingSqliteConnection::$finalizerCalls);
    }

    #[Test]
    public function missing_or_malformed_top_level_version_fails_closed(): void
    {
        foreach ([null, 'invalid'] as $sourceVersion) {
            [$session, $resolver, $service] = $this->fixture('sha256:'.str_repeat('b', 64));
            $draft = $this->draft($sourceVersion, [$this->acceptedWorkItem($session, 'sha256:'.str_repeat('b', 64))]);

            $service->syncFromDraft($session, $draft);

            $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->sole();
            self::assertSame([1], $resolver->transactionLevels);
            self::assertSame('sha256:'.str_repeat('b', 64), $package->input_version);
            self::assertSame('blocked', $package->status);
            self::assertSame(['stale_input_version'], $package->quality_summary['critical_flags']);
            self::assertSame(0, $package->items()->count());
        }
    }

    #[Test]
    public function matching_top_level_version_writes_item_and_executes_pricing_finalizer(): void
    {
        $current = 'sha256:'.str_repeat('b', 64);
        [$session, $resolver, $service] = $this->fixture($current);

        $service->syncFromDraft($session, $this->draft($current, [$this->acceptedWorkItem($session, $current)]));

        $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->sole();
        self::assertSame(1, $resolver->calls);
        self::assertSame([1], $resolver->transactionLevels);
        self::assertSame($current, $package->input_version);
        self::assertNotSame('blocked', $package->status);
        self::assertNotContains('stale_input_version', $package->quality_summary['critical_flags']);
        self::assertSame(1, $package->items()->count());
        self::assertSame(1, FinalizerTrackingSqliteConnection::$finalizerCalls);
        self::assertNotNull($package->items()->sole()->pricing_finalized_at);
        self::assertSame([7001], DB::table('estimate_generation_package_item_price_inputs')->pluck('norm_resource_id')->all());
        self::assertSame([9001], DB::table('estimate_generation_package_item_price_inputs')->pluck('resource_price_id')->all());
    }

    #[Test]
    public function pricing_input_cardinality_mismatch_keeps_item_unfinalized_without_calling_finalizer(): void
    {
        FinalizerTrackingSqliteConnection::$driverName = 'pgsql';
        FinalizerTrackingSqliteConnection::$forceCardinalityMismatch = true;
        $current = 'sha256:'.str_repeat('b', 64);
        [$session, , $service] = $this->fixture($current);

        $service->syncFromDraft($session, $this->draft($current, [$this->acceptedWorkItem($session, $current)]));

        $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->sole();
        $item = $package->items()->sole();
        self::assertSame(0, FinalizerTrackingSqliteConnection::$finalizerCalls);
        self::assertNull($item->pricing_finalized_at);
        self::assertSame('blocked', $package->fresh()->status);
        self::assertContains('missing_price_snapshot', $package->fresh()->quality_summary['critical_flags']);
    }

    #[Test]
    public function unfinalized_item_retries_finalization_for_same_pricing_identity(): void
    {
        $current = 'sha256:'.str_repeat('b', 64);
        [$session, , $service] = $this->fixture($current);
        $draft = $this->draft($current, [$this->acceptedWorkItem($session, $current)]);

        $service->syncFromDraft($session, $draft);
        $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->sole();
        $package->items()->sole()->forceFill(['pricing_finalized_at' => null])->save();
        FinalizerTrackingSqliteConnection::$finalizerCalls = 0;
        $service->syncFromDraft($session, $draft);

        self::assertSame(1, FinalizerTrackingSqliteConnection::$finalizerCalls);
        self::assertNotNull($package->items()->sole()->pricing_finalized_at);
        self::assertNotSame('blocked', $package->fresh()->status);
        self::assertNotContains('missing_price_snapshot', $package->fresh()->quality_summary['critical_flags']);
    }

    #[Test]
    public function quantity_evidence_for_another_work_item_is_not_sent_to_database_finalizer(): void
    {
        $current = 'sha256:'.str_repeat('b', 64);
        [$session, , $service] = $this->fixture($current);

        $workItem = $this->acceptedWorkItem($session, $current, 'persisted-work', 'materialized-work');

        $service->syncFromDraft($session, $this->draft($current, [$workItem]));

        $item = EstimateGenerationPackage::query()->where('session_id', $session->id)->sole()->items()->sole();
        self::assertSame('persisted-work', $item->logical_key);
        self::assertNull($item->pricing_finalized_at);
        self::assertSame(0, FinalizerTrackingSqliteConnection::$finalizerCalls);
        self::assertSame([], DB::table('estimate_generation_package_item_price_inputs')->pluck('norm_resource_id')->all());
    }

    #[Test]
    public function supplementary_project_material_uses_typed_rule_and_is_included_in_finalized_package_total(): void
    {
        $current = 'sha256:'.str_repeat('b', 64);
        [$session, , $service] = $this->fixture($current);
        $workItem = $this->acceptedWorkItem($session, $current);
        $workItem['specialization_scenario'] = [
            'work_item_key' => 'lighting.fixtures',
            'assumption_code' => 'residential_ceiling_luminaire',
        ];
        $selection = [
            'version' => 'residential_project_material:v3',
            'work_item_key' => 'lighting.fixtures',
            'assumption_code' => 'residential_led_ceiling_luminaire_18w',
            'selection_policy' => 'exact_code',
            'source_unit_price' => '2925',
            'source_price_unit' => 'pcs',
            'price_conversion_factor' => '1',
            'preferred_resource_code' => '59.1.20.03-0798',
            'candidate_pool_version' => 'project_material_candidate_pool:v2',
            'candidate_resource_price_ids' => [9101],
        ];
        $workItem['materials'][] = [
            'code' => '59.1.20.03-0798',
            'name' => 'Светильник светодиодный потолочный',
            'unit' => 'pcs',
            'price_unit' => 'pcs',
            'quantity_per_unit' => '1',
            'price_source' => 'fsnb_base',
            'price_source_version' => '2026.1',
            'project_material_selection' => $selection,
            'normative_ref' => [
                'norm_resource_id' => null,
                'price_id' => 9101,
                'resource_code' => '59.1.20.03-0798',
                'price_source' => 'fsnb_base',
                'price_source_version' => '2026.1',
                'project_material_selection' => $selection,
            ],
        ];

        $service->syncFromDraft($session, $this->draft($current, [$workItem]));

        $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->sole();
        $item = $package->items()->sole();
        self::assertNotNull($item->pricing_finalized_at);
        self::assertSame($workItem['specialization_scenario'], data_get($item->metadata, 'specialization_scenario'));
        self::assertSame('supplementary_project_material:v4', data_get($item->price_snapshot, 'coefficients.pricing_formula_version'));
        self::assertSame('321.00', (string) $item->total_cost);
        self::assertSame('321.00', (string) data_get($package->fresh()->totals, 'total_cost'));
        self::assertSame(501, (int) DB::table('estimate_generation_package_item_project_price_inputs')->value('project_material_rule_id'));
        self::assertSame(9101, (int) DB::table('estimate_generation_package_item_project_price_inputs')->value('resource_price_id'));
        $service->assertCalculatedPricesFinalized($session, $this->draft($current, [$workItem]));

        $service->syncFromDraft($session, $this->draft($current, [$workItem]));
        self::assertSame(1, $package->items()->count());

        $item->forceFill(['price_snapshot' => ['coefficients' => ['pricing_formula_version' => 'project_resource:v3']]])->save();
        try {
            $service->assertCalculatedPricesFinalized($session, $this->draft($current, [$workItem]));
            self::fail('A supplementary project material must not accept a positive v3 price as finalized.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('authoritative pricing boundary', $exception->getMessage());
        }

        $item->forceFill([
            'pricing_finalized_at' => null,
            'total_cost' => '0.00',
            'price_snapshot' => ['coefficients' => ['pricing_formula_version' => 'supplementary_project_material:v4']],
        ])->save();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('authoritative pricing boundary');
        $service->assertCalculatedPricesFinalized($session, $this->draft($current, [$workItem]));
    }

    #[Test]
    public function regenerated_draft_supersedes_items_that_are_no_longer_present(): void
    {
        $current = 'sha256:'.str_repeat('b', 64);
        [$session, , $service] = $this->fixture($current);

        $service->syncFromDraft($session, $this->draft($current, [
            $this->acceptedWorkItem($session, $current, 'kept'),
            $this->acceptedWorkItem($session, $current, 'removed'),
        ]));
        DB::table('estimate_generation_package_items')->update(['total_cost' => '1.00']);
        $service->syncFromDraft($session, $this->draft($current, [
            $this->acceptedWorkItem($session, $current, 'kept'),
        ]));

        $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->sole();
        $latestRemoved = $package->items()->where('logical_key', 'removed')->reorder()->orderByDesc('revision')->firstOrFail();

        self::assertSame(
            ['kept:1:priced_work', 'removed:1:priced_work', 'removed:2:operation'],
            $package->items()->orderBy('id')->get()->map(
                static fn ($item): string => $item->logical_key.':'.$item->revision.':'.$item->item_type,
            )->all(),
        );
        self::assertSame(2, $latestRemoved->revision);
        self::assertSame('operation', $latestRemoved->item_type);
        self::assertTrue((bool) data_get($latestRemoved->metadata, 'superseded_by_regeneration'));
        self::assertSame(1, (int) data_get($package->fresh()->totals, 'total_items_count'));
        self::assertSame('1.00', (string) data_get($package->fresh()->totals, 'total_cost'));
    }

    #[Test]
    public function stale_pricing_formula_is_repriced_but_current_formula_is_reused(): void
    {
        $current = 'sha256:'.str_repeat('b', 64);
        [$session, , $service] = $this->fixture($current);
        $draft = $this->draft($current, [$this->acceptedWorkItem($session, $current)]);

        $service->syncFromDraft($session, $draft);
        $service->syncFromDraft($session, $draft);

        $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->sole();
        self::assertSame(1, $package->items()->count());
        self::assertSame(1, FinalizerTrackingSqliteConnection::$finalizerCalls);

        $item = $package->items()->sole();
        $snapshot = $item->price_snapshot;
        $snapshot['coefficients']['pricing_formula_version'] = 'norm_measurement:v1';
        $item->forceFill(['price_snapshot' => $snapshot])->save();

        $service->syncFromDraft($session, $draft);

        self::assertSame(2, $package->items()->count());
        self::assertSame(2, FinalizerTrackingSqliteConnection::$finalizerCalls);
        self::assertSame(
            ['1:norm_measurement:v1', '2:semantic_project_resource:v8'],
            $package->items()->orderBy('revision')->get()->map(
                static fn ($revision): string => $revision->revision.':'.data_get($revision->price_snapshot, 'coefficients.pricing_formula_version'),
            )->all(),
        );
    }

    #[Test]
    public function revision_from_previous_package_input_version_is_not_reused(): void
    {
        $current = 'sha256:'.str_repeat('b', 64);
        [$session, , $service] = $this->fixture($current);
        $draft = $this->draft($current, [$this->acceptedWorkItem($session, $current)]);

        $service->syncFromDraft($session, $draft);
        $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->sole();
        $legacy = $package->items()->sole();
        $metadata = is_array($legacy->metadata) ? $legacy->metadata : [];
        $metadata['source_input_version'] = 'sha256:'.str_repeat('a', 64);
        $legacy->forceFill(['metadata' => $metadata])->save();

        $service->syncFromDraft($session, $draft);

        self::assertSame(2, $package->items()->count());
        self::assertSame(2, FinalizerTrackingSqliteConnection::$finalizerCalls);
        $currentRevision = $package->items()->reorder()->orderByDesc('revision')->firstOrFail();
        self::assertSame($current, data_get($currentRevision->metadata, 'source_input_version'));
        self::assertSame(
            'authoritative_package_pricing:v1',
            data_get($currentRevision->metadata, 'pricing_calculation_identity'),
        );
    }

    #[Test]
    public function revision_from_previous_calculation_contract_is_not_reused(): void
    {
        $current = 'sha256:'.str_repeat('b', 64);
        [$session, , $service] = $this->fixture($current);
        $draft = $this->draft($current, [$this->acceptedWorkItem($session, $current)]);

        $service->syncFromDraft($session, $draft);
        $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->sole();
        $legacy = $package->items()->sole();
        $metadata = is_array($legacy->metadata) ? $legacy->metadata : [];
        $metadata['pricing_calculation_identity'] = 'authoritative_package_pricing:legacy';
        $legacy->forceFill(['metadata' => $metadata])->save();

        $service->syncFromDraft($session, $draft);

        self::assertSame(2, $package->items()->count());
        self::assertSame(2, FinalizerTrackingSqliteConnection::$finalizerCalls);
    }

    #[Test]
    public function nested_local_estimate_version_is_ignored_when_top_level_version_is_current(): void
    {
        $current = 'sha256:'.str_repeat('b', 64);
        [$session, , $service] = $this->fixture($current);
        $draft = $this->draft($current, []);
        $draft['local_estimates'][0]['input_version'] = 'sha256:'.str_repeat('a', 64);

        $service->syncFromDraft($session, $draft);

        self::assertNotSame('blocked', EstimateGenerationPackage::query()->where('session_id', $session->id)->sole()->status);
    }

    #[Test]
    public function work_item_only_sync_uses_same_top_level_version_fence(): void
    {
        $current = 'sha256:'.str_repeat('b', 64);
        [$staleSession, $staleResolver, $staleService] = $this->fixture($current);
        $stale = $this->draft('sha256:'.str_repeat('a', 64), [$this->acceptedWorkItem($staleSession, $current, 'target')]);

        self::assertTrue($staleService->syncWorkItemPackageFromDraft($staleSession, $stale, 'target'));
        self::assertSame(1, $staleResolver->calls);
        self::assertSame([1], $staleResolver->transactionLevels);
        $blocked = EstimateGenerationPackage::query()->where('session_id', $staleSession->id)->sole();
        self::assertSame('blocked', $blocked->status);
        self::assertSame(0, $blocked->items()->count());
        self::assertSame(0, FinalizerTrackingSqliteConnection::$finalizerCalls);

        [$validSession, $validResolver, $validService] = $this->fixture($current);
        $valid = $this->draft($current, [$this->acceptedWorkItem($validSession, $current, 'target')]);

        self::assertTrue($validService->syncWorkItemPackageFromDraft($validSession, $valid, 'target'));
        self::assertSame(1, $validResolver->calls);
        self::assertSame([1], $validResolver->transactionLevels);
        $persisted = EstimateGenerationPackage::query()->where('session_id', $validSession->id)->sole();
        self::assertNotSame('blocked', $persisted->status);
        self::assertSame(1, $persisted->items()->count());
        self::assertSame(1, FinalizerTrackingSqliteConnection::$finalizerCalls);
    }

    private function fixture(string $current): array
    {
        $session = EstimateGenerationSession::query()->create(['organization_id' => 10, 'project_id' => 20]);
        $resolver = new class($current) implements SessionBaseInputVersionResolver
        {
            public int $calls = 0;

            public array $transactionLevels = [];

            public function __construct(private readonly string $current) {}

            public function resolve(EstimateGenerationSession $session): string
            {
                $this->calls++;
                $this->transactionLevels[] = DB::connection()->transactionLevel();

                return $this->current;
            }
        };

        $this->evidence = new InMemoryEvidenceRepository;

        return [$session, $resolver, new EstimateGenerationPackagePersistenceService(
            new AuthoritativePackagePricingGuard(new AcceptedQuantityEvidenceVerifier($this->evidence)),
            baseInputVersions: $resolver,
        )];
    }

    private function draft(?string $sourceVersion, array $workItems): array
    {
        $draft = [
            'local_estimates' => [[
                'key' => 'stale-package', 'title' => 'Package', 'scope_type' => 'walls',
                'sections' => [['work_items' => $workItems]],
            ]],
        ];
        if ($sourceVersion !== null) {
            $draft['source_input_version'] = $sourceVersion;
        }

        return $draft;
    }

    private function acceptedWorkItem(
        EstimateGenerationSession $session,
        string $version,
        string $key = 'must-not-price',
        ?string $evidenceKey = null,
    ): array
    {
        $context = new PipelineContext((int) $session->id, 10, 20, 1, 'sha256:'.str_repeat('f', 64), 'generating', baseInputVersion: $version);
        $quantity = QuantityData::fromArray([
            'key' => 'wall_area', 'unit' => 'm2', 'amount' => '1.000000', 'formula_key' => 'wall.area',
            'formula_version' => 'v1', 'formula_inputs' => [], 'source' => 'evidenced', 'evidence_ids' => ['1'],
            'model_version' => 'building-model:v1', 'assumptions' => [], 'review_blockers' => [],
        ]);
        $item = [
            'key' => $key, 'item_type' => 'priced_work', 'quantity' => '1.000000', 'unit' => 'm2',
            'pricing_status' => 'calculated',
            'normative_match' => ['norm_id' => 101],
            'price_snapshot' => ['region_id' => 16, 'zone_id' => 3, 'period_id' => 8, 'version_id' => 11],
            'materials' => [['normative_ref' => ['norm_resource_id' => 7001, 'price_id' => 9001]]],
            'labor' => [], 'machinery' => [], 'other_resources' => [],
        ];
        $node = (new AcceptedQuantityEvidenceMaterializer($this->evidence))->materialize($context, $quantity, [
            ...$item,
            'key' => $evidenceKey ?? $key,
        ]);

        return [...$item, 'quantity_evidence_id' => $node->id, 'quantity_evidence_fingerprint' => $node->fingerprint];
    }
}

final class FinalizerTrackingSqliteConnection extends SQLiteConnection
{
    public static int $finalizerCalls = 0;

    public static string $driverName = 'sqlite';

    public static bool $forceCardinalityMismatch = false;

    public function getDriverName()
    {
        return self::$driverName;
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if (self::$forceCardinalityMismatch
            && str_contains((string) $query, 'from "estimate_norm_resources"')
            && str_contains((string) $query, '"resource_type" <> ?')) {
            return [(object) ['id' => 7001], (object) ['id' => 7002]];
        }

        if ($query === 'SELECT public.eg_finalize_package_item_price(?)') {
            self::$finalizerCalls++;
            $hasProjectMaterial = $this->table('estimate_generation_package_item_project_price_inputs')
                ->where('package_item_id', (int) $bindings[0])
                ->exists();
            $snapshot = ['coefficients' => ['pricing_formula_version' => $hasProjectMaterial
                ? 'supplementary_project_material:v4'
                : 'semantic_project_resource:v8']];
            $this->table('estimate_generation_package_items')->where('id', (int) $bindings[0])->update([
                'pricing_finalized_at' => '2026-07-13 00:00:00',
                'price_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'unit_price' => $hasProjectMaterial ? '321.000000' : '0.000000',
                'direct_cost' => $hasProjectMaterial ? '321.00' : '0.00',
                'total_cost' => $hasProjectMaterial ? '321.00' : '0.00',
            ]);

            return [];
        }

        return parent::select($query, $bindings, $useReadPdo);
    }
}
