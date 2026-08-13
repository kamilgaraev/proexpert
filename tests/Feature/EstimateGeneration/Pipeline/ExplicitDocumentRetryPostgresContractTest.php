<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentMutationSessionReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ExplicitDocumentRetryConflict;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ExplicitDocumentRetryEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RetryEstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationAuditEvent;
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
        $database = (string) DB::getDatabaseName();
        self::assertTrue(
            str_ends_with($database, '_testing')
                || ($database === 'most_ai_estimator_contract'
                    && getenv('RUN_ESTIMATE_GENERATION_CONTRACT_PROVISIONER') === '1'),
            'Explicit retry contract requires an isolated testing database.',
        );
        $this->createContractTables();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->dropContractTables();
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
        self::assertSame(1, EstimateGenerationAuditEvent::query()->count());
        self::assertSame(hash('sha256', $key), EstimateGenerationAuditEvent::query()->firstOrFail()->payload['idempotency_hash']);

        $terminalMeta = $document->meta;
        $terminalMeta['explicit_document_retry'] = [
            ...$terminalMeta['explicit_document_retry'],
            'status' => 'failed',
            'completed_at' => now()->toISOString(),
            'terminal_reason' => 'system_failure',
        ];
        $document->forceFill(['status' => 'failed', 'meta' => $terminalMeta])->save();
        $terminalReplay = $service->handle($session, $document, $actor, 9, $sourceVersion, $key, null);

        self::assertSame('replayed', $terminalReplay->disposition);
        self::assertSame($accepted->attemptId, $terminalReplay->attemptId);
        self::assertSame('failed', $terminalReplay->document->meta['explicit_document_retry']['status']);
        self::assertSame('estimate_generation.document_retry_result_replayed', $terminalReplay->messageKey);
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 1);
        self::assertSame(1, EstimateGenerationAuditEvent::query()->count());
    }

    public function test_concurrent_different_keys_have_exactly_one_winner_and_one_dispatch(): void
    {
        $checksum = hash('sha256', 'concurrent-saved-document');
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
            'filename' => 'concurrent.pdf',
            'storage_path' => 'org-38/concurrent.pdf',
            'status' => 'needs_review',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'checksum_sha256' => $checksum,
            'source_version' => $sourceVersion,
            'page_count' => 3,
            'processed_page_count' => 0,
            'facts_summary' => [],
            'meta' => ['processing_attempt_id' => 'old-concurrent-lineage'],
        ]);
        $fingerprint = hash('sha256', 'concurrent-system-root');
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
                'failure_fingerprint' => $fingerprint,
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
            ]);
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION explicit_retry_hold_document_lock() RETURNS trigger AS $$
BEGIN
    PERFORM pg_sleep(0.75);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER explicit_retry_hold_document_lock
