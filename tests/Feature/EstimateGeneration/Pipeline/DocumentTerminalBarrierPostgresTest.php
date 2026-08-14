<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentUnitDispatchStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentUnitExhaustionHandler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class DocumentTerminalBarrierPostgresTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function exhausted_page_cannot_finalize_document_with_pending_or_leased_siblings_and_terminal_document_cannot_dispatch(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        self::assertSame('1', getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT'));
        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $user = User::factory()->create();
            $sourceVersion = 'sha256:'.str_repeat('d', 64);
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'status' => 'processing_documents',
                'processing_stage' => 'processing_documents',
                'processing_progress' => 30,
                'input_payload' => [],
                'state_version' => 0,
            ]);
            $document = EstimateGenerationDocument::query()->create([
                'session_id' => $session->id,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'filename' => 'bounded.pdf',
                'mime_type' => 'application/pdf',
                'source_version' => $sourceVersion,
                'status' => 'processing',
                'processing_stage' => 'preflight',
                'progress_percent' => 35,
            ]);
            $units = [];
            foreach ([
                ['failed', ProcessDocumentUnit::MAX_ATTEMPTS, null],
                ['running', 1, CarbonImmutable::now()->addMinute()],
                ['pending', 0, null],
            ] as $offset => [$status, $attemptCount, $lease]) {
                $unit = EstimateGenerationProcessingUnit::query()->create([
                    'organization_id' => $organization->id,
                    'project_id' => $project->id,
                    'session_id' => $session->id,
                    'document_id' => $document->id,
                    'unit_type' => 'pdf_page',
                    'unit_index' => $offset + 1,
                    'source_version' => $sourceVersion,
                    'status' => $status,
                    'attempt_count' => $attemptCount,
                    'claim_token' => $status === 'running' ? '11111111-1111-4111-8111-111111111111' : null,
                    'lease_expires_at' => $lease,
                    'failed_at' => $status === 'failed' ? CarbonImmutable::now() : null,
                    'failure_code' => $status === 'failed' ? 'document_unit_attempts_exhausted' : null,
                    'failure_fingerprint' => $status === 'failed' ? str_repeat('f', 64) : null,
                    'locator' => [
                        'source_kind' => 'pdf',
                        'source_version' => $sourceVersion,
                        'coordinate_space' => 'pdf_page_pixels',
                        'artifact_path' => 'org-'.$organization->id.'/estimate-generation/contracts/page-'.($offset + 1).'.png',
                        'artifact_sha256' => 'sha256:'.str_repeat((string) ($offset + 1), 64),
                        'artifact_version_id' => 'terminal-barrier-page-'.($offset + 1),
                    ],
                    'metadata' => ['contract' => 'terminal-barrier'],
                ]);
                EstimateGenerationDocumentPage::query()->create([
                    'organization_id' => $organization->id,
                    'project_id' => $project->id,
                    'session_id' => $session->id,
                    'document_id' => $document->id,
                    'processing_unit_id' => $unit->id,
                    'source_version' => $sourceVersion,
                    'page_number' => $offset + 1,
                    'status' => $status === 'failed' ? 'failed' : ($status === 'running' ? 'processing' : 'queued'),
                    'language_codes' => [],
                    'normalized_payload' => [],
                    'quality_flags' => [],
                ]);
                $units[] = $unit;
            }

            app(EloquentDocumentUnitExhaustionHandler::class)->handle((int) $units[0]->id);

            $active = $document->fresh();
            self::assertSame('processing', $active->status);
            self::assertNotSame('completed', $active->processing_stage);
            self::assertLessThan(100, $active->progress_percent);
            self::assertSame('processing_documents', $session->fresh()->status->value);

            $active->forceFill(['status' => 'needs_review', 'processing_stage' => 'completed', 'progress_percent' => 100])->save();
            $due = (new EloquentDocumentUnitDispatchStore(DB::connection()))->dueForDocument(
                (int) $document->id,
                $sourceVersion,
                CarbonImmutable::now()->toDateTimeImmutable(),
                16,
            );
            self::assertSame([], $due);
        } finally {
            DB::rollBack();
        }
    }
}
