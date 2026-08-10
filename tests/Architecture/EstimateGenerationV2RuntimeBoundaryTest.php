<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class EstimateGenerationV2RuntimeBoundaryTest extends TestCase
{
    #[DataProvider('forbiddenRuntimeContracts')]
    public function test_removed_infrastructure_is_absent_from_production_runtime(string $symbol): void
    {
        $matches = [];

        foreach ($this->productionFiles() as $path => $source) {
            if (str_contains($source, $symbol)) {
                $matches[] = $path;
            }
        }

        self::assertSame([], $matches, $symbol.' remains in: '.implode(', ', $matches));
    }

    public static function forbiddenRuntimeContracts(): iterable
    {
        yield 'internal AI attempt budget authorizer' => ['AiAttemptBudgetAuthorizer'];
        yield 'internal AI budget guard' => ['AiBudgetGuard'];
        yield 'internal AI budget reconciliation job' => ['ReconcileAiBudgetReservationsJob'];
        yield 'mutating admin failure command' => ['AdminFailureResolutionCommand'];
        yield 'mutating admin failure transaction' => ['AdminFailureResolutionTransaction'];
        yield 'failure workflow fence' => ['FailureWorkflowFence'];
        yield 'failure workflow handler' => ['FailureWorkflowHandler'];
        yield 'training lease recovery job' => ['RecoverExpiredTrainingDatasetLeasesJob'];
        yield 'training dataset processor job' => ['ProcessEstimateGenerationTrainingDatasetJob'];
        yield 'training online migration runtime' => ['TrainingBenchmarkOnlineMigrationRuntime'];
        yield 'training dataset review state machine' => ['TrainingDatasetReviewStateMachine'];
        yield 'training dataset action policy' => ['TrainingDatasetActionPolicy'];
        yield 'finalization outbox' => ['FinalizationOutbox'];
        yield 'finalization delivery store' => ['FinalizationDeliveryStore'];
        yield 'document manifest publication fence' => ['DocumentManifestPublicationFence'];
        yield 'project model correction chain projector' => ['ProjectModelCorrectionChainProjector'];
    }

    public function test_product_contracts_survive_runtime_cleanup(): void
    {
        $root = dirname(__DIR__, 2);
        $required = [
            'app/BusinessModules/Addons/EstimateGeneration/Services/Billing/AiEstimateQuotaService.php',
            'app/BusinessModules/Addons/EstimateGeneration/Observability/AiUsageStore.php',
            'app/BusinessModules/Addons/EstimateGeneration/Pipeline/PipelineRunner.php',
            'app/BusinessModules/Addons/EstimateGeneration/Application/Apply/GeneratedEstimateWriter.php',
            'app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationSessionController.php',
            'app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationReviewController.php',
            'app/BusinessModules/Addons/EstimateGeneration/routes.php',
            'app/Domain/Authorization/Services/AuthorizationService.php',
        ];

        foreach ($required as $relativePath) {
            self::assertFileExists($root.'/'.$relativePath, $relativePath.' must survive cleanup.');
        }

        $routes = (string) file_get_contents(
            $root.'/app/BusinessModules/Addons/EstimateGeneration/routes.php',
        );
        foreach ([
            "Route::post('/', [EstimateGenerationSessionController::class, 'store'])",
            "Route::post('/{session}/documents', [EstimateGenerationDocumentController::class, 'upload'])",
            "Route::get('/{session}/snapshot', [EstimateGenerationSessionController::class, 'snapshot'])",
            "Route::get('/{session}/review-items', [EstimateGenerationReviewController::class, 'index'])",
            "Route::post('/{session}/generate', [EstimateGenerationActionController::class, 'generate'])",
            "authorize:estimate_generation.",
        ] as $contract) {
            self::assertStringContainsString($contract, $routes, $contract.' must survive cleanup.');
        }
    }

    /** @return array<string, string> */
    private function productionFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $sources = [];

        foreach (['app', 'bootstrap', 'config', 'routes'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory));

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'json'], true)) {
                    continue;
                }

                $normalizedPath = str_replace('\\', '/', $file->getPathname());
                if (str_contains($normalizedPath, '/migrations/')) {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                if (! is_string($source)) {
                    continue;
                }

                $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $sources[$relativePath] = $source;
            }
        }

        return $sources;
    }
}