BEFORE UPDATE ON estimate_generation_documents
FOR EACH ROW EXECUTE FUNCTION explicit_retry_hold_document_lock();
SQL);

        $worker = dirname(__DIR__, 3).'/Support/ExplicitDocumentRetryConcurrentWorker.php';
        $command = [PHP_BINARY, $worker, (string) $session->id, (string) $document->id, $sourceVersion, '9'];
        $environment = array_replace(getenv(), array_filter(
            $_ENV,
            static fn (mixed $value): bool => is_string($value),
        ));
        $first = proc_open([...$command, (string) Str::uuid()], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $firstPipes, dirname(__DIR__, 4), $environment);
        $second = proc_open([...$command, (string) Str::uuid()], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $secondPipes, dirname(__DIR__, 4), $environment);
        self::assertIsResource($first);
        self::assertIsResource($second);
        $this->waitForProcessToken($first, $firstPipes[1], $firstPipes[2], 'READY');
        $this->waitForProcessToken($second, $secondPipes[1], $secondPipes[2], 'READY');
        fwrite($firstPipes[0], "GO\n");
        fwrite($secondPipes[0], "GO\n");
        fclose($firstPipes[0]);
        fclose($secondPipes[0]);
        $firstOutput = $this->waitForProcessToken($first, $firstPipes[1], $firstPipes[2], 'RESULT ');
        $secondOutput = $this->waitForProcessToken($second, $secondPipes[1], $secondPipes[2], 'RESULT ');
        $firstError = stream_get_contents($firstPipes[2]);
        $secondError = stream_get_contents($secondPipes[2]);
        self::assertSame(0, proc_close($first), $firstError);
        self::assertSame(0, proc_close($second), $secondError);
        $results = [
            $this->decodeWorkerResult($firstOutput),
            $this->decodeWorkerResult($secondOutput),
        ];

        $dispositions = array_values(array_unique(array_column($results, 'disposition')));
        sort($dispositions);
        self::assertSame(['accepted', 'already_in_progress'], $dispositions);
        self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['dispatches'] === 1));
        self::assertCount(1, array_unique(array_column($results, 'attempt_id')));
        self::assertCount(1, EstimateGenerationDocument::query()->findOrFail($document->id)->meta['explicit_document_retry_history']);
        self::assertSame(1, EstimateGenerationAuditEvent::query()->count());
    }

    public function test_terminal_retry_repair_migration_closes_only_matching_lineage(): void
    {
        $sourceVersion = 'sha256:'.hash('sha256', 'terminal-repair');
        $attemptId = (string) Str::uuid();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => 38,
            'project_id' => 52,
            'user_id' => 7,
            'status' => 'input_review_required',
            'processing_stage' => 'input_review_required',
            'processing_progress' => 100,
            'state_version' => 9,
            'input_payload' => [],
            'problem_flags' => [],
        ]);
        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => 38,
            'project_id' => 52,
            'user_id' => 7,
            'filename' => 'terminal.pdf',
            'storage_path' => 'org-38/terminal.pdf',
            'status' => 'failed',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'source_version' => $sourceVersion,
            'page_count' => 3,
            'processed_page_count' => 0,
            'facts_summary' => [
                'processing_outcome' => [
                    'type' => 'system_failure',
                    'counts' => ['included' => 3, 'ready' => 0, 'system_failed' => 3, 'needs_user_action' => 0],
                ],
            ],
            'meta' => [
                'processing_attempt_id' => $attemptId,
                'explicit_document_retry' => [
                    'attempt_id' => $attemptId,
                    'source_version' => $sourceVersion,
                    'status' => 'processing',
                ],
            ],
        ]);
        $fingerprint = hash('sha256', 'terminal-repair-root');
        foreach (range(1, 3) as $index) {
            EstimateGenerationProcessingUnit::query()->create([
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
                'failure_code' => 'document_unit_processing_failed',
                'failure_fingerprint' => $fingerprint,
                'locator' => ['page' => $index],
                'metadata' => ['failure_category' => 'terminal', 'processing_attempt_id' => $attemptId],
                'started_at' => now()->subSecond(),
                'failed_at' => now(),
            ]);
        }
        $foreign = $document->replicate()->fill([
            'meta' => [
                'processing_attempt_id' => 'different-lineage',
                'explicit_document_retry' => [
                    'attempt_id' => 'different-lineage',
                    'source_version' => $sourceVersion,
                    'status' => 'processing',
                ],
            ],
        ]);
        $foreign->save();

        $migration = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_13_000210_finalize_terminal_explicit_document_retries.php';
        $migration->up();

        $document->refresh();
        self::assertSame('failed', $document->meta['explicit_document_retry']['status']);
        self::assertNotEmpty($document->meta['explicit_document_retry']['completed_at']);
        self::assertSame('system_failure', $document->meta['explicit_document_retry']['terminal_reason']);
        self::assertSame(3, $document->meta['explicit_document_retry']['actual_execution_count']);
        self::assertSame('sha256:'.$fingerprint, $document->meta['explicit_document_retry']['diagnostic_fingerprint']);
        self::assertSame(3, $document->meta['explicit_document_retry']['counts']['system_failed']);
        self::assertSame('failed', $document->status);
        self::assertSame('system_failure', $document->facts_summary['processing_outcome']['type']);
        self::assertSame('failed', $session->fresh()->status->value);
        self::assertSame('processing', $foreign->fresh()->meta['explicit_document_retry']['status']);
    }

    private function waitForProcessToken($process, $stdout, $stderr, string $token): string
    {
        stream_set_blocking($stdout, false);
        $output = '';
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            $chunk = fread($stdout, 8192);
            if ($chunk !== false) {
                $output .= $chunk;
            }
            if (str_contains($output, $token)) {
                return $output;
            }
            $status = proc_get_status($process);
            if (! $status['running']) {
                self::fail(trim((string) stream_get_contents($stderr)) ?: 'Concurrent retry worker stopped before '.$token.'. Output: '.$output);
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);

        self::fail('Concurrent retry worker timed out before '.$token.'.');
    }

    /** @return array{disposition: string, attempt_id: string, dispatches: int} */
    private function decodeWorkerResult(string $output): array
    {
        $position = strpos($output, 'RESULT ');
        self::assertNotFalse($position);
        $result = json_decode(trim(substr($output, $position + 7)), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }

    private function createContractTables(): void
    {
        $this->dropContractTables();
        DB::unprepared(<<<'SQL'
CREATE TABLE estimate_generation_sessions (
 id bigserial PRIMARY KEY, organization_id bigint, project_id bigint, user_id bigint, status varchar(40),
 processing_stage varchar(80), processing_progress integer default 0, input_payload jsonb default '{}',
 analysis_payload jsonb, draft_payload jsonb, problem_flags jsonb default '[]', applied_estimate_id bigint,
 last_error text, failure_code varchar(80), state_version integer default 0, resume_status varchar(40),
 created_at timestamptz, updated_at timestamptz
);
CREATE TABLE estimate_generation_documents (
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
CREATE TABLE estimate_generation_processing_units (
 id bigserial PRIMARY KEY, organization_id bigint, project_id bigint, session_id bigint, document_id bigint,
 unit_type varchar(40), unit_index integer, source_version varchar(80), status varchar(20), attempt_count integer default 0,
 claim_token varchar(36), lease_expires_at timestamptz, output_version varchar(80), output_count integer default 0,
 dispatch_attempt_count integer default 0, last_dispatched_at timestamptz, next_dispatch_at timestamptz,
 failure_code varchar(80), failure_fingerprint varchar(64), locator jsonb default '{}', metadata jsonb default '{}',
 started_at timestamptz, completed_at timestamptz, failed_at timestamptz, created_at timestamptz, updated_at timestamptz
);
CREATE TABLE estimate_generation_document_pages (
 id bigserial PRIMARY KEY, document_id bigint, processing_unit_id bigint, source_version varchar(80), output_version varchar(80),
 organization_id bigint, project_id bigint, session_id bigint, page_number integer, width integer, height integer,
 rotation integer, language_codes jsonb, text text, text_hash varchar(64), confidence numeric, raw_payload_path varchar(255),
 normalized_payload jsonb, quality_flags jsonb, status varchar(20), excluded_at timestamptz, excluded_reason text,
 retry_attempt_id varchar(36), last_retry_requested_at timestamptz, created_at timestamptz, updated_at timestamptz
);
CREATE TABLE estimate_generation_audit_events (
 id bigserial PRIMARY KEY, session_id bigint, package_id bigint, user_id bigint, event_type varchar(100),
 payload jsonb default '{}', created_at timestamptz, updated_at timestamptz
);
SQL);
    }

    private function dropContractTables(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS estimate_generation_audit_events CASCADE;
DROP TABLE IF EXISTS estimate_generation_document_pages CASCADE;
DROP TABLE IF EXISTS estimate_generation_processing_units CASCADE;
DROP TABLE IF EXISTS estimate_generation_documents CASCADE;
DROP TABLE IF EXISTS estimate_generation_sessions CASCADE;
DROP FUNCTION IF EXISTS explicit_retry_hold_document_lock();
SQL);
    }
}
