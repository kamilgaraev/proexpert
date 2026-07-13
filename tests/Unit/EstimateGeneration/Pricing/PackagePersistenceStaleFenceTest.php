<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\SessionBaseInputVersionResolver;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class PackagePersistenceStaleFenceTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
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
            $table->timestamp('pricing_finalized_at')->nullable();
            $table->decimal('total_cost', 20, 2)->default(0);
        });
    }

    protected function tearDown(): void
    {
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

        $service->syncFromDraft($session, $this->draft('sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('b', 64)));

        $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->where('key', 'stale-package')->sole();
        self::assertSame(1, $resolver->calls);
        self::assertSame($current, $package->input_version);
        self::assertSame('blocked', $package->status);
        self::assertSame(['stale_input_version'], $package->quality_summary['critical_flags']);
        self::assertSame(0, $package->items()->count());
    }

    #[Test]
    public function missing_or_malformed_top_level_version_fails_closed(): void
    {
        foreach ([null, 'invalid'] as $sourceVersion) {
            [$session, , $service] = $this->fixture('sha256:'.str_repeat('b', 64));
            $draft = $this->draft($sourceVersion, 'sha256:'.str_repeat('b', 64));

            $service->syncFromDraft($session, $draft);

            $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->sole();
            self::assertSame('blocked', $package->status);
            self::assertSame(0, $package->items()->count());
        }
    }

    #[Test]
    public function matching_top_level_version_proceeds_and_ignores_local_estimate_version(): void
    {
        $current = 'sha256:'.str_repeat('b', 64);
        [$session, $resolver, $service] = $this->fixture($current);

        $service->syncFromDraft($session, $this->draft($current, 'sha256:'.str_repeat('a', 64), []));

        $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->sole();
        self::assertSame(1, $resolver->calls);
        self::assertSame($current, $package->input_version);
        self::assertNotSame('blocked', $package->status);
        self::assertNotContains('stale_input_version', $package->quality_summary['critical_flags']);
    }

    #[Test]
    public function work_item_only_sync_uses_same_top_level_version_fence(): void
    {
        $current = 'sha256:'.str_repeat('b', 64);
        [$staleSession, $staleResolver, $staleService] = $this->fixture($current);
        $stale = $this->draft('sha256:'.str_repeat('a', 64), $current, [[
            'key' => 'target', 'item_type' => 'operation',
        ]]);

        self::assertTrue($staleService->syncWorkItemPackageFromDraft($staleSession, $stale, 'target'));
        self::assertSame(1, $staleResolver->calls);
        $blocked = EstimateGenerationPackage::query()->where('session_id', $staleSession->id)->sole();
        self::assertSame('blocked', $blocked->status);
        self::assertSame(0, $blocked->items()->count());

        [$validSession, $validResolver, $validService] = $this->fixture($current);
        $valid = $this->draft($current, 'sha256:'.str_repeat('a', 64), [[
            'key' => 'target', 'item_type' => 'operation',
        ]]);

        self::assertTrue($validService->syncWorkItemPackageFromDraft($validSession, $valid, 'target'));
        self::assertSame(1, $validResolver->calls);
        self::assertNotSame('blocked', EstimateGenerationPackage::query()->where('session_id', $validSession->id)->sole()->status);
    }

    private function fixture(string $current): array
    {
        $session = EstimateGenerationSession::query()->create(['organization_id' => 10, 'project_id' => 20]);
        $resolver = new class($current) implements SessionBaseInputVersionResolver
        {
            public int $calls = 0;

            public function __construct(private readonly string $current) {}

            public function resolve(EstimateGenerationSession $session): string
            {
                $this->calls++;

                return $this->current;
            }
        };

        return [$session, $resolver, new EstimateGenerationPackagePersistenceService(baseInputVersions: $resolver)];
    }

    private function draft(?string $sourceVersion, string $localVersion, ?array $workItems = null): array
    {
        $draft = [
            'local_estimates' => [[
                'key' => 'stale-package', 'input_version' => $localVersion,
                'title' => 'Package', 'scope_type' => 'walls', 'sections' => [['work_items' => $workItems ?? [[
                    'key' => 'must-not-price', 'item_type' => 'priced_work', 'quantity' => '1', 'unit' => 'm2',
                    'normative_match' => ['norm_id' => 101],
                    'price_snapshot' => ['region_id' => 16, 'zone_id' => 3, 'period_id' => 8, 'version_id' => 11],
                ]]]],
            ]],
        ];
        if ($sourceVersion !== null) {
            $draft['source_input_version'] = $sourceVersion;
        }

        return $draft;
    }
}
