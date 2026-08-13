<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentMutationSessionReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\RetryEstimateGenerationDocumentRequest;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationAuditEvent;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineFailureDetails;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Http\Middleware\InterfaceMiddleware;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Http\Middleware\SetOrganizationContext;
use App\Http\Middleware\WebInterfaceSecurityMiddleware;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Auth\User as FrameworkUser;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Mockery;
use RuntimeException;
use Tymon\JWTAuth\Facades\JWTAuth;

final class ExplicitDocumentRetryHttpContractTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('pgsql', DB::getDriverName());
        self::assertStringEndsWith('_testing', (string) DB::getDatabaseName());
        $this->createContractTables();
        $this->withoutMiddleware([
            SetOrganizationContext::class,
            InterfaceMiddleware::class,
            ProjectContextMiddleware::class,
            AuthorizeMiddleware::class,
            WebInterfaceSecurityMiddleware::class,
        ]);

        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);

        $reconciler = Mockery::mock(DocumentMutationSessionReconciler::class);
        $reconciler->allows('changed')->andReturnUsing(
            static fn (EstimateGenerationSession $session): EstimateGenerationSession => $session,
        );
        $this->app->instance(DocumentMutationSessionReconciler::class, $reconciler);

        $readiness = Mockery::mock(DocumentGenerationReadinessService::class);
        $readiness->allows('evaluate')->andReturn(['summary' => ['pending_count' => 1]]);
        $this->app->instance(DocumentGenerationReadinessService::class, $readiness);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->dropContractTables();
        parent::tearDown();
    }

    public function test_protected_retry_route_returns_safe_unauthenticated_contract(): void
    {
        $this->postJson('/api/v1/admin/projects/1/estimate-generation/sessions/1/documents/1/retry', [
            'state_version' => 0,
            'source_version' => 'sha256:'.str_repeat('0', 64),
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnauthorized()->assertJsonPath('success', false);
    }

    public function test_retry_request_rejects_authenticated_principal_that_is_not_an_application_user(): void
    {
        $request = RetryEstimateGenerationDocumentRequest::create('/retry', 'POST', [
            'state_version' => 0,
            'source_version' => 'sha256:'.str_repeat('0', 64),
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(static fn (): FrameworkUser => new FrameworkUser);

        self::assertFalse($request->authorize());

        $this->expectException(AuthorizationException::class);
        $request->actor();
    }

    public function test_authenticated_stale_retry_returns_conflict_without_dispatch(): void
    {
        Queue::fake();
        [$actor, $headers, $project, $session, $document] = $this->retryFixture();

        $response = $this->withHeaders($headers)
            ->postJson($this->endpoint($project, $session, $document), [
                'state_version' => $session->state_version,
                'source_version' => 'sha256:'.str_repeat('0', 64),
                'idempotency_key' => (string) Str::uuid(),
            ]);
        self::assertSame(409, $response->status(), $response->getContent());
        $response
            ->assertJsonPath('success', false)
            ->assertJsonPath('disposition', 'stale_source');

        Queue::assertNothingPushed();
    }

    public function test_authenticated_retry_passes_application_user_and_same_key_replay_dispatches_once(): void
    {
        Queue::fake();
        [$actor, $headers, $project, $session, $document, $sourceVersion] = $this->retryFixture();
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'state_version' => $session->state_version,
            'source_version' => $sourceVersion,
            'idempotency_key' => $idempotencyKey,
            'reason' => 'Системная причина устранена',
        ];
        $endpoint = $this->endpoint($project, $session, $document);

        $accepted = $this->withHeaders($headers)->postJson($endpoint, $payload);
        self::assertSame(200, $accepted->status(), $accepted->getContent());
        $accepted->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.retry.disposition', 'accepted');
        $attemptId = $accepted->json('data.retry.attempt_id');

        $replayed = $this->withHeaders($headers)->postJson($endpoint, $payload);
        $replayed->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.retry.disposition', 'replayed')
            ->assertJsonPath('data.retry.attempt_id', $attemptId);

        $audit = EstimateGenerationAuditEvent::query()
            ->where('event_type', 'document_explicit_retry_requested')
            ->sole();
        self::assertSame($actor->id, $audit->user_id);
        self::assertSame($actor->id, $audit->payload['actor_id']);
        self::assertSame(hash('sha256', $idempotencyKey), $audit->payload['idempotency_hash']);
        self::assertCount(1, $document->fresh()->meta['explicit_document_retry_history']);
        Queue::assertPushed(
            ProcessEstimateGenerationDocumentJob::class,
            static fn (ProcessEstimateGenerationDocumentJob $job): bool => $job->afterCommit === true,
        );
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 1);
    }

    public function test_unexpected_retry_failure_is_reported_and_logged_without_sensitive_values(): void
    {
        Queue::fake();
        [$actor, $headers, $project, $session, $document, $sourceVersion] = $this->retryFixture();
        $correlationId = (string) Str::uuid();
        $headers['X-Correlation-ID'] = $correlationId;
        $idempotencyKey = (string) Str::uuid();
        $previous = new LogicException('inner-sensitive-message', 41);
        $failure = new RuntimeException('outer-sensitive-message', 73, $previous);

        $authorizationCall = 0;
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->expects('can')->twice()->andReturnUsing(
            static function () use (&$authorizationCall, $failure): bool {
                $authorizationCall++;

                if ($authorizationCall === 1) {
                    return true;
                }

                throw $failure;
            },
        );
        $this->app->instance(AuthorizationService::class, $authorization);

        Exceptions::fake();
        $details = PipelineFailureDetails::from($failure);
        $previousFingerprint = PipelineFailureDetails::from($previous)->fingerprint;
        $loggedContext = null;
        $channelLogger = Mockery::mock();
        $channelLogger->allows('log');
        Log::spy();
        Log::shouldReceive('channel')->andReturn($channelLogger);
        Log::shouldReceive('error')->once()->withArgs(
            static function (string $message, array $context) use (&$loggedContext): bool {
                if ($message !== '[EstimateGeneration] Document retry failed') {
                    return false;
                }

                $loggedContext = $context;

                return true;
            },
        );

        $response = $this->withHeaders($headers)->postJson($this->endpoint($project, $session, $document), [
            'state_version' => $session->state_version,
            'source_version' => $sourceVersion,
            'idempotency_key' => $idempotencyKey,
        ]);

        $response->assertInternalServerError()
            ->assertJsonMissing(['outer-sensitive-message', 'inner-sensitive-message', $idempotencyKey]);
        Exceptions::assertReported(static fn (RuntimeException $reported): bool => $reported === $failure);
        Exceptions::assertReportedCount(1);
        self::assertIsArray($loggedContext);
        $encoded = json_encode($loggedContext, JSON_THROW_ON_ERROR);
        self::assertSame('document_retry_failed', $loggedContext['failure_code']);
        self::assertSame($project->id, $loggedContext['project_id']);
        self::assertSame($session->id, $loggedContext['session_id']);
        self::assertSame($document->id, $loggedContext['document_id']);
        self::assertSame(RuntimeException::class, $loggedContext['exception_class']);
        self::assertSame($details->fingerprint, $loggedContext['failure_fingerprint']);
        self::assertSame(hash('sha256', $previousFingerprint), $loggedContext['previous_chain_fingerprint']);
        self::assertSame($response->headers->get('X-Correlation-ID'), $loggedContext['correlation_id']);
        self::assertStringNotContainsString('outer-sensitive-message', $encoded);
        self::assertStringNotContainsString('inner-sensitive-message', $encoded);
        self::assertStringNotContainsString($idempotencyKey, $encoded);
        Queue::assertNothingPushed();
    }

    /**
     * @return array{0: User, 1: array<string, string>, 2: Project, 3: EstimateGenerationSession, 4: EstimateGenerationDocument, 5: string}
     */
    private function retryFixture(): array
    {
        $organizationId = 38;
        $userId = 7;
        DB::table('projects')->insert([
            'id' => 52,
            'organization_id' => $organizationId,
            'name' => 'HTTP contract project',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail(52);
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'HTTP contract actor',
            'email' => 'http-contract@example.test',
            'password' => 'not-used',
            'current_organization_id' => $organizationId,
            'is_active' => true,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor = User::query()->findOrFail($userId);
        $token = JWTAuth::claims(['organization_id' => $organizationId])->fromUser($actor);
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'user_id' => $actor->id,
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'state_version' => 9,
            'input_payload' => [],
            'problem_flags' => [],
        ]);
        $checksum = hash('sha256', 'saved-document');
        $sourceVersion = 'sha256:'.$checksum;
        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'user_id' => $actor->id,
            'filename' => 'saved.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'org-'.$organizationId.'/estimate-generation/documents/saved.pdf',
            'status' => 'needs_review',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'checksum_sha256' => $checksum,
            'source_version' => $sourceVersion,
            'page_count' => 3,
            'processed_page_count' => 0,
            'facts_summary' => [],
            'meta' => ['processing_attempt_id' => 'old-attempt'],
        ]);
        foreach (range(1, 3) as $pageNumber) {
            $unit = EstimateGenerationProcessingUnit::query()->create([
                'organization_id' => $organizationId,
                'project_id' => $project->id,
                'session_id' => $session->id,
                'document_id' => $document->id,
                'unit_type' => 'pdf_page',
                'unit_index' => $pageNumber,
                'source_version' => $sourceVersion,
                'status' => 'failed',
                'attempt_count' => 3,
                'output_count' => 0,
                'failure_code' => 'document_geometry_processing_failed',
                'failure_fingerprint' => hash('sha256', 'same-root'),
                'failed_at' => now(),
                'locator' => ['page' => $pageNumber],
                'metadata' => ['failure_category' => 'terminal'],
            ]);
            EstimateGenerationDocumentPage::query()->create([
                'document_id' => $document->id,
                'processing_unit_id' => $unit->id,
                'source_version' => $sourceVersion,
                'organization_id' => $organizationId,
                'project_id' => $project->id,
                'session_id' => $session->id,
                'page_number' => $pageNumber,
                'text' => 'Старый результат',
                'status' => 'failed',
            ]);
        }

        return [$actor, $headers, $project, $session, $document, $sourceVersion];
    }

    private function endpoint(
        Project $project,
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document,
    ): string {
        return "/api/v1/admin/projects/{$project->id}/estimate-generation/sessions/{$session->id}/documents/{$document->id}/retry";
    }

    private function createContractTables(): void
    {
        $this->dropContractTables();
        DB::unprepared(<<<'SQL'
CREATE TABLE users (
 id bigserial PRIMARY KEY, name varchar(255), email varchar(255), password varchar(255), is_active boolean default true,
 current_organization_id bigint, email_verified_at timestamptz, settings jsonb, remember_token varchar(100),
 deleted_at timestamptz, created_at timestamptz, updated_at timestamptz
);
CREATE TABLE projects (
 id bigserial PRIMARY KEY, organization_id bigint, name varchar(255), deleted_at timestamptz,
 created_at timestamptz, updated_at timestamptz
);
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
DROP TABLE IF EXISTS projects CASCADE;
DROP TABLE IF EXISTS users CASCADE;
SQL);
    }
}
