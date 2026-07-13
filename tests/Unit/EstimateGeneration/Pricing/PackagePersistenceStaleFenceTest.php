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
        $session = EstimateGenerationSession::query()->create(['organization_id' => 10, 'project_id' => 20]);
        $current = 'sha256:'.str_repeat('b', 64);
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

        (new EstimateGenerationPackagePersistenceService(baseInputVersions: $resolver))->syncFromDraft($session, [
            'local_estimates' => [[
                'key' => 'stale-package', 'input_version' => 'sha256:'.str_repeat('a', 64),
                'title' => 'Stale', 'scope_type' => 'walls', 'sections' => [['work_items' => [[
                    'key' => 'must-not-price', 'item_type' => 'priced_work', 'quantity' => '1', 'unit' => 'm2',
                    'normative_match' => ['norm_id' => 101],
                    'price_snapshot' => ['region_id' => 16, 'zone_id' => 3, 'period_id' => 8, 'version_id' => 11],
                ]]]],
            ]],
        ]);

        $package = EstimateGenerationPackage::query()->where('session_id', $session->id)->where('key', 'stale-package')->sole();
        self::assertSame(1, $resolver->calls);
        self::assertSame($current, $package->input_version);
        self::assertSame('blocked', $package->status);
        self::assertSame(['stale_input_version'], $package->quality_summary['critical_flags']);
        self::assertSame(0, $package->items()->count());
    }
}
