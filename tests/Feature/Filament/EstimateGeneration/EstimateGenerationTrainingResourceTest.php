<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\EstimateGeneration;

use App\Filament\Resources\EstimateGeneration\TrainingDatasetResource;
use App\Filament\Resources\EstimateGeneration\TrainingDatasetResource\Pages\ListEstimateGenerationTrainingDatasets;
use App\Filament\Resources\EstimateGeneration\TrainingDatasetResource\Pages\ViewEstimateGenerationTrainingDataset;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EstimateGenerationTrainingResourceTest extends TestCase
{
    public function test_historical_dataset_resource_is_read_only(): void
    {
        $source = $this->source(TrainingDatasetResource::class);

        self::assertStringContainsString('FilamentPermission::ESTIMATE_GENERATION_DATASETS', $source);
        self::assertStringContainsString('return $schema->components([]);', $source);
        self::assertStringContainsString("'view' => Pages\\ViewEstimateGenerationTrainingDataset::route('/{record}')", $source);
        self::assertStringNotContainsString("'create' =>", $source);
        self::assertStringNotContainsString('FilamentPermission::ESTIMATE_GENERATION_OPERATE', $source);
        self::assertStringNotContainsString('use Filament\\Actions\\Action;', $source);
        self::assertStringNotContainsString('DeleteAction::make(', $source);
        self::assertStringNotContainsString('AdminTrainingDatasetAction', $source);
        self::assertStringNotContainsString('runAction(', $source);
        self::assertFalse(TrainingDatasetResource::canCreate());
        self::assertFalse(TrainingDatasetResource::canDeleteAny());
    }

    public function test_historical_dataset_pages_expose_no_mutating_header_actions(): void
    {
        $list = $this->source(ListEstimateGenerationTrainingDatasets::class);
        $view = $this->source(ViewEstimateGenerationTrainingDataset::class);

        self::assertStringNotContainsString('CreateAction', $list);
        self::assertStringNotContainsString('getHeaderActions', $list);
        self::assertStringNotContainsString('Action::make(', $view);
        self::assertStringNotContainsString('getHeaderActions', $view);
    }

    public function test_old_resource_namespace_remains_absent(): void
    {
        $root = dirname(__DIR__, 4);

        self::assertFileDoesNotExist($root.'/app/Filament/Resources/EstimateGenerationTrainingDatasetResource.php');
        self::assertDirectoryDoesNotExist($root.'/app/Filament/Resources/EstimateGenerationTrainingDatasetResource');
        self::assertSame(10, TrainingDatasetResource::getNavigationSort());
        self::assertStringContainsString('NavigationGroups::aiEstimator()', $this->source(TrainingDatasetResource::class));
    }

    private function source(string $class): string
    {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());
        self::assertIsString($source);

        return $source;
    }
}
