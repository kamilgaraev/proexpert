<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class EstimateGenerationLegacyCleanupContractTest extends TestCase
{
    #[Test]
    public function obsolete_runtime_classes_and_replay_adapter_are_absent(): void
    {
        $module = dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration';
        foreach ([
            'Benchmark/ProductionReplayBenchmarkAdapter.php',
            'BuildingModel/BuildingModelAssembler.php',
            'BuildingModel/StoredBuildingModel.php',
            'BuildingModel/GeometryBuildingModelInputMapper.php',
            'BuildingModel/DTO/NormalizedBuildingModelData.php',
            'BuildingModel/DTO/GeometryConfirmationData.php',
            'Quantities/NormalizedBuildingModelQuantityInputMapper.php',
            'Quantities/ResidentialQuantityScenarioCatalog.php',
            'Quantities/ResidentialScopeDecisionQuantityMaterializer.php',
            'Services/Normatives/BuildingModelMaterialEvidenceExtractor.php',
        ] as $obsolete) {
            self::assertFileDoesNotExist($module.'/'.$obsolete, $obsolete);
        }
    }

    #[Test]
    public function production_runtime_has_no_obsolete_model_or_table_reference(): void
    {
        $module = dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration';
        $forbidden = [
            'normalized_building_model',
            'ProductionReplayBenchmarkAdapter',
            'repository-production-replay',
            'estimate_generation_sheet_analysis_operations',
            'estimate_generation_geometry_regeneration_outbox',
            'estimate_generation_geometry_confirmations',
            'estimate_generation_building_model_evidence',
            'estimate_generation_building_models',
        ];

        foreach ($this->runtimePhpFiles($module) as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString($needle, $source, $path);
            }
        }
    }

    #[Test]
    public function canonical_project_model_evidence_and_dialogue_remain_available(): void
    {
        $module = dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration';
        foreach ([
            'Domain/ProjectModel/ProjectModelRepository.php',
            'Domain/ProjectModel/EloquentProjectModelRepository.php',
            'Evidence/EvidenceRepository.php',
            'Analysis/AiRoleRunRepository.php',
            'Application/Dialogue/EstimateChangeProposal.php',
        ] as $required) {
            self::assertFileExists($module.'/'.$required, $required);
        }
    }

    /** @return list<string> */
    private function runtimePhpFiles(string $module): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $module,
            FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/migrations/')) {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
