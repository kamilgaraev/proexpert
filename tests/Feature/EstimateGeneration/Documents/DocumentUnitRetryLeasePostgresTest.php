<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ResetDocumentProcessingUnitsForAttempt;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\DocumentSheetOperationScope;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisOperationIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisOperationJournal;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSheetAnalysisOperation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[Group('postgres-contract')]
final class DocumentUnitRetryLeasePostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function persisted_terminal_history_can_renew_a_claim_in_a_new_processing_lineage(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires explicit isolated PostgreSQL contract environment.');
        }
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $sourceVersion = 'sha256:'.str_repeat('a', 64);
        $oldAttemptId = '7d1385db-106e-47ab-993b-322fb5d124af';
        $newAttemptId = '2899deb2-a38f-4eeb-9ebe-4d833c789bbb';
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'processing_documents',
            'processing_stage' => 'processing_documents',
            'processing_progress' => 0,
            'input_payload' => [],
            'state_version' => 1,
        ]);
        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'filename' => 'retry.pdf',
            'mime_type' => 'application/pdf',
            'source_version' => $sourceVersion,
            'status' => 'failed',
        ]);
        $unit = EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'session_id' => $session->id,
            'document_id' => $document->id,
            'unit_type' => 'pdf_page',
            'unit_index' => 1,
            'source_version' => $sourceVersion,
            'status' => 'failed',
            'attempt_count' => ProcessDocumentUnit::MAX_ATTEMPTS,
            'failure_code' => 'document_unit_processing_failed',
            'failure_fingerprint' => hash('sha256', 'old-failure'),
            'failed_at' => now(),
            'locator' => [
                'source_kind' => 'pdf',
                'source_version' => $sourceVersion,
                'coordinate_space' => 'pdf_page_pixels',
                'artifact_path' => 'org-'.$organization->id.'/estimate-generation/tests/page.png',
                'artifact_source_version' => $sourceVersion,
                'artifact_bytes' => 1,
                'artifact_sha256' => $sourceVersion,
                'artifact_version_id' => 'retry-lineage-fixture-v1',
                'content_type' => 'image/png',
            ],
            'metadata' => [
                'processing_attempt_id' => $oldAttemptId,
                'failure_category' => 'terminal',
                'actual_execution_count' => 1,
                'failure_history' => [[
                    'attempt_id' => '6b04208e-0f8a-43a6-b8bb-d4f5d86664bd',
                    'attempt_count' => 3,
                    'failure_code' => 'vision_http_failed',
                    'failure_fingerprint' => hash('sha256', 'provider-failure'),
                ]],
            ],
        ]);
        EstimateGenerationDocumentPage::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'session_id' => $session->id,
            'document_id' => $document->id,
            'processing_unit_id' => $unit->id,
            'source_version' => $sourceVersion,
            'page_number' => 1,
            'status' => 'failed',
            'language_codes' => [],
            'normalized_payload' => [],
            'quality_flags' => [],
        ]);

        (new ResetDocumentProcessingUnitsForAttempt)->handle(
            $document,
            $sourceVersion,
            $oldAttemptId,
            $newAttemptId,
        );
        $store = new EloquentDocumentProcessingUnitStore(DB::connection());
        $now = new DateTimeImmutable;
        $claim = $store->claim(
            (int) $unit->id,
            $sourceVersion,
            $now,
            $now->modify('+2100 seconds'),
            ProcessDocumentUnit::MAX_ATTEMPTS,
        );
        $context = $store->executionContext($claim);

        self::assertNotNull($context);
        self::assertSame($newAttemptId, $context->processingAttemptId);
        $context->renewLeaseOrFail();
        self::assertGreaterThan($now, $unit->fresh()->lease_expires_at?->toDateTimeImmutable());

        $derivativeHash = 'sha256:'.str_repeat('b', 64);
        $oldOperationId = SheetAnalysisOperationIdentity::primary(
            (int) $session->id,
            (int) $document->id,
            (int) $unit->id,
            $sourceVersion,
            $derivativeHash,
            $oldAttemptId,
        );
        EstimateGenerationSheetAnalysisOperation::query()->create([
            'operation_id' => $oldOperationId,
            'kind' => 'primary',
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'session_id' => $session->id,
            'document_id' => $document->id,
            'unit_id' => $unit->id,
            'source_version' => $sourceVersion,
            'status' => 'failed',
            'lease_token' => null,
            'lease_expires_at' => null,
            'attempt_count' => 2,
            'initial_routing' => ['role' => 'unknown'],
            'final_routing' => ['status' => 'pending'],
            'analysis_payload' => ['status' => 'pending'],
            'failure_reason' => 'old_provider_failure',
        ]);
        $operationId = SheetAnalysisOperationIdentity::primary(
            (int) $session->id,
            (int) $document->id,
            (int) $unit->id,
            $sourceVersion,
            $derivativeHash,
            $newAttemptId,
        );
        $scope = new DocumentSheetOperationScope(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
            (int) $document->id,
            (int) $unit->id,
            $sourceVersion,
            (string) $claim->token,
        );
        $wireReached = false;
        try {
            (new SheetAnalysisOperationJournal)->run(
                $operationId,
                'primary',
                $scope,
                ['role' => 'unknown'],
                static function () use (&$wireReached): never {
                    $wireReached = true;

                    throw new RuntimeException('provider_boundary_reached');
                },
            );
            self::fail('The provider boundary sentinel was not raised.');
        } catch (RuntimeException $error) {
            self::assertSame('provider_boundary_reached', $error->getMessage());
        }

        self::assertTrue($wireReached);
        self::assertNotSame($oldOperationId, $operationId);
        self::assertDatabaseHas('estimate_generation_sheet_analysis_operations', [
            'operation_id' => $operationId,
            'unit_id' => $unit->id,
            'status' => 'failed',
            'attempt_count' => 1,
        ]);
    }
}
