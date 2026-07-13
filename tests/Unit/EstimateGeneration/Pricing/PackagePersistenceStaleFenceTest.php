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
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class PackagePersistenceStaleFenceTest extends TestCase
{
    private InMemoryEvidenceRepository $evidence;

    public function createApplication()
    {
        Connection::resolverFor('sqlite', static fn (mixed $connection, string $database = '', string $prefix = '', array $config = []): SQLiteConnection => new FinalizerTrackingSqliteConnection(
            $connection,
            $database,
            $prefix,
            $config,
        ));
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        FinalizerTrackingSqliteConnection::$finalizerCalls = 0;
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
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('estimate_generation_package_item_price_inputs');
        Schema::dropIfExists('estimate_generation_package_items');
        Schema::dropIfExists('estimate_generation_packages');
        Schema::dropIfExists('estimate_generation_sessions');
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

    private function acceptedWorkItem(EstimateGenerationSession $session, string $version, string $key = 'must-not-price'): array
    {
        $context = new PipelineContext((int) $session->id, 10, 20, 1, 'sha256:'.str_repeat('f', 64), 'generating', baseInputVersion: $version);
        $quantity = QuantityData::fromArray([
            'key' => 'wall_area', 'unit' => 'm2', 'amount' => '1.000000', 'formula_key' => 'wall.area',
            'formula_version' => 'v1', 'formula_inputs' => [], 'source' => 'evidenced', 'evidence_ids' => ['1'],
            'model_version' => 'building-model:v1', 'assumptions' => [], 'review_blockers' => [],
        ]);
        $item = [
            'key' => $key, 'item_type' => 'priced_work', 'quantity' => '1.000000', 'unit' => 'm2',
            'normative_match' => ['norm_id' => 101],
            'price_snapshot' => ['region_id' => 16, 'zone_id' => 3, 'period_id' => 8, 'version_id' => 11],
            'materials' => [['normative_ref' => ['norm_resource_id' => 7001, 'price_id' => 9001]]],
            'labor' => [], 'machinery' => [], 'other_resources' => [],
        ];
        $node = (new AcceptedQuantityEvidenceMaterializer($this->evidence))->materialize($context, $quantity, $item);

        return [...$item, 'quantity_evidence_id' => $node->id, 'quantity_evidence_fingerprint' => $node->fingerprint];
    }
}

final class FinalizerTrackingSqliteConnection extends SQLiteConnection
{
    public static int $finalizerCalls = 0;

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if ($query === 'SELECT public.eg_finalize_package_item_price(?)') {
            self::$finalizerCalls++;
            $this->table('estimate_generation_package_items')->where('id', (int) $bindings[0])->update([
                'pricing_finalized_at' => '2026-07-13 00:00:00',
            ]);

            return [];
        }

        return parent::select($query, $bindings, $useReadPdo);
    }
}
