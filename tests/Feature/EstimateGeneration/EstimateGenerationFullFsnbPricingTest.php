<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\NormativeSearchProfileData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSearchProfileCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsEstimateGenerationPipeline;
use Tests\TestCase;

final class EstimateGenerationFullFsnbPricingTest extends TestCase
{
    use RunsEstimateGenerationPipeline;

    private const TARGET_TOTAL_COST = 12000000.0;

    public function test_house_draft_prices_every_source_backed_position_with_safe_fsnb_norms(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $input = [
            'description' => 'house 150 m2, two floors, roof, facade, engineering systems',
            'building_type' => 'house',
            'area' => 150,
            'regional_context' => [
                'region_name' => 'Test region',
                'year' => 2026,
                'quarter' => 1,
                'version_key' => '2026-q1-test',
            ],
        ];
        $analysis = app(ConstructionSemanticParser::class)->parse($input, []);
        $plannedItems = $this->plannedWorkItems($analysis);

        self::assertGreaterThanOrEqual(55, count($plannedItems));

        $analysis = $this->withTakeoffsForPlannedItems($analysis, $plannedItems);
        $plannedItems = $this->plannedWorkItems($analysis);

        $this->seedFsnbCatalogForWorkItems($plannedItems);

        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'generating',
            'processing_stage' => 'object_analysis',
            'processing_progress' => 35,
            'input_payload' => [...$input, 'generation_attempt_id' => 'full-fsnb-generation'],
            'analysis_payload' => $analysis,
            'problem_flags' => [],
        ]);

        $session = $this->runGenerationPipeline($session);
        $draft = $session->draft_payload;
        $quality = $draft['quality_summary'];
        $normativeItems = $quality['normative_items'];
        $pricedDenominator = $quality['total_work_items'] - ($quality['operation_work_items'] ?? 0);

        self::assertSame(
            $pricedDenominator,
            $quality['priced_work_items'],
            json_encode($this->unpricedItems($draft), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );
        self::assertSame(0, $quality['safe_norm_required_work_items']);
        self::assertSame(0, $quality['not_calculated_work_items']);
        self::assertSame(0, $quality['market_estimate_work_items']);
        self::assertSame(
            $pricedDenominator,
            $normativeItems['accepted'] + $normativeItems['review_priced']
        );
        self::assertSame(0, $normativeItems['requires_review']);
        self::assertSame(0, $normativeItems['candidate_only']);
        self::assertSame(0, $normativeItems['not_found']);
        self::assertSame(0, $normativeItems['unit_mismatch']);
        self::assertSame(0, $normativeItems['scope_mismatch']);
        self::assertNotContains('missing_price', $quality['critical_flags']);
        self::assertNotContains('missing_resources', $quality['critical_flags']);
        self::assertNotContains('total_out_of_range', $quality['critical_flags']);
        self::assertNotContains('line_total_anomaly', $quality['critical_flags']);
        self::assertContains($session->status, [
            EstimateGenerationStatus::EstimateReviewRequired,
            EstimateGenerationStatus::ReadyToApply,
        ]);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<int, array{item: array<string, mixed>, context: array<string, mixed>}>  $plannedItems
     * @return array<string, mixed>
     */
    private function withTakeoffsForPlannedItems(array $analysis, array $plannedItems): array
    {
        $takeoffs = [];

        foreach ($plannedItems as $index => $payload) {
            $item = $payload['item'];
            $quantityKey = (string) ($item['quantity_formula'] ?? '');

            if ($quantityKey === '') {
                continue;
            }

            $takeoffs[] = [
                'scope_key' => $quantityKey,
                'quantity_key' => $quantityKey,
                'name' => (string) ($item['name'] ?? $quantityKey),
                'quantity' => max((float) ($item['quantity'] ?? 1), 1.0),
                'unit' => (string) ($item['unit'] ?? 'компл'),
                'confidence' => 0.94,
                'source_refs' => [[
                    'type' => 'test_takeoff',
                    'value' => 'takeoff-'.($index + 1),
                ]],
                'normalized_payload' => [
                    'quantity_key' => $quantityKey,
                    'unit' => (string) ($item['unit'] ?? 'компл'),
                    'review_required' => false,
                ],
            ];
        }

        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $documentContext['quantity_takeoffs'] = $takeoffs;
        $analysis['document_context'] = $documentContext;

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<int, array{item: array<string, mixed>, context: array<string, mixed>}>
     */
    private function plannedWorkItems(array $analysis): array
    {
        $planner = app(PackagePlannerService::class);
        $plan = $planner->plan($planner->profileFromAnalysis($analysis));
        $localEstimates = app(EstimateDecompositionService::class)->decomposePackagePlan($analysis, $plan);
        $workItems = [];

        foreach ($localEstimates as $localEstimate) {
            foreach ($localEstimate['sections'] as $section) {
                $context = [
                    'scope_type' => $localEstimate['scope_type'] ?? null,
                    'section_title' => $section['title'] ?? null,
                    'local_estimate_title' => $localEstimate['title'] ?? null,
                    'regional_context' => $analysis['regional_context'] ?? [],
                ];

                foreach (app(NormativeWorkItemPlannerService::class)->build($localEstimate, $section, $analysis) as $item) {
                    if (($item['item_type'] ?? null) !== 'priced_work') {
                        continue;
                    }

                    $workItems[] = [
                        'item' => $item,
                        'context' => $context,
                    ];
                }
            }
        }

        return $workItems;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<int, array<string, mixed>>
     */
    private function unpricedItems(array $draft): array
    {
        $items = [];

        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            foreach ($localEstimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $workItem) {
                    if (($workItem['item_type'] ?? null) !== 'priced_work') {
                        continue;
                    }

                    if ((float) ($workItem['total_cost'] ?? 0) > 0) {
                        continue;
                    }

                    $items[] = [
                        'key' => $workItem['key'] ?? null,
                        'name' => $workItem['name'] ?? null,
                        'search' => $workItem['normative_search_text'] ?? null,
                        'unit' => $workItem['unit'] ?? null,
                        'intent' => $workItem['work_intent'] ?? null,
                        'flags' => $workItem['validation_flags'] ?? [],
                        'status' => $workItem['normative_match']['status'] ?? null,
                        'warnings' => $workItem['normative_match']['warnings'] ?? [],
                        'candidates' => array_slice($workItem['normative_candidates'] ?? [], 0, 2),
                        'seeded_norms' => DB::table('estimate_norms')
                            ->where('name', (string) ($workItem['normative_search_text'] ?? $workItem['name'] ?? ''))
                            ->get(['code', 'unit', 'section_code'])
                            ->map(static fn (object $row): array => (array) $row)
                            ->all(),
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<int, array{item: array<string, mixed>, context: array<string, mixed>}>  $plannedItems
     */
    private function seedFsnbCatalogForWorkItems(array $plannedItems): void
    {
        $versionId = $this->createVersion('fsnb_2022', '2026-q1-test');
        $priceVersionId = $this->createVersion('fsbc', '2026-q1-test');
        $collectionId = $this->createCollection($versionId);
        $sectionIds = [];
        $targetLineTotal = self::TARGET_TOTAL_COST / max(count($plannedItems), 1);

        foreach ($plannedItems as $index => $payload) {
            $item = $payload['item'];
            $context = $payload['context'];
            $matchingItem = $this->matchingItem($item);
            $intent = app(WorkIntentClassifier::class)->classify($matchingItem, $context);
            $profile = app(NormativeSearchProfileCatalog::class)->forIntentData($intent);
            $sectionPrefix = $this->sectionPrefixFor($profile, $intent);
            $sectionIds[$sectionPrefix] ??= $this->createSection($collectionId, $sectionPrefix);
            $normId = $this->createNorm(
                $collectionId,
                $sectionIds[$sectionPrefix],
                $this->normCode($sectionPrefix, $index),
                (string) ($matchingItem['name'] ?? $item['name']),
                (string) $item['unit'],
                $sectionPrefix
            );
            $resourceCode = sprintf('test.%04d', $index + 1);
            $quantity = max((float) ($item['quantity'] ?? 1), 1.0);
            $unitPrice = round($targetLineTotal / $quantity, 6);

            $this->createNormResource($normId, $resourceCode, (string) $item['unit']);
            $this->createResourcePrice($priceVersionId, $resourceCode, (string) $item['unit'], max($unitPrice, 0.01));
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function matchingItem(array $item): array
    {
        if (($item['normative_search_text'] ?? null) === null) {
            return $item;
        }

        return [
            ...$item,
            'name' => $item['normative_search_text'],
            'description' => $item['normative_search_text'],
        ];
    }

    private function sectionPrefixFor(NormativeSearchProfileData $profile, WorkIntentData $intent): string
    {
        $forbidden = $intent->forbiddenSectionPrefixes;
        $candidates = [
            ...$profile->allowedSectionPrefixes,
            ...$intent->preferredSectionPrefixes,
            '01',
            '06',
            '07',
            '08',
            '09',
            '10',
            '12',
            '15',
            '16',
            '18',
            '20',
            '26',
            '27',
        ];

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if ($candidate !== '' && ! $this->startsWithAny($candidate, $forbidden)) {
                return $candidate;
            }
        }

        return '01';
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    private function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function normCode(string $sectionPrefix, int $index): string
    {
        return sprintf('%s-%02d-%03d-01', str_pad($sectionPrefix, 2, '0', STR_PAD_LEFT), ($index % 90) + 1, $index + 1);
    }

    private function createVersion(string $sourceType, string $versionKey): int
    {
        return (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => $sourceType,
            'version_key' => $versionKey,
            'bucket' => 'test-bucket',
            'prefix' => 'test-prefix',
            'status' => 'parsed',
            'files_count' => 1,
            'rows_read' => 1,
            'rows_imported' => 1,
            'errors_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCollection(int $versionId): int
    {
        return (int) DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $versionId,
            'code' => 'gesn',
            'name' => 'GESN',
            'norm_type' => 'gesn',
            'source_file' => 'GESN.xml',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSection(int $collectionId, string $code): int
    {
        return (int) DB::table('estimate_norm_sections')->insertGetId([
            'collection_id' => $collectionId,
            'parent_id' => null,
            'code' => $code,
            'name' => 'Section '.$code,
            'section_type' => 'collection',
            'depth' => 0,
            'path' => $code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createNorm(int $collectionId, int $sectionId, string $code, string $name, string $unit, string $sectionPrefix): int
    {
        return (int) DB::table('estimate_norms')->insertGetId([
            'collection_id' => $collectionId,
            'section_id' => $sectionId,
            'code' => $code,
            'name' => $name,
            'unit' => $unit,
            'section_code' => $sectionPrefix.'-00-000',
            'section_name' => 'Section '.$sectionPrefix,
            'work_composition' => json_encode([$name], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createNormResource(int $normId, string $code, string $unit): void
    {
        DB::table('estimate_norm_resources')->insert([
            'estimate_norm_id' => $normId,
            'construction_resource_id' => null,
            'resource_code' => $code,
            'resource_name' => 'Resource '.$code,
            'unit' => $unit,
            'quantity' => 1.0,
            'resource_type' => 'material',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createResourcePrice(int $versionId, string $code, string $unit, float $price): void
    {
        DB::table('estimate_resource_prices')->insert([
            'dataset_version_id' => $versionId,
            'construction_resource_id' => null,
            'resource_code' => $code,
            'resource_name' => 'Resource '.$code,
            'unit' => $unit,
            'base_price' => $price,
            'price_type' => 'material',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
