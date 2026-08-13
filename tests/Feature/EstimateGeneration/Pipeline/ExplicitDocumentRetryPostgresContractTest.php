<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentMutationSessionReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ExplicitDocumentRetryConflict;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ExplicitDocumentRetryEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RetryEstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;

final class ExplicitDocumentRetryPostgresContractTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('pgsql', DB::getDriverName());
        $this->createTemporaryTables();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_postgres_lock_idempotency_lineage_history_and_single_post_commit_dispatch(): void
    {
        Queue::fake();
        $checksum = hash('sha256', 'saved-document');
        $sourceVersion = 'sha256:'.$checksum;
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => 38,
            'project_id' => 52,
            'user_id' => 7,
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'state_version' => 9,
            'input_payload' => [],
            'problem_flags' => [],
        ]);
        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => 38,
            'project_id' => 52,
            'user_id' => 7,
            'filename' => 'saved.pdf',
            'storage_path' => 'org-38/saved.pdf',
            'status' => 'needs_review',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'checksum_sha256' => $checksum,
            'source_version' => $sourceVersion,
            'page_count' => 3,
            'processed_page_count' => 0,
            'facts_summary' => [],
            'meta' => ['processing_attempt_id' => 'old-lineage'],
        ]);
        foreach (range(1, 3) as $index) {
            $unit = EstimateGenerationProcessingUnit::query()->create([
                'organization_id' => 38,
                'project_id' => 52,
                'session_id' => $session->id,
                'document_id' => $document->id,
                'unit_type' => 'pdf_page',
                'unit_index' => $index,
                'source_version' => $sourceVersion,
                'status' => 'failed',
                'attempt_count' => 3,
                'output_count' => 0,
                'failure_code' => 'document_geometry_processing_failed',
                'failure_fingerprint' => hash('sha256', 'same-system-root'),
                'locator' => ['page' => $index],
                'metadata' => ['failure_category' => 'terminal'],
                'failed_at' => now(),
            ]);
            EstimateGenerationDocumentPage::query()->create([
                'document_id' => $document->id,
                'processing_unit_id' => $unit->id,
                'source_version' => $sourceVersion,
                'organization_id' => 38,
                'project_id' => 52,
                'session_id' => $session->id,
                'page_number' => $index,
                'status' => 'failed',
                'text' => 'historical output',
            ]);
        }

        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnTrue();
        $policy = new EstimateGenerationMutationPolicy;
        $reconciler = Mockery::mock(DocumentMutationSessionReconciler::class);
        $reconciler->expects('changed')->once()->andReturn($session);
        $readiness = Mockery::mock(DocumentGenerationReadinessService::class);
        $readiness->allows('evaluate')->andReturn(['summary' => ['pending_count' => 1]]);
        $service = new RetryEstimateGenerationDocument(
            $policy,
            $reconciler,
            $readiness,
            $authorization,
            new ExplicitDocumentRetryEligibility,
        );
        $actor = new User;
        $actor->forceFill(['id' => 7, 'current_organization_id' => 38]);
        $key = (string) Str::uuid();

        try {
            $service->handle($session, $document, $actor, 9, 'sha256:'.str_repeat('0', 64), (string) Str::uuid(), null);
            self::fail('A stale source fence must conflict.');
        } catch (ExplicitDocumentRetryConflict $conflict) {
            self::assertSame('stale_source', $conflict->disposition);
        }
        $foreignActor = new User;
        $foreignActor->forceFill(['id' => 8, 'current_organization_id' => 99]);
        try {
            $service->handle($session, $document, $foreignActor, 9, $sourceVersion, (string) Str::uuid(), null);
            self::fail('A cross-tenant actor must conflict.');
        } catch (ExplicitDocumentRetryConflict $conflict) {
            self::assertSame('forbidden', $conflict->disposition);
        }
        $firstUnit = EstimateGenerationProcessingUnit::query()->firstOrFail();
        $firstUnit->forceFill(['failure_code' => 'document_artifact_integrity_failed'])->save();
        try {
            $service->handle($session, $document, $actor, 9, $sourceVersion, (string) Str::uuid(), null);
            self::fail('An integrity failure must conflict.');
        } catch (ExplicitDocumentRetryConflict $conflict) {
            self::assertSame('retry_not_allowed', $conflict->disposition);
        }
        $firstUnit->forceFill(['failure_code' => 'document_geometry_processing_failed'])->save();
        Queue::assertNothingPushed();

        $accepted = $service->handle($session, $document, $actor, 9, $sourceVersion, $key, null);
        $replayed = $service->handle($session, $document, $actor, 9, $sourceVersion, $key, null);
        $loser = $service->handle($session, $document, $actor, 9, $sourceVersion, (string) Str::uuid(), null);

        self::assertSame('accepted', $accepted->disposition);
        self::assertSame('replayed', $replayed->disposition);
        self::assertSame('already_in_progress', $loser->disposition);
        self::assertSame($accepted->attemptId, $replayed->attemptId);
        self::assertSame($accepted->attemptId, $loser->attemptId);
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 1);
        $document->refresh();
        self::assertSame('org-38/saved.pdf', $document->storage_path);
        self::assertCount(1, $document->meta['explicit_document_retry_history']);
        self::assertSame('old-lineage', $document->meta['explicit_document_retry_history'][0]['old_attempt_id']);
        self::assertSame(hash('sha256', $key), $document->meta['explicit_document_retry_history'][0]['idempotency_hash']);
        self::assertCount(3, EstimateGenerationProcessingUnit::query()->get());
        self::assertSame('document_geometry_processing_failed', EstimateGenerationProcessingUnit::query()->firstOrFail()->metadata['failure_history'][0]['failure_code']);
        self::assertSame(3, EstimateGenerationDocumentPage::query()->where('status', 'queued')->count());
    }

    private function createTemporaryTables(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TEMP TABLE estimate_generation_sessions (
 id bigserial PRIMARY KEY, organization_id bigint, project_id bigint, user_id bigint, status varchar(40),
 processing_stage varchar(80), processing_progress integer default 0, input_payload jsonb default '{}',
 analysis_payload jsonb, draft_payload jsonb, problem_flags jsonb default '[]', applied_estimate_id bigint,
 last_error text, failure_code varchar(80), state_version integer default 0, resume_status varchar(40),
 created_at timestamptz, updated_at timestamptz
);
CREATE TEMP TABLE estimate_generation_documents (
 id bigserial PRIMARY KEY, session_id bigint, organization_id bigint, project_id bigint, user_id bigint,
 filename varchar(255), mime_type varchar(255), storage_path varchar(255), status varchar(40), processing_stage varchar(80),
 progress_percent integer default 0, file_size_bytes bigint, checksum_sha256 varchar(64), source_version varchar(80),
 units_finalized_source_version varchar(80), units_reconciled_source_version varchar(80), units_reconcile_claim_token varchar(36),
 units_reconcile_lease_expires_at timestamptz, page_count integer, processed_page_count integer default 0,
 ocr_provider varchar(255), ocr_model varchar(255), ocr_attempts integer default 0, quality_score numeric,
 quality_level varchar(40), quality_flags jsonb default '[]', facts_summary jsonb default '{}', error_code varchar(80),
 error_message_key varchar(255), error_context jsonb, ocr_started_at timestamptz, ocr_finished_at timestamptz,
 ignored_at timestamptz, extracted_text text, structured_payload jsonb default '{}', meta jsonb default '{}',
 created_at timestamptz, updated_at timestamptz
);
CREATE TEMP TABLE estimate_generation_processing_units (
 id bigserial PRIMARY KEY, organization_id bigint, project_id bigint, session_id bigint, document_id bigint,
 unit_type varchar(40), unit_index integer, source_version varchar(80), status varchar(20), attempt_count integer default 0,
 claim_token varchar(36), lease_expires_at timestamptz, output_version varchar(80), output_count integer default 0,
 dispatch_attempt_count integer default 0, last_dispatched_at timestamptz, next_dispatch_at timestamptz,
 failure_code varchar(80), failure_fingerprint varchar(64), locator jsonb default '{}', metadata jsonb default '{}',
 started_at timestamptz, completed_at timestamptz, failed_at timestamptz, created_at timestamptz, updated_at timestamptz
);
CREATE TEMP TABLE estimate_generation_document_pages (
 id bigserial PRIMARY KEY, document_id bigint, processing_unit_id bigint, source_version varchar(80), output_version varchar(80),
 organization_id bigint, project_id bigint, session_id bigint, page_number integer, width integer, height integer,
 rotation integer, language_codes jsonb, text text, text_hash varchar(64), confidence numeric, raw_payload_path varchar(255),
 normalized_payload jsonb, quality_flags jsonb, status varchar(20), excluded_at timestamptz, excluded_reason text,
 retry_attempt_id varchar(36), last_retry_requested_at timestamptz, created_at timestamptz, updated_at timestamptz
);
SQL);
    }
}
