<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Review;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\ListEstimateReviewExceptions;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReviewSummarySnapshot;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EstimateReviewExceptionsPostgresTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_real_postgres_keeps_review_filter_order_cursor_locator_and_tenant_scope_stable(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertContains(DB::connection()->getDatabaseName(), ['most_ai_estimator_contract', 'most_backend_testing']);
        $version = (string) DB::selectOne('SELECT version() AS version')->version;
        self::assertStringStartsWith('PostgreSQL 16.', $version);

        foreach (['technology' => '000700_create_technology_planning_projections', 'completeness' => '000710_create_completeness_planning_projections'] as $kind => $suffix) {
            $table = 'estimate_generation_'.$kind.'_'.($kind === 'technology' ? 'planning' : '').'_runs';
            $table = str_replace('__', '_', $table);
            if (! Schema::hasTable($table)) {
                $migration = glob(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/*_'.$suffix.'.php');
                self::assertCount(1, $migration);
                (require $migration[0])->up();
            }
        }
        DB::beginTransaction();
        try {

            $organization = Organization::factory()->create();
            $user = User::factory()->create(['current_organization_id' => $organization->id]);
            $project = Project::factory()->create(['organization_id' => $organization->id]);
            $items = [];
            foreach (range(1, 205) as $index) {
                $items[] = [
                    'key' => sprintf('stage6:quantity:item-%03d', $index),
                    'local_estimate_key' => 'estimate', 'local_estimate_title' => 'Смета',
                    'section_key' => 'roof', 'section_title' => 'Кровля', 'work_item_key' => sprintf('item-%03d', $index),
                    'work_item' => [
                        'key' => sprintf('item-%03d', $index), 'name' => sprintf('Работа %03d', $index),
                        'confidence' => '0.4', 'total_cost' => (string) (1000 + $index),
                        'metadata' => ['floor' => '2', 'room' => '201'],
                    ],
                    'severity' => $index <= 3 ? 'blocking' : 'warning', 'required_action' => 'confirm_quantity',
                    'reason_codes' => ['quantity_review_required'],
                    'source_refs' => [[
                        'artifact_id' => 77, 'source_version' => 'artifact-v7', 'page_number' => 8,
                        'region' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4],
                        'native_reference' => 'Лист АР-8',
                    ]],
                ];
            }
            $draft = $this->draft($items);
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $user->id,
                'status' => 'ready_to_apply', 'processing_stage' => 'quality_check', 'processing_progress' => 100,
                'input_payload' => [], 'problem_flags' => [], 'state_version' => 7, 'draft_payload' => $draft,
            ]);

            $service = app(ListEstimateReviewExceptions::class);
            $first = $service->handle($session->fresh(), [
                'origin' => 'stage6', 'floor' => '2', 'room' => '201', 'section' => 'Кровля',
                'cost_impact' => 'known', 'unresolved_type' => 'confirm_quantity', 'limit' => 100,
            ]);
            $second = $service->handle($session->fresh(), [
                'origin' => 'stage6', 'floor' => '2', 'room' => '201', 'section' => 'Кровля',
                'cost_impact' => 'known', 'unresolved_type' => 'confirm_quantity', 'limit' => 100,
                'cursor' => $first['meta']['next_cursor'],
            ]);

            self::assertCount(100, $first['items']);
            self::assertCount(100, $second['items']);
            self::assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
            self::assertTrue($first['meta']['canonical_sort']);
            self::assertSame(205, $first['summary']['unresolved']);
            self::assertSame(77, $first['items'][0]['locators'][0]['artifact_id']);
            self::assertSame('Лист АР-8', $first['items'][0]['locators'][0]['native_reference']);

            $foreign = Organization::factory()->create();
            $foreignProject = Project::factory()->create(['organization_id' => $foreign->id]);
            $foreignSession = EstimateGenerationSession::query()->create([
                'organization_id' => $foreign->id, 'project_id' => $foreignProject->id, 'user_id' => $user->id,
                'status' => 'ready_to_apply', 'processing_stage' => 'quality_check', 'processing_progress' => 100,
                'input_payload' => [], 'problem_flags' => [], 'state_version' => 7, 'draft_payload' => $this->draft([]),
            ]);
            self::assertSame([], $service->handle($foreignSession->fresh(), [])['items']);

            DB::statement('SET LOCAL enable_seqscan = off');
            $plan = DB::select('EXPLAIN (FORMAT TEXT) SELECT id FROM estimate_generation_sessions WHERE id = ? AND organization_id = ?', [$session->id, $organization->id]);
            self::assertMatchesRegularExpression('/Index(?: Only)? Scan/', implode("\n", array_map(static fn (object $row): string => (string) $row->{'QUERY PLAN'}, $plan)));
        } finally {
            DB::rollBack();
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    private function draft(array $items): array
    {
        $inputVersion = 'sha256:'.str_repeat('1', 64);
        $draft = ['source_input_version' => $inputVersion, 'local_estimates' => []];
        $contentVersion = ReviewSummarySnapshot::contentVersion($draft);
        $draft['quality_summary'] = [
            'content_version' => $contentVersion,
            'review_queue_items' => $items,
            'review_items' => [
                'classifier_version' => ReviewSummarySnapshot::VERSION,
                'source_version' => $contentVersion,
                'input_version' => $inputVersion,
            ],
        ];

        return $draft;
    }
}
