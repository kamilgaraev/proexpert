<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Review;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\ListEstimateReviewExceptions;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReviewSummarySnapshot;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EstimateReviewExceptionsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_persisted_prerequisites_are_scoped_stale_bound_and_not_repeated_after_known_input_without_ai(): void
    {
        Http::fake([]);
        Http::preventStrayRequests();
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
            'status' => 'draft', 'state_version' => 1, 'input_payload' => [], 'draft_payload' => [],
        ]);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $source = 'sha256:'.str_repeat('a', 64);
        $models = app(ProjectModelRepository::class);
        $models->saveSourceModel([
            new Entity('entity:foundation', ...[...$scope, $source, 'quantity', 'entity:foundation', ['semantic_type' => 'quantity']]),
        ], [
            new Fact('fact:foundation-type', ...[...$scope, $source, 'entity:foundation', 'foundation_type',
                'slab', null, 0.0, 'user_assumption', 'confirmed', []]),
        ], []);
        $rules = CompletenessRuleCatalog::fromArray(require base_path('config/estimate-generation-completeness-rules.php'));
        $persist = function () use ($models, $scope, $source, $rules): void {
            $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
            $projection = ['source_version' => $source, 'input_fingerprint' => $capture['token'],
                'catalog_version' => 'technology:v1', 'catalog_hash' => str_repeat('c', 64),
                'rule_catalog_version' => $rules->version, 'rule_catalog_hash' => $rules->contentHash];
            $result = app(ProjectCompletenessAnalyzer::class)->analyze($capture['snapshot'], [], [], $projection);
            self::assertTrue($models->replaceCompleteness(...[...$scope, $source, $capture['token'],
                'technology:v1', str_repeat('c', 64), $rules->version, $rules->contentHash,
                $result->findings, $result->limitations]));
        };
        $persist();
        $service = app(ListEstimateReviewExceptions::class);
        $first = $service->handle($session, ['origin' => 'stage5']);
        $finding = array_values(array_filter($first['items'], static fn (array $item): bool => in_array('base_preparation', $item['codes'], true)))[0];
        $backlog = $finding['recommendation']['prerequisites'];

        self::assertSame(['foundation_area'], array_column($backlog['questions'], 'input_fact_type'));
        self::assertSame('Площадь основания фундамента', $backlog['questions'][0]['label']);
        self::assertSame($source, $backlog['provenance']['source_version']);
        self::assertSame($rules->contentHash, $backlog['provenance']['rule_catalog_hash']);
        self::assertSame($backlog, $service->handle($session, ['origin' => 'stage5'])['items'][array_search($finding['id'], array_column($first['items'], 'id'), true)]['recommendation']['prerequisites']);

        $foreignOrganization = Organization::factory()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignSession = EstimateGenerationSession::query()->create([
            'organization_id' => $foreignOrganization->id, 'project_id' => $foreignProject->id, 'user_id' => $actor->id,
            'status' => 'draft', 'state_version' => 1, 'input_payload' => [], 'draft_payload' => [],
        ]);
        self::assertSame([], $service->handle($foreignSession, ['origin' => 'stage5'])['items']);

        $models->saveSourceModel([], [
            new Fact('fact:foundation-area', ...[...$scope, $source, 'entity:foundation', 'foundation_area',
                '40', 'm2', 0.0, 'user_assumption', 'confirmed', []]),
        ], []);
        self::assertNull($models->currentCompleteness(...$scope));
        self::assertSame([], $service->handle($session, ['origin' => 'stage5'])['items']);
        $persist();
        $updated = $service->handle($session, ['origin' => 'stage5']);
        $current = array_values(array_filter($updated['items'], static fn (array $item): bool => in_array('base_preparation', $item['codes'], true)))[0]['recommendation']['prerequisites'];
        self::assertSame([], $current['questions']);
        self::assertNotSame($backlog['version'], $current['version']);
        Http::assertNothingSent();
        self::assertSame(0, DB::table('estimate_generation_qa_expense_attempts')->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_real_postgres_keeps_review_filter_order_cursor_locator_and_tenant_scope_stable(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]+_testing$/', DB::connection()->getDatabaseName());
        $version = (string) DB::selectOne('SELECT version() AS version')->version;
        self::assertStringStartsWith('PostgreSQL 16.', $version);

        self::assertTrue(Schema::hasTable('estimate_generation_technology_planning_runs'));
        self::assertTrue(Schema::hasTable('estimate_generation_completeness_runs'));
        DB::beginTransaction();
        try {

            $organization = Organization::factory()->create();
            $user = User::factory()->create(['current_organization_id' => $organization->id]);
            $project = Project::factory()->create(['organization_id' => $organization->id]);
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $user->id,
                'status' => 'ready_to_apply', 'processing_stage' => 'quality_check', 'processing_progress' => 100,
                'input_payload' => [], 'problem_flags' => [], 'state_version' => 7, 'draft_payload' => $this->draft([]),
            ]);
            $document = EstimateGenerationDocument::query()->create([
                'session_id' => $session->id, 'organization_id' => $organization->id,
                'project_id' => $project->id, 'user_id' => $user->id,
                'filename' => 'review-source.pdf', 'mime_type' => 'application/pdf',
                'source_version' => 'artifact-v7',
            ]);
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
                        'artifact_id' => (int) $document->id, 'source_version' => 'artifact-v7',
                        'representation_kind' => 'native', 'page_number' => 8,
                        'region' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4],
                        'native_reference' => 'Лист АР-8',
                    ]],
                ];
            }
            $draft = $this->draft($items);
            $session->update(['draft_payload' => $draft]);

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
            self::assertSame($first['summary'], $second['summary']);
            self::assertSame((int) $document->id, $first['items'][0]['locators'][0]['artifact_id']);
            self::assertSame('Лист АР-8', $first['items'][0]['locators'][0]['native_reference']);

            foreach ([
                ['origin' => 'stage5', 'cursor' => $first['meta']['next_cursor'], 'limit' => 100],
                ['origin' => 'stage6', 'cursor' => substr((string) $first['meta']['next_cursor'], 0, -1).'x', 'limit' => 100],
            ] as $invalidCursor) {
                try {
                    $service->handle($session->fresh(), $invalidCursor);
                    self::fail('Changed filter or forged cursor must fail closed.');
                } catch (RuntimeException $exception) {
                    self::assertStringStartsWith('estimate_generation.review_cursor_', $exception->getMessage());
                }
            }

            $foreign = Organization::factory()->create();
            $foreignProject = Project::factory()->create(['organization_id' => $foreign->id]);
            $foreignSession = EstimateGenerationSession::query()->create([
                'organization_id' => $foreign->id, 'project_id' => $foreignProject->id, 'user_id' => $user->id,
                'status' => 'ready_to_apply', 'processing_stage' => 'quality_check', 'processing_progress' => 100,
                'input_payload' => [], 'problem_flags' => [], 'state_version' => 7, 'draft_payload' => $this->draft([]),
            ]);
            self::assertSame([], $service->handle($foreignSession->fresh(), [])['items']);
            try {
                $service->handle($foreignSession->fresh(), ['cursor' => $first['meta']['next_cursor'], 'limit' => 100]);
                self::fail('Cross-session cursor must fail closed.');
            } catch (RuntimeException $exception) {
                self::assertStringStartsWith('estimate_generation.review_cursor_', $exception->getMessage());
            }

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
