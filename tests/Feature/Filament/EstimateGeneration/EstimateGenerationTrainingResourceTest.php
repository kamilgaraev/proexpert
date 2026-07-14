<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\TrainingDatasetReviewStateMachine;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\TrainingDatasetTrustPolicy;
use App\Filament\Resources\EstimateGeneration\TrainingDatasetResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EstimateGenerationTrainingResourceTest extends TestCase
{
    #[Test]
    public function action_policy_has_a_closed_kind_and_status_matrix(): void
    {
        $policy = \App\BusinessModules\Addons\EstimateGeneration\Services\Training\TrainingDatasetActionPolicy::class;
        self::assertTrue($policy::allows('development', 'draft', 'draft', 'process'));
        self::assertTrue($policy::allows('development', 'review_required', 'draft', 'submit_review'));
        self::assertTrue($policy::allows('development', 'review_required', 'pending', 'approve_review'));
        self::assertTrue($policy::allows('development', 'review_required', 'approved', 'approve_primary'));
        self::assertTrue($policy::allows('acceptance', 'review_required', 'draft', 'approve_primary'));
        self::assertTrue($policy::allows('regression', 'review_required', 'draft', 'approve_primary'));
        self::assertFalse($policy::allows('acceptance', 'review_required', 'draft', 'submit_review'));
        self::assertFalse($policy::allows('regression', 'approved', 'approved', 'train'));
        self::assertFalse($policy::allows('unknown', 'review_required', 'draft', 'approve_primary'));
    }

    #[Test]
    public function upgrade_migration_backfills_approved_development_without_stranding(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000400_add_training_dataset_trusted_review.php');
        self::assertIsString($source);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS eg_training_dataset_immutable', $source);
        self::assertStringContainsString("dataset_type = 'development'", $source);
        self::assertStringContainsString("status = 'approved'", $source);
        self::assertStringContainsString("trusted_review_status = 'approved'", $source);
        self::assertStringContainsString('approved_by', $source);
        self::assertStringContainsString('approved_at', $source);
        self::assertStringContainsString('trusted_review_migrated_from_approval', $source);
        self::assertStringContainsString('CREATE TRIGGER eg_training_dataset_immutable', $source);
    }

    #[Test]
    public function dataset_creation_persists_a_tenant_bound_immutable_benchmark_manifest(): void
    {
        $resource = file_get_contents((new ReflectionClass(TrainingDatasetResource::class))->getFileName());
        $service = file_get_contents((new ReflectionClass(\App\BusinessModules\Addons\EstimateGeneration\Services\Training\EstimateGenerationTrainingDatasetService::class))->getFileName());
        self::assertIsString($resource);
        self::assertStringContainsString("FileUpload::make('benchmark_manifest_file')", $resource);
        self::assertIsString($service);
        self::assertStringContainsString('BenchmarkManifest::fromArray', $service);
        self::assertStringContainsString("'benchmark_manifest' =>", $service);
        self::assertStringContainsString('estimate-generation/benchmarks/{$datasetType}', $service);
        self::assertStringContainsString("'dataset_content_hash'", $service);
    }

    public function test_old_resource_is_deleted_and_only_new_namespace_is_registered(): void
    {
        $root = dirname(__DIR__, 4);

        self::assertFileDoesNotExist($root.'/app/Filament/Resources/EstimateGenerationTrainingDatasetResource.php');
        self::assertDirectoryDoesNotExist($root.'/app/Filament/Resources/EstimateGenerationTrainingDatasetResource');
        self::assertSame(10, TrainingDatasetResource::getNavigationSort());
        self::assertStringContainsString('NavigationGroups::aiEstimator()', $this->source(TrainingDatasetResource::class));
    }

    public function test_acceptance_dataset_is_isolated_from_learning_but_can_be_benchmarked(): void
    {
        $dataset = new EstimateGenerationTrainingDataset([
            'dataset_type' => EstimateGenerationTrainingDataset::TYPE_ACCEPTANCE,
            'status' => EstimateGenerationTrainingDataset::STATUS_APPROVED,
            'trusted_review_status' => EstimateGenerationTrainingDataset::TRUSTED_REVIEW_APPROVED,
        ]);
        $policy = new TrainingDatasetTrustPolicy;

        self::assertFalse($policy->canTrain($dataset));
        self::assertFalse($policy->canTuneRules($dataset));
        self::assertTrue($policy->canBenchmark($dataset));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function developmentTrustMatrix(): iterable
    {
        yield 'draft review' => ['draft', EstimateGenerationTrainingDataset::STATUS_REVIEW_REQUIRED, false];
        yield 'pending review' => ['pending', EstimateGenerationTrainingDataset::STATUS_REVIEW_REQUIRED, false];
        yield 'rejected review' => ['rejected', EstimateGenerationTrainingDataset::STATUS_REJECTED, false];
        yield 'trusted approved' => ['approved', EstimateGenerationTrainingDataset::STATUS_APPROVED, true];
    }

    #[DataProvider('developmentTrustMatrix')]
    public function test_development_learning_requires_trusted_review_and_approved_dataset(
        string $reviewStatus,
        string $datasetStatus,
        bool $allowed,
    ): void {
        $dataset = new EstimateGenerationTrainingDataset([
            'dataset_type' => EstimateGenerationTrainingDataset::TYPE_DEVELOPMENT,
            'status' => $datasetStatus,
            'trusted_review_status' => $reviewStatus,
        ]);
        $policy = new TrainingDatasetTrustPolicy;

        self::assertSame($allowed, $policy->canTrain($dataset));
        self::assertSame($allowed, $policy->canTuneRules($dataset));
    }

    public function test_trusted_review_state_machine_is_closed_and_rejects_self_review(): void
    {
        self::assertSame('pending', TrainingDatasetReviewStateMachine::submit('draft', 10, null));
        self::assertSame('approved', TrainingDatasetReviewStateMachine::approve('pending', 10, 11));
        self::assertSame('rejected', TrainingDatasetReviewStateMachine::reject('pending', 10, 11));

        $this->expectExceptionMessage('training_dataset_self_review_forbidden');
        TrainingDatasetReviewStateMachine::approve('pending', 10, 10);
    }

    public function test_resource_actions_delegate_to_guarded_application_service(): void
    {
        $source = $this->source(TrainingDatasetResource::class);

        self::assertStringContainsString('AdminTrainingDatasetActionService::class', $source);
        self::assertStringContainsString('FilamentPermission::ESTIMATE_GENERATION_DATASETS', $source);
        self::assertStringContainsString('FilamentPermission::ESTIMATE_GENERATION_OPERATE', $source);
        self::assertStringNotContainsString('queueProcessing(', $source);
        foreach (['->save()', '->update([', '->delete()', 'DB::', 'dispatch('] as $mutation) {
            self::assertStringNotContainsString($mutation, $source);
        }
    }

    public function test_application_service_declares_tenant_idempotency_permission_and_audit_guards(): void
    {
        $source = $this->source(\App\BusinessModules\Addons\EstimateGeneration\Operations\AdminTrainingDatasetActionService::class);

        foreach (['organizationId', 'idempotencyKey', 'expectedVersion', 'actorId', 'assertAllowed', 'recordAudit'] as $guard) {
            self::assertStringContainsString($guard, $source);
        }
    }

    public function test_trusted_review_migration_runs_after_plan3_dataset_versioning_and_has_db_guards(): void
    {
        $migration = '2026_07_14_000400_add_training_dataset_trusted_review.php';
        self::assertGreaterThan('2026_07_12_001700_rebuild_estimate_generation_training_and_benchmarks.php', $migration);
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/'.$migration);
        self::assertIsString($source);

        foreach (['control_version', 'trusted_review_status', 'trusted_review_submitted_by', 'trusted_reviewed_by', 'trusted_review_submitted_by <> trusted_reviewed_by', "NEW.dataset_type = 'development'", "NEW.status = 'approved'", 'invalid trusted review transition'] as $guard) {
            self::assertStringContainsString($guard, $source);
        }
    }

    /** @param class-string $class */
    private function source(string $class): string
    {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());
        self::assertIsString($source);

        return $source;
    }
}
