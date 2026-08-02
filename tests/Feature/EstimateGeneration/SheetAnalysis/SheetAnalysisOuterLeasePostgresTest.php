<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\SheetAnalysis;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\DocumentSheetOperationScope;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisOperationBusy;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisOperationJournal;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EstimateGeneration\EstimateGenerationContractDatabaseProvisioner;

#[Group('postgres-contract')]
final class SheetAnalysisOuterLeasePostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function delayed_outer_owner_cannot_overwrite_final_routing_after_a_reclaimed_lease(): void
    {
        $this->requireEnvironment();
        $root = dirname(__DIR__, 4);
        EstimateGenerationContractDatabaseProvisioner::provision(DB::connection(), $root, 'training');
        $fixture = $this->fixture();
        $journal = new SheetAnalysisOperationJournal;
        [$organizationId, $projectId, $sessionId, $documentId, $unitId, $sourceVersion] = $fixture['scope'];
        $old = new DocumentSheetOperationScope($organizationId, $projectId, $sessionId, $documentId, $unitId, $sourceVersion, $fixture['old_token']);
        $new = new DocumentSheetOperationScope($organizationId, $projectId, $sessionId, $documentId, $unitId, $sourceVersion, $fixture['new_token']);

        try {
            $journal->persistFinalRouting($fixture['operation_id'], $old, ['role' => 'section', 'owner' => 'old']);
            self::fail('A reclaimed document unit lease accepted the delayed owner routing.');
        } catch (SheetAnalysisOperationBusy $exception) {
            self::assertSame('document_unit_lease_lost', $exception->getMessage());
        }

        $journal->persistFinalRouting($fixture['operation_id'], $new, ['role' => 'plan', 'owner' => 'new']);
        self::assertSame(['role' => 'plan', 'owner' => 'new'], json_decode((string) DB::table('estimate_generation_sheet_analysis_operations')
            ->where('operation_id', $fixture['operation_id'])->value('final_routing'), true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function partial_operations_table_recovers_identity_scope_and_is_safe_to_restart(): void
    {
        $this->requireEnvironment();
        $root = dirname(__DIR__, 4);
        EstimateGenerationContractDatabaseProvisioner::provision(DB::connection(), $root, 'training');
        DB::statement('DROP TABLE estimate_generation_sheet_analysis_operations');
        DB::statement(<<<'SQL'
CREATE TABLE estimate_generation_sheet_analysis_operations (
    operation_id uuid,
    organization_id bigint,
    project_id bigint,
    session_id bigint,
    document_id bigint,
    unit_id bigint,
    source_version char(71)
)
SQL);
        $migration = require $root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000300_create_estimate_generation_sheet_analysis_operations.php';
        $migration->up();
        $migration->up();

        self::assertTrue(DB::table('pg_constraint')->whereRaw('conrelid = ?::regclass', ['estimate_generation_sheet_analysis_operations'])
            ->where('contype', 'p')->exists());
        self::assertSame(7, DB::table('information_schema.columns')->where('table_schema', 'public')
            ->where('table_name', 'estimate_generation_sheet_analysis_operations')->whereIn('column_name', ['operation_id', 'organization_id', 'project_id', 'session_id', 'document_id', 'unit_id', 'source_version'])
            ->where('is_nullable', 'NO')->count());
        $migration->down();
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $user->id, 'status' => 'draft', 'processing_stage' => 'draft', 'processing_progress' => 0, 'input_payload' => [], 'state_version' => 0]);
        $sourceVersion = 'sheet-contract-v1';
        $document = EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $user->id, 'filename' => 'sheet-contract.pdf', 'mime_type' => 'application/pdf',
            'source_version' => $sourceVersion, 'status' => 'processing']);
        $oldToken = '11111111-1111-4111-8111-111111111111';
        $newToken = '22222222-2222-4222-8222-222222222222';
        $unit = EstimateGenerationProcessingUnit::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'session_id' => $session->id, 'document_id' => $document->id, 'unit_type' => 'pdf_page', 'unit_index' => 1,
            'source_version' => $sourceVersion, 'status' => 'running', 'attempt_count' => 2, 'claim_token' => $newToken,
            'lease_expires_at' => now()->addMinutes(10), 'locator' => [], 'metadata' => [], 'started_at' => now()]);
        $operationId = '33333333-3333-4333-8333-333333333333';
        DB::table('estimate_generation_sheet_analysis_operations')->insert(['operation_id' => $operationId, 'kind' => 'targeted',
            'organization_id' => $organization->id, 'project_id' => $project->id, 'session_id' => $session->id, 'document_id' => $document->id,
            'unit_id' => $unit->id, 'source_version' => $sourceVersion, 'status' => 'completed', 'attempt_count' => 1,
            'analysis_payload' => json_encode(['schema_version' => 1], JSON_THROW_ON_ERROR), 'initial_routing' => json_encode([], JSON_THROW_ON_ERROR),
            'final_routing' => json_encode([], JSON_THROW_ON_ERROR), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return ['scope' => [(int) $organization->id, (int) $project->id, (int) $session->id, (int) $document->id, (int) $unit->id, $sourceVersion],
            'old_token' => $oldToken, 'new_token' => $newToken, 'operation_id' => $operationId];
    }

    private function requireEnvironment(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_SHEET_ANALYSIS_POSTGRES_CONTRACT') !== '1'
            || getenv('RUN_ESTIMATE_GENERATION_CONTRACT_PROVISIONER') !== '1' || DB::getDriverName() !== 'pgsql'
            || ! str_ends_with((string) DB::getDatabaseName(), '_contract')) {
            self::markTestSkipped('Requires an explicit disposable PostgreSQL sheet-analysis contract environment.');
        }
    }
}
