<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageEvidenceRequired;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageDraftPatcher;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuilder;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialProjectMaterialCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TargetedPackageRebuilderTest extends TestCase
{
    public function test_it_rebuilds_only_an_evidenced_heating_package_and_keeps_all_resources(): void
    {
        $draft = $this->draft();
        $original = $draft;
        $resources = $this->createMock(ResourceAssemblyService::class);
        $pricing = $this->createMock(EstimatePricingService::class);

        $resources->expects(self::once())
            ->method('enrich')
            ->willReturnCallback(function (array $workItems, array $regionalContext): array {
                self::assertSame(['heating-work'], array_column($workItems, 'key'));
                self::assertSame(['dataset_version' => '2026-q2-ru'], $regionalContext);

                return [[
                    ...$workItems[0],
                    'materials' => [
                        ['key' => 'water', 'resource_type' => 'water'],
                        ['key' => 'pipe', 'resource_type' => 'pipe'],
                    ],
                    'labor' => [['key' => 'labor', 'resource_type' => 'labor']],
                    'machinery' => [['key' => 'machine', 'resource_type' => 'machine']],
                    'other_resources' => [['key' => 'operator', 'resource_type' => 'operator']],
                ]];
            });
        $pricing->expects(self::once())
            ->method('price')
            ->willReturnCallback(function (array $workItems, array $regionalContext, ?PipelineContext $context): array {
                self::assertSame(['heating-work'], array_column($workItems, 'key'));
                self::assertSame(['dataset_version' => '2026-q2-ru'], $regionalContext);
                self::assertSame(58, $context?->sessionId);
                self::assertSame(89, $context?->organizationId);
                self::assertSame(414, $context?->projectId);
                self::assertSame(3, $context?->stateVersion);
                self::assertSame($this->sourceInputVersion(), $context?->inputVersion);
                self::assertSame('ready', $context?->sessionStatus);
                self::assertSame($this->operationId(), $context?->generationAttemptId);
                self::assertSame($this->sourceInputVersion(), $context?->baseInputVersion);
                self::assertNull($context?->stage);
                self::assertNull($context?->claimToken);
                self::assertSame(['water', 'pipe'], array_column($workItems[0]['materials'], 'key'));
                self::assertSame(['labor'], array_column($workItems[0]['labor'], 'key'));
                self::assertSame(['machine'], array_column($workItems[0]['machinery'], 'key'));
                self::assertSame(['operator'], array_column($workItems[0]['other_resources'], 'key'));

                return [[...$workItems[0], 'pricing_status' => 'calculated', 'total_cost' => '1250.00']];
            });

        $result = (new TargetedPackageRebuilder($resources, $pricing))->rebuild($this->command($draft));

        self::assertSame($this->fingerprint($original['local_estimates'][0]), $result->nonTargetFingerprints['foundation']);
        self::assertSame($original, $draft);
        self::assertSame(['water', 'pipe'], array_column($result->draft['local_estimates'][1]['sections'][0]['work_items'][0]['materials'], 'key'));
        self::assertSame(['labor'], array_column($result->draft['local_estimates'][1]['sections'][0]['work_items'][0]['labor'], 'key'));
        self::assertSame(['machine'], array_column($result->draft['local_estimates'][1]['sections'][0]['work_items'][0]['machinery'], 'key'));
        self::assertSame(['operator'], array_column($result->draft['local_estimates'][1]['sections'][0]['work_items'][0]['other_resources'], 'key'));
    }

    public function test_it_rejects_missing_target_quantity_evidence_before_services_are_called(): void
    {
        $draft = $this->draft();
        unset($draft['local_estimates'][1]['sections'][0]['work_items'][0]['quantity_evidence']);
        $resources = $this->createMock(ResourceAssemblyService::class);
        $pricing = $this->createMock(EstimatePricingService::class);
        $resources->expects(self::never())->method('enrich');
        $pricing->expects(self::never())->method('price');

        $this->expectException(TargetedPackageEvidenceRequired::class);

        (new TargetedPackageRebuilder($resources, $pricing))->rebuild($this->command($draft));
    }

    public function test_it_rejects_a_targeted_verdict_without_evidence_references_before_services_are_called(): void
    {
        $draft = $this->draft();
        $resources = $this->createMock(ResourceAssemblyService::class);
        $pricing = $this->createMock(EstimatePricingService::class);
        $resources->expects(self::never())->method('enrich');
        $pricing->expects(self::never())->method('price');
        $verdict = new ArbiterVerdict('targeted_rebuild', [[
            'action' => 'rebuild',
            'package_keys' => ['heating'],
            'evidence_refs' => [],
        ]]);

        $this->expectException(TargetedPackageEvidenceRequired::class);

        (new TargetedPackageRebuilder($resources, $pricing))->rebuild($this->command($draft, $verdict));
    }

    public function test_it_rejects_an_attempted_remediation_for_another_package_or_stale_source_before_services_are_called(): void
    {
        foreach (['another_package', 'stale_source'] as $case) {
            $draft = $this->draft();
            if ($case === 'another_package') {
                $draft['arbiter_review']['remediation']['target_package_keys'] = ['foundation'];
            } else {
                $draft['source_input_version'] = 'sha256:'.str_repeat('b', 64);
            }
            $resources = $this->createMock(ResourceAssemblyService::class);
            $pricing = $this->createMock(EstimatePricingService::class);
            $resources->expects(self::never())->method('enrich');
            $pricing->expects(self::never())->method('price');

            try {
                (new TargetedPackageRebuilder($resources, $pricing))->rebuild($this->command($draft));
                self::fail($case.' must reject the rebuild.');
            } catch (TargetedPackageEvidenceRequired) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_it_processes_each_target_section_in_order_without_sending_foundation_items_to_services(): void
    {
        $draft = $this->draft(twoHeatingSections: true);
        $resourceCalls = [];
        $pricingCalls = [];
        $resources = $this->createMock(ResourceAssemblyService::class);
        $pricing = $this->createMock(EstimatePricingService::class);
        $resources->expects(self::exactly(2))
            ->method('enrich')
            ->willReturnCallback(function (array $workItems) use (&$resourceCalls): array {
                $resourceCalls[] = array_column($workItems, 'key');

                return $workItems;
            });
        $pricing->expects(self::exactly(2))
            ->method('price')
            ->willReturnCallback(function (array $workItems) use (&$pricingCalls): array {
                $pricingCalls[] = array_column($workItems, 'key');

                return $workItems;
            });

        $result = (new TargetedPackageRebuilder($resources, $pricing))->rebuild($this->command($draft));

        self::assertSame([['heating-work-1'], ['heating-work-2', 'heating-work-3']], $resourceCalls);
        self::assertSame($resourceCalls, $pricingCalls);
        self::assertSame(['foundation-work'], array_column($result->draft['local_estimates'][0]['sections'][0]['work_items'], 'key'));
        self::assertSame(['heating-first', 'heating-second'], array_column($result->draft['local_estimates'][1]['sections'], 'key'));
        self::assertSame(['heating-work-1'], array_column($result->draft['local_estimates'][1]['sections'][0]['work_items'], 'key'));
        self::assertSame(['heating-work-2', 'heating-work-3'], array_column($result->draft['local_estimates'][1]['sections'][1]['work_items'], 'key'));
    }

    #[DataProvider('malformedTargetCandidates')]
    public function test_it_rejects_malformed_target_topology_before_services_are_called(\Closure $mutate): void
    {
        $draft = $this->draft();
        $mutate($draft);
        $resources = $this->createMock(ResourceAssemblyService::class);
        $pricing = $this->createMock(EstimatePricingService::class);
        $resources->expects(self::never())->method('enrich');
        $pricing->expects(self::never())->method('price');

        $this->expectException(TargetedPackageEvidenceRequired::class);

        (new TargetedPackageRebuilder($resources, $pricing))->rebuild($this->command($draft));
    }

    #[DataProvider('invalidReviewFences')]
    public function test_it_rejects_an_invalid_review_fence_before_services_are_called(\Closure $mutate): void
    {
        $draft = $this->draft();
        $mutate($draft);
        $resources = $this->createMock(ResourceAssemblyService::class);
        $pricing = $this->createMock(EstimatePricingService::class);
        $resources->expects(self::never())->method('enrich');
        $pricing->expects(self::never())->method('price');

        $this->expectException(TargetedPackageEvidenceRequired::class);

        (new TargetedPackageRebuilder($resources, $pricing))->rebuild($this->command($draft));
    }

    #[DataProvider('invalidPricedResourceCompositions')]
    public function test_it_fails_closed_when_pricing_changes_an_enriched_resource_composition(\Closure $mutate): void
    {
        $draft = $this->draft();
        $resources = $this->createMock(ResourceAssemblyService::class);
        $pricing = $this->createMock(EstimatePricingService::class);
        $resources->expects(self::once())
            ->method('enrich')
            ->willReturnCallback(static fn (array $workItems): array => [[
                ...$workItems[0],
                'materials' => [
                    ['key' => 'water', 'resource_type' => 'water'],
                    ['key' => 'pipe', 'resource_type' => 'pipe'],
                ],
            ]]);
        $pricing->expects(self::once())
            ->method('price')
            ->willReturnCallback(static fn (array $workItems): array => [$mutate($workItems[0])]);

        $this->expectException(TargetedPackageEvidenceRequired::class);

        (new TargetedPackageRebuilder($resources, $pricing))->rebuild($this->command($draft));
    }

    public function test_it_rejects_a_different_targeted_command_verdict_before_services_are_called(): void
    {
        $draft = $this->draft();
        $resources = $this->createMock(ResourceAssemblyService::class);
        $pricing = $this->createMock(EstimatePricingService::class);
        $resources->expects(self::never())->method('enrich');
        $pricing->expects(self::never())->method('price');
        $differentVerdict = new ArbiterVerdict('targeted_rebuild', [[
            ...$this->targetedReviewFinding(),
            'reason_code' => 'quantity_unconfirmed',
        ]]);

        $this->expectException(TargetedPackageEvidenceRequired::class);

        (new TargetedPackageRebuilder($resources, $pricing))->rebuild($this->command($draft, $differentVerdict));
    }

    public function test_it_allows_a_priced_supplementary_project_material_appended_by_resource_assembly(): void
    {
        $catalog = new ResidentialProjectMaterialCatalog;
        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('electrical.power_lines', 'residential');
        self::assertIsArray($scenario);
        $requirement = $catalog->requirementForIntent(['specialization_scenario' => $scenario]);
        self::assertIsArray($requirement);
        $resource = $catalog->resourceFromPriceRow($requirement, (object) [
            'price_id' => 41,
            'construction_resource_id' => 9,
            'resource_code' => '21.1.06.09-0152',
            'resource_name' => 'Cable',
            'unit' => '1000 м',
            'base_price' => '72000',
            'price_source' => 'regional_catalog',
            'price_source_version' => 'region-16-q2-2026',
        ]);
        self::assertIsArray($resource);
        $draft = $this->draft();
        $workItem = &$draft['local_estimates'][1]['sections'][0]['work_items'][0];
        $workItem['key'] = 'electrical.power_lines';
        $workItem['name'] = 'Cable installation';
        $workItem['normative_rate_code'] = $scenario['normative_rate_code'];
        $workItem['specialization_scenario'] = $scenario;
        $workItem['materials'] = [];
        $workItem['labor'] = [];
        $workItem['machinery'] = [];
        $workItem['other_resources'] = [];
        $workItem['quantity_evidence']['evidence_ids'] = ['evidence:electrical.power_lines'];
        unset($workItem);
        $draft['supplementary_materials'] = [[
            'work_item_key' => 'electrical.power_lines',
            'requirement' => $requirement,
            'status' => 'priced',
            'resource' => $resource,
        ]];
        $resources = $this->createMock(ResourceAssemblyService::class);
        $pricing = $this->createMock(EstimatePricingService::class);
        $resources->expects(self::once())
            ->method('enrich')
            ->willReturnCallback(static fn (array $workItems): array => $workItems);
        $pricing->expects(self::once())
            ->method('price')
            ->willReturnCallback(function (array $workItems): array {
                self::assertSame('21.1.06.09-0152', $workItems[0]['materials'][0]['code']);

                return $workItems;
            });

        $result = (new TargetedPackageRebuilder(
            $resources,
            $pricing,
            new TargetedPackageDraftPatcher,
            new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AssembleMatchedResources($catalog),
        ))->rebuild($this->command($draft));

        self::assertSame('21.1.06.09-0152', $result->draft['local_estimates'][1]['sections'][0]['work_items'][0]['materials'][0]['code']);
    }

    public function test_it_rejects_a_pricing_substitution_of_a_norm_resource_code_with_the_same_resource_key(): void
    {
        $draft = $this->draft();
        $resources = $this->createMock(ResourceAssemblyService::class);
        $pricing = $this->createMock(EstimatePricingService::class);
        $resources->expects(self::once())
            ->method('enrich')
            ->willReturnCallback(static fn (array $workItems): array => [[
                ...$workItems[0],
                'materials' => [[
                    'key' => 'norm-resource-1',
                    'code' => '01.1.01.01-0001',
                    'normative_ref' => [
                        'resource_code' => '01.1.01.01-0001',
                        'resource_id' => 7,
                        'price_id' => 20,
                    ],
                ]],
            ]]);
        $pricing->expects(self::once())
            ->method('price')
            ->willReturnCallback(static fn (array $workItems): array => [[
                ...$workItems[0],
                'materials' => [[
                    ...$workItems[0]['materials'][0],
                    'code' => '01.1.01.01-9999',
                    'normative_ref' => [
                        ...$workItems[0]['materials'][0]['normative_ref'],
                        'resource_code' => '01.1.01.01-9999',
                    ],
                ]],
            ]]);

        $this->expectException(TargetedPackageEvidenceRequired::class);

        (new TargetedPackageRebuilder($resources, $pricing))->rebuild($this->command($draft));
    }

    public function test_it_rejects_a_pricing_substitution_of_an_appended_project_material_code_with_the_same_resource_key(): void
    {
        $catalog = new ResidentialProjectMaterialCatalog;
        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('electrical.power_lines', 'residential');
        self::assertIsArray($scenario);
        $requirement = $catalog->requirementForIntent(['specialization_scenario' => $scenario]);
        self::assertIsArray($requirement);
        $resource = $catalog->resourceFromPriceRow($requirement, (object) [
            'price_id' => 41,
            'construction_resource_id' => 9,
            'resource_code' => '21.1.06.09-0152',
            'resource_name' => 'Cable',
            'unit' => '1000 м',
            'base_price' => '72000',
            'price_source' => 'regional_catalog',
            'price_source_version' => 'region-16-q2-2026',
        ]);
        self::assertIsArray($resource);
        $draft = $this->draft();
        $workItem = &$draft['local_estimates'][1]['sections'][0]['work_items'][0];
        $workItem['key'] = 'electrical.power_lines';
        $workItem['name'] = 'Cable installation';
        $workItem['normative_rate_code'] = $scenario['normative_rate_code'];
        $workItem['specialization_scenario'] = $scenario;
        $workItem['materials'] = [];
        $workItem['labor'] = [];
        $workItem['machinery'] = [];
        $workItem['other_resources'] = [];
        $workItem['quantity_evidence']['evidence_ids'] = ['evidence:electrical.power_lines'];
        unset($workItem);
        $draft['supplementary_materials'] = [[
            'work_item_key' => 'electrical.power_lines',
            'requirement' => $requirement,
            'status' => 'priced',
            'resource' => $resource,
        ]];
        $resources = $this->createMock(ResourceAssemblyService::class);
        $pricing = $this->createMock(EstimatePricingService::class);
        $resources->expects(self::once())
            ->method('enrich')
            ->willReturnCallback(static fn (array $workItems): array => $workItems);
        $pricing->expects(self::once())
            ->method('price')
            ->willReturnCallback(static fn (array $workItems): array => [[
                ...$workItems[0],
                'materials' => [[
                    ...$workItems[0]['materials'][0],
                    'code' => '21.1.06.09-9999',
                    'normative_ref' => [
                        ...$workItems[0]['materials'][0]['normative_ref'],
                        'resource_code' => '21.1.06.09-9999',
                    ],
                ]],
            ]]);

        $this->expectException(TargetedPackageEvidenceRequired::class);

        (new TargetedPackageRebuilder(
            $resources,
            $pricing,
            new TargetedPackageDraftPatcher,
            new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AssembleMatchedResources($catalog),
        ))->rebuild($this->command($draft));
    }

    public function test_production_files_do_not_reference_the_full_generation_pipeline(): void
    {
        $forbidden = [
            'RebuildGeneratedSection',
            'GenerateEstimateDraftJob',
            'DraftPipelineEntrypoint',
            'PipelineRunner',
            'PublishValidatedDraft',
            'syncFromDraft',
            'dispatch',
            'onQueue',
            'EstimateValidationService',
            'PlanWorkItemsStage',
            'BuildDraftStage',
            'ValidateDraftStage',
        ];
        $productionFiles = [
            'TargetedPackageEvidenceRequired.php',
            'TargetedPackageRebuildCommand.php',
            'TargetedPackageRebuilder.php',
        ];

        foreach ($productionFiles as $productionFile) {
            $content = file_get_contents(__DIR__.'/../../../../../app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild/'.$productionFile);
            self::assertIsString($content);
            foreach ($forbidden as $forbiddenReference) {
                self::assertStringNotContainsString($forbiddenReference, $content, $productionFile);
            }
        }
    }

    private function command(array $draft, ?ArbiterVerdict $verdict = null): TargetedPackageRebuildCommand
    {
        return new TargetedPackageRebuildCommand(
            58,
            89,
            414,
            3,
            $this->sourceInputVersion(),
            $this->operationId(),
            $this->arbiterInputHash(),
            'heating',
            $verdict ?? $this->targetedVerdict(),
            'ready',
            $draft,
        );
    }

    private function draft(bool $twoHeatingSections = false): array
    {
        $heatingSections = $twoHeatingSections
            ? [
                $this->section('heating-first', [$this->workItem('heating-work-1')]),
                $this->section('heating-second', [$this->workItem('heating-work-2'), $this->workItem('heating-work-3')]),
            ]
            : [$this->section('heating-section', [$this->workItem('heating-work')])];

        return [
            'source_input_version' => $this->sourceInputVersion(),
            'regional_context' => ['dataset_version' => '2026-q2-ru'],
            'supplementary_materials' => [],
            'local_estimates' => [
                ['key' => 'foundation', 'sections' => [$this->section('foundation-section', [$this->workItem('foundation-work')])]],
                ['key' => 'heating', 'sections' => $heatingSections],
            ],
            'arbiter_review' => [
                'mode' => 'shadow',
                'status' => 'reviewed',
                'outcome' => 'targeted_rebuild',
                'input_hash' => $this->arbiterInputHash(),
                'findings' => [$this->targetedReviewFinding()],
                'cycle' => [
                    'input_hash' => $this->arbiterInputHash(),
                    'attempted' => false,
                    'target_package_keys' => ['heating'],
                    'status' => 'shadow_recommendation',
                    'terminal_outcome' => 'targeted_rebuild',
                ],
                'remediation' => [
                    'root_input_hash' => $this->arbiterInputHash(),
                    'target_package_keys' => ['heating'],
                    'rebuild_attempted' => true,
                    'phase' => 'attempted',
                    'review_outcome' => null,
                ],
            ],
        ];
    }

    private function section(string $key, array $workItems): array
    {
        return ['key' => $key, 'work_items' => $workItems];
    }

    private function workItem(string $key): array
    {
        return [
            'key' => $key,
            'item_type' => 'priced_work',
            'name' => 'Concrete work',
            'quantity' => 1,
            'quantity_evidence' => [
                'evidence_ids' => ['evidence:'.$key],
                'review_blockers' => [],
            ],
            'normative_match' => [
                'status' => 'matched',
                'decision' => ['status' => 'accepted'],
            ],
        ];
    }

    private function targetedVerdict(): ArbiterVerdict
    {
        return new ArbiterVerdict('targeted_rebuild', [$this->targetedReviewFinding()]);
    }

    private function targetedReviewFinding(): array
    {
        return [
            'scope_key' => 'heating',
            'action' => 'rebuild',
            'package_keys' => ['heating'],
            'evidence_refs' => ['evidence:arbiter-heating'],
            'reason_code' => 'missing_component',
        ];
    }

    /** @return iterable<string, array{\Closure}> */
    public static function malformedTargetCandidates(): iterable
    {
        yield 'non-array section' => [static function (array &$draft): void {
            $draft['local_estimates'][1]['sections'][] = 'invalid';
        }];
        yield 'missing section key' => [static function (array &$draft): void {
            unset($draft['local_estimates'][1]['sections'][0]['key']);
        }];
        yield 'duplicate section key' => [static function (array &$draft): void {
            $draft['local_estimates'][1]['sections'][] = $draft['local_estimates'][1]['sections'][0];
        }];
        yield 'non-array work item' => [static function (array &$draft): void {
            $draft['local_estimates'][1]['sections'][0]['work_items'][] = 'invalid';
        }];
        yield 'missing work item key' => [static function (array &$draft): void {
            unset($draft['local_estimates'][1]['sections'][0]['work_items'][0]['key']);
        }];
        yield 'duplicate work item key across sections' => [static function (array &$draft): void {
            $draft['local_estimates'][1]['sections'][] = [
                'key' => 'heating-second',
                'work_items' => [$draft['local_estimates'][1]['sections'][0]['work_items'][0]],
            ];
        }];
    }

    /** @return iterable<string, array{\Closure}> */
    public static function invalidReviewFences(): iterable
    {
        yield 'cycle exhausted' => [static function (array &$draft): void {
            $draft['arbiter_review']['cycle']['status'] = 'cycle_exhausted';
            $draft['arbiter_review']['cycle']['terminal_outcome'] = 'human_review';
            $draft['arbiter_review']['cycle']['target_package_keys'] = [];
        }];
        yield 'malformed cycle attempted type' => [static function (array &$draft): void {
            $draft['arbiter_review']['cycle']['attempted'] = 'false';
        }];
        yield 'review already routes to human review' => [static function (array &$draft): void {
            $draft['arbiter_review']['outcome'] = 'human_review';
        }];
        yield 'review findings lack evidence' => [static function (array &$draft): void {
            $draft['arbiter_review']['findings'][0]['evidence_refs'] = [];
        }];
        yield 'foreign review input hash' => [static function (array &$draft): void {
            $draft['arbiter_review']['input_hash'] = 'sha256:'.str_repeat('c', 64);
        }];
        yield 'unavailable review status' => [static function (array &$draft): void {
            $draft['arbiter_review']['status'] = 'unavailable';
        }];
        yield 'non-shadow review mode' => [static function (array &$draft): void {
            $draft['arbiter_review']['mode'] = 'active';
        }];
    }

    /** @return iterable<string, array{\Closure}> */
    public static function invalidPricedResourceCompositions(): iterable
    {
        yield 'dropped resource' => [static function (array $workItem): array {
            return [...$workItem, 'materials' => [$workItem['materials'][0]]];
        }];
        yield 'reordered resources' => [static function (array $workItem): array {
            return [...$workItem, 'materials' => array_reverse($workItem['materials'])];
        }];
    }

    private function sourceInputVersion(): string
    {
        return 'sha256:'.str_repeat('a', 64);
    }

    private function arbiterInputHash(): string
    {
        return 'sha256:'.str_repeat('b', 64);
    }

    private function operationId(): string
    {
        return '7d2a6a6f-b3c8-4c4a-9c13-f64ea7089b81';
    }

    private function fingerprint(array $package): string
    {
        return 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($package));
    }
}
