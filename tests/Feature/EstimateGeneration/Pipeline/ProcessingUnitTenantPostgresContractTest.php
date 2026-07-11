<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class ProcessingUnitTenantPostgresContractTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function composite_scope_null_on_delete_and_session_cascade_are_enforced_together(): void
    {
        $this->requireEnvironment();
        DB::beginTransaction();
        try {
            $a = $this->fixture('a');
            $b = $this->fixture('b');

            $this->assertRejected(fn () => DB::table('estimate_generation_processing_units')->insert([
                ...$this->unitRow($a), 'organization_id' => $b['organization_id'],
            ]));
            $this->assertRejected(fn () => DB::table('estimate_generation_processing_units')->insert([
                ...$this->unitRow($a), 'project_id' => $b['project_id'],
            ]));
            $this->assertRejected(fn () => DB::table('estimate_generation_processing_units')->insert([
                ...$this->unitRow($a), 'session_id' => $b['session_id'],
            ]));
            $this->assertRejected(fn () => DB::table('estimate_generation_processing_units')->insert([
                ...$this->unitRow($a), 'document_id' => $b['document_id'],
            ]));
            $this->assertRejected(fn () => DB::table('estimate_generation_document_pages')
                ->where('id', $a['page_id'])->update(['processing_unit_id' => $b['unit_id']]));

            DB::table('estimate_generation_processing_units')->where('id', $a['unit_id'])->delete();
            $page = DB::table('estimate_generation_document_pages')->where('id', $a['page_id'])->first();
            self::assertNull($page->processing_unit_id);
            self::assertSame($a['organization_id'], (int) $page->organization_id);
            self::assertSame($a['project_id'], (int) $page->project_id);
            self::assertSame($a['session_id'], (int) $page->session_id);
            self::assertSame($a['document_id'], (int) $page->document_id);

            DB::table('estimate_generation_sessions')->where('id', $b['session_id'])->delete();
            self::assertSame(0, DB::table('estimate_generation_processing_units')->where('session_id', $b['session_id'])->count());
            self::assertSame(0, DB::table('estimate_generation_document_pages')->where('session_id', $b['session_id'])->count());
            self::assertSame(0, DB::table('estimate_generation_documents')->where('session_id', $b['session_id'])->count());
        } finally {
            DB::rollBack();
        }
    }

    private function fixture(string $suffix): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $user->id,
            'status' => 'draft', 'processing_stage' => 'draft', 'processing_progress' => 0,
            'input_payload' => [], 'state_version' => 0,
        ]);
        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id, 'organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $user->id, 'filename' => "contract-{$suffix}.pdf", 'mime_type' => 'application/pdf',
        ]);
        $unit = EstimateGenerationProcessingUnit::query()->create([
            ...$this->unitRow(['organization_id' => (int) $organization->id, 'project_id' => (int) $project->id,
                'session_id' => (int) $session->id, 'document_id' => (int) $document->id]),
        ]);
        $page = EstimateGenerationDocumentPage::query()->create([
            'document_id' => $document->id, 'organization_id' => $organization->id, 'project_id' => $project->id,
            'session_id' => $session->id, 'page_number' => 1, 'processing_unit_id' => $unit->id,
        ]);

        return ['organization_id' => (int) $organization->id, 'project_id' => (int) $project->id,
            'session_id' => (int) $session->id, 'document_id' => (int) $document->id,
            'unit_id' => (int) $unit->id, 'page_id' => (int) $page->id];
    }

    private function unitRow(array $fixture): array
    {
        return ['organization_id' => $fixture['organization_id'], 'project_id' => $fixture['project_id'],
            'session_id' => $fixture['session_id'], 'document_id' => $fixture['document_id'],
            'unit_type' => 'pdf_page', 'unit_index' => random_int(100, 100000),
            'source_version' => 'contract-v1', 'status' => 'pending', 'locator' => '{}', 'metadata' => '{}'];
    }

    private function assertRejected(callable $operation): void
    {
        DB::statement('SAVEPOINT tenant_contract');
        try {
            $operation();
            self::fail('Cross-tenant relation was accepted.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT tenant_contract');
        } finally {
            DB::statement('RELEASE SAVEPOINT tenant_contract');
        }
    }

    private function requireEnvironment(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires explicit isolated PostgreSQL contract environment.');
        }
    }
}
