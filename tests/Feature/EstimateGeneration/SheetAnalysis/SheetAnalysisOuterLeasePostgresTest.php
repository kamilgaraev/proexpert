<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\SheetAnalysis;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\DocumentSheetOperationScope;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisOperationBusy;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisOperationJournal;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionEvidenceData;
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
        $this->provision($root);
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
        $this->provision($root);
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

    #[Test]
    public function applied_char_64_schema_is_expanded_and_accepts_canonical_source_version(): void
    {
        $this->requireEnvironment();
        $root = dirname(__DIR__, 4);
        $this->provision($root);
        DB::statement('ALTER TABLE estimate_generation_sheet_analysis_operations ALTER COLUMN source_version TYPE char(64)');

        $migration = require $root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_13_000200_expand_sheet_analysis_source_version.php';
        $migration->up();

        self::assertSame(71, (int) DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'estimate_generation_sheet_analysis_operations')
            ->where('column_name', 'source_version')
            ->value('character_maximum_length'));
        $fixture = $this->fixture();
        $sourceVersion = 'sha256:'.str_repeat('a', 64);
        $scope = new DocumentSheetOperationScope(
            $fixture['scope'][0],
            $fixture['scope'][1],
            $fixture['scope'][2],
            $fixture['scope'][3],
            $fixture['scope'][4],
            $sourceVersion,
            $fixture['new_token'],
        );
        $unit = EstimateGenerationProcessingUnit::query()->findOrFail($fixture['scope'][4]);
        $locator = $unit->locator;
        $locator['source_version'] = $sourceVersion;
        $unit->forceFill(['source_version' => $sourceVersion, 'locator' => $locator])->save();
        EstimateGenerationDocument::query()->whereKey($fixture['scope'][3])->update(['source_version' => $sourceVersion]);

        (new SheetAnalysisOperationJournal)->run(
            '44444444-4444-4444-8444-444444444444',
            'primary',
            $scope,
            ['role' => 'unknown'],
            static fn (): VisionAnalysisData => new VisionAnalysisData(
                'unknown',
                [new VisionEvidenceData('page-1', [
                    'page_id' => 1,
                    'page_number' => 1,
                    'processing_unit_id' => $fixture['scope'][4],
                    'source_version' => $sourceVersion,
                    'coordinate_space' => 'normalized_source_v1',
                ])],
                [],
                [],
                ['scale_missing'],
                'deterministic_spy',
                'vision-spy-v1',
                'vision-spy-v1',
                '2026-08-13',
                'unavailable',
                null,
                null,
            ),
        );

        self::assertSame($sourceVersion, rtrim((string) DB::table('estimate_generation_sheet_analysis_operations')
            ->where('operation_id', '44444444-4444-4444-8444-444444444444')
            ->value('source_version')));
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $user->id, 'status' => 'draft', 'processing_stage' => 'draft', 'processing_progress' => 0, 'input_payload' => [], 'state_version' => 0]);
        $sourceVersion = 'sha256:'.hash('sha256', 'sheet-contract-v1');
        $document = EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $user->id, 'filename' => 'sheet-contract.pdf', 'mime_type' => 'application/pdf',
            'source_version' => $sourceVersion, 'status' => 'processing']);
        $oldToken = '11111111-1111-4111-8111-111111111111';
        $newToken = '22222222-2222-4222-8222-222222222222';
        $unit = EstimateGenerationProcessingUnit::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'session_id' => $session->id, 'document_id' => $document->id, 'unit_type' => 'pdf_page', 'unit_index' => 1,
            'source_version' => $sourceVersion, 'status' => 'running', 'attempt_count' => 2, 'claim_token' => $newToken,
            'lease_expires_at' => now()->addMinutes(10), 'locator' => [
                'source_kind' => 'pdf',
                'source_version' => $sourceVersion,
                'coordinate_space' => 'pdf_page_pixels',
                'artifact_path' => 'org-'.$organization->id.'/contract.pdf',
                'artifact_sha256' => 'sha256:'.hash('sha256', 'sheet-contract-artifact'),
                'artifact_version_id' => 'sheet-contract-v1',
            ], 'metadata' => ['contract' => true], 'started_at' => now()]);
        $operationId = '33333333-3333-4333-8333-333333333333';
        DB::table('estimate_generation_sheet_analysis_operations')->insert(['operation_id' => $operationId, 'kind' => 'targeted',
            'organization_id' => $organization->id, 'project_id' => $project->id, 'session_id' => $session->id, 'document_id' => $document->id,
            'unit_id' => $unit->id, 'source_version' => $sourceVersion, 'status' => 'completed', 'attempt_count' => 1,
            'analysis_payload' => json_encode(['schema_version' => 1], JSON_THROW_ON_ERROR),
            'initial_routing' => json_encode(['role' => 'unknown'], JSON_THROW_ON_ERROR),
            'final_routing' => json_encode(['role' => 'unknown'], JSON_THROW_ON_ERROR),
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

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

    private function provision(string $root): void
    {
        EstimateGenerationContractDatabaseProvisioner::provision(
            DB::connection(),
            $root,
            (string) DB::getDatabaseName() === 'most_ai_estimator_contract' ? 'fresh' : 'training',
        );
    }
}
