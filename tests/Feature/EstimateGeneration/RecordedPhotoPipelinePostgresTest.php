<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverProfile;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\RecordedUniversalPipelineAdapter;

final class RecordedPhotoPipelinePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_actual_photo_upload_quote_vision_publication_and_generation_preserve_unknown_sizes(): void
    {
        $this->replayPhoto('photo-no-scale-v1.json', 1);
    }

    public function test_photo_without_a_description_uses_only_the_selected_scope_and_never_fabricates_measurements_or_a_description_fact(): void
    {
        $this->replayPhoto('photo-only-empty-context-v1.json', 0);
    }

    private function replayPhoto(string $recording, int $descriptionFacts): void
    {
        $image = imagecreatetruecolor(32, 24);
        imagefill($image, 0, 0, imagecolorallocate($image, 235, 235, 235));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $objects = new class($png) implements BenchmarkObjectReader
        {
            public function __construct(private readonly string $png) {}

            public function read(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData|BenchmarkPredictionCaseData $case, string $role, int $maxBytes): string
            {
                if ($role !== 'input') {
                    throw new \LogicException('Prediction cannot read expected.');
                }

                return $this->png;
            }
        };
        $fixture = base_path('tests/Fixtures/EstimateGeneration/universal-replay/'.$recording);
        $adapter = new RecordedUniversalPipelineAdapter($this->app, $objects, $fixture, hash_file('sha256', $fixture));
        $result = $adapter->run(new BenchmarkPredictionCaseData('photo-no-scale', BenchmarkDatasetType::Regression, BenchmarkSourceType::SitePhoto,
            'room.png', hash('sha256', $png), ['photo_no_scale', 'current_repair'], []), 300000);
        self::assertSame('success', $result->status);
        self::assertSame($descriptionFacts, $adapter->lastRun['description_fact_count']);
        if ($descriptionFacts === 0) {
            self::assertNotContains('painting-from-description', $result->prediction['work_ids']);
            self::assertNotEmpty($result->prediction['work_ids']);
            self::assertFalse($adapter->lastRun['evaluation']['can_confirm_for_apply']);
        }
        self::assertSame([], $result->prediction['areas']);
        self::assertSame([], $result->prediction['quantities']);
        self::assertSame([], $result->prediction['costs']);
        $run = $adapter->lastRun['document_run'];
        self::assertSame(['completed'], $run['units']);
        self::assertSame(count(ObserverProfile::cases()), $run['vision_requests']);
        self::assertSame(array_fill(0, $run['vision_requests'], true), $run['provider_ownership_preserved']);
        self::assertSame($run['vision_requests'], $run['vision_requests_after_replay']);
        self::assertSame(0, $run['receipt']['consumed_units_minor']);
        self::assertSame(0, $adapter->lastRun['consumed_units_minor']);
        self::assertSame(1000000, $adapter->lastRun['available_minor']);
        self::assertNotEmpty($adapter->lastRun['evaluation']['priority_questions']);
        self::assertFalse($adapter->lastRun['evaluation']['accuracy_calibrated']);
        self::assertSame($run['vision_requests'] + 2, count($adapter->lastRun['usage']));
        self::assertSame(count($adapter->lastRun['usage']), $adapter->lastRun['usage_after_replay']);
        self::assertGreaterThan(0, $run['receipt']['measured_supplier_cost_micro_rub']);
        self::assertSame(2, DB::table('estimate_generation_credit_operations')->where('session_id', $adapter->lastRun['session_id'])->count());
    }
}
