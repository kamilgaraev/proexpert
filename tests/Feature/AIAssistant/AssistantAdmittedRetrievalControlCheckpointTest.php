<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDocumentsTool;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\File;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;
use Throwable;

final class AssistantAdmittedRetrievalControlCheckpointTest extends TestCase
{
    protected array $connectionsToTransact = [];

    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private AIAssistantDocument $document;
    private AIAssistantDocumentUnit $unit;
    private RagChunk $chunk;
    private string $text;
    private ?AssistantRequestExecutionContext $execution = null;
    private mixed $originalRedis = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-24 10:00:00');
        Queue::fake();
        config(['cache.default' => 'array', 'logging.default' => 'stderr',
            'ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true]);
        self::assertSame(0, DB::transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->originalRedis = Redis::getFacadeRoot();
        Redis::swap(new class {
            public function connection(?string $name = null): object
            {
                return new class {
                    public function eval(mixed ...$arguments): int { return 1; }
                };
            }
        });
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(
            array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        $this->text = 'QA-CONTROL-001 '.str_repeat('x', 365).' EXACT_APPROVED_AFTER_340';
        $file = Model::withoutEvents(fn () => File::query()->create([
            'organization_id' => $this->fixture->organization->id, 'user_id' => $this->fixture->owner->id,
            'fileable_type' => $this->project->getMorphClass(), 'fileable_id' => $this->project->id,
            'name' => 'Control fixture.txt', 'original_name' => 'Control fixture.txt', 'path' => 'private/control.txt',
            'disk' => 's3', 'mime_type' => 'text/plain', 'size' => strlen($this->text)]));
        $this->document = Model::withoutEvents(fn () => AIAssistantDocument::query()->create([
            'organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id, 'file_id' => $file->id,
            'parent_entity_type' => 'project', 'parent_entity_id' => (string) $this->project->id, 'storage_path' => $file->path,
            'filename' => $file->name, 'mime_type' => 'text/plain', 'checksum' => hash('sha256', $this->text),
            'size_bytes' => strlen($this->text), 'status' => 'ready', 'coverage_status' => 'full', 'processed_at' => now(),
            'extracted_text' => $this->text, 'metadata' => []]));
        $this->unit = AIAssistantDocumentUnit::query()->create(['document_id' => $this->document->id,
            'unit_type' => 'page', 'unit_index' => 0, 'text' => $this->text, 'checksum' => hash('sha256', $this->text), 'provenance' => ['page' => 1]]);
        $provider = $this->createMock(RagEmbeddingProviderInterface::class);
        $provider->method('provider')->willReturn('offline');
        $provider->method('model')->willReturn('offline');
        $provider->method('dimensions')->willReturn(256);
        $provider->expects(self::never())->method('embed');
        $this->app->instance(RagEmbeddingProviderInterface::class, $provider);
        $data = FileRagSource::chunkForUnit($this->document, $this->unit);
        $source = RagSource::query()->create((new RagIndexer($provider, new RagSourceRegistry([])))->coverageIdentity($data)
            + ['title' => $data->title, 'source_version' => now()->toAtomString(), 'metadata' => $data->metadata, 'indexed_at' => now()]);
        $this->chunk = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $data->organizationId,
            'project_id' => $data->projectId, 'chunk_index' => 0, 'content' => $this->text, 'content_hash' => hash('sha256', $this->text),
            'metadata' => $data->metadata + ['chunk_index' => 0, 'chunk_count' => 1]]);
        $this->actingAs($this->fixture->owner);
        app(AssistantDataAccessPolicy::class)->setTrustedSurface(KnowledgeSurface::ADMIN);
        app(AICreditService::class)->grant($this->fixture->organization, 100000, 'purchase', null, 'control-'.Str::uuid());
    }

    protected function tearDown(): void
    {
        $this->execution?->restoreDatabaseStatementTimeouts();
        $this->app->forgetInstance(AssistantRequestExecutionContext::class);
        if ($this->originalRedis !== null) { Redis::swap($this->originalRedis); }
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function start(?array $payload = null): AssistantRequest
    {
        $payload ??= ['request_id' => (string) Str::uuid(), 'message' => 'QA-CONTROL-001',
            'conversation_id' => null, 'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] ??= app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        $lifecycle = app(AssistantRequestLifecycle::class);
        $started = $lifecycle->startQueued($this->fixture->organization, $this->fixture->owner, null, $payload, 'admin');
        $request = $lifecycle->claimQueued($started['request']->id);
        self::assertNotNull($request);
        $this->execution = $lifecycle->createExecutionContext($request, $this->fixture->owner);
        $this->app->instance(AssistantRequestExecutionContext::class, $this->execution);
        $this->execution->activate();
        $this->execution->assertCanContinue();
        return $request;
    }

    private function assertFails(string $type, callable $operation): void
    {
        $caught = null;
        try { $operation(); } catch (Throwable $failure) { $caught = $failure; }
        self::assertInstanceOf($type, $caught);
    }

    private function enterDocumentScope(): OrdinaryAssistantScopedContext
    {
        $operator = User::factory()->create(['is_active' => true]);
        UserRoleAssignment::query()->create(['user_id' => $operator->id, 'role_slug' => 'system_admin',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM, 'context_id' => AuthorizationContext::getSystemContext()->id,
            'assigned_by' => $operator->id, 'is_active' => true]);
        $enrollments = app(OrdinaryAssistantEnrollmentService::class);
        $cohort = $enrollments->assign($operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, [OrdinaryAssistantDocumentProjection::TOOL], OrdinaryAssistantDocumentProjection::VERSION);
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'QA-CONTROL-001',
            'conversation_id' => null, 'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        $preview = $enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
            [['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => ['project_id' => $this->project->id,
                'document_id' => $this->document->id, 'chunk_ids' => [$this->chunk->id]]]]);
        $enrollments->confirm($this->fixture->owner, $cohort->organization_id, $preview['enrollment_id'], $preview['manifest_hash'],
            OrdinaryAssistantEnrollmentService::DECLARATION);
        $scope = app(OrdinaryAssistantScopedContext::class);
        $scope->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        self::assertTrue($scope->allowsExpansion('document_rag'));
        $this->start($payload);
        return $scope;
    }

    private function toolArguments(): array
    {
        return ['query' => 'QA-CONTROL-001', 'project_id' => $this->project->id,
            'source_types' => ['file_document'], 'limit' => 1, 'retrieval_mode' => 'lexical'];
    }

    private function isRetrievalCandidateQuery(QueryExecuted $event): bool
    {
        return str_contains($event->sql, 'from "ai_rag_chunks" as "c"')
            && str_contains($event->sql, '"s"."id" as "rag_source_id"')
            && str_contains($event->sql, '"c"."metadata" as "chunk_metadata"');
    }

    public function test_control_uses_current_request_metadata_and_less_sql_than_full_checkpoint(): void
    {
        $this->start();
        $checkpoint = $this->execution->requestControlCheckpoint();
        $phase = null;
        $counts = ['full' => 0, 'control' => 0];
        $controlMetadataOnly = true;
        DB::listen(static function (QueryExecuted $event) use (&$phase, &$counts, &$controlMetadataOnly): void {
            if ($phase === null) { return; }
            $counts[$phase]++;
            if ($phase === 'control') {
                $controlMetadataOnly = $controlMetadataOnly && str_contains($event->sql, '"ai_assistant_requests"')
                    && str_contains($event->sql, '"cancel_requested_at"') && ! str_contains($event->sql, '*')
                    && ! str_contains($event->sql, 'payload') && ! str_contains($event->sql, 'response');
            }
        });
        $phase = 'full';
        $this->execution->assertCanContinue();
        $phase = 'control';
        $checkpoint();
        $phase = null;
        self::assertGreaterThan(0, $counts['control']);
        self::assertLessThan($counts['full'], $counts['control']);
        self::assertTrue($controlMetadataOnly);
    }

    public function test_current_state_identity_and_missing_rows_fail_without_cached_request_state(): void
    {
        $request = $this->start();
        $checkpoint = $this->execution->requestControlCheckpoint();
        $otherUser = User::factory()->create();
        $otherOrganization = Model::withoutEvents(fn () => Organization::factory()->create());
        $otherConversation = Conversation::query()->create(['organization_id' => $this->fixture->organization->id,
            'user_id' => $this->fixture->owner->id, 'title' => 'Other synthetic conversation']);
        $original = DB::table('ai_assistant_requests')->where('id', $request->id)->first();
        foreach ([['cancel_requested_at' => now()], ['status' => 'completed'],
            ['lease_expires_at' => now()->subSecond()]] as $change) {
            DB::table('ai_assistant_requests')->where('id', $request->id)->update($change);
            $this->assertFails(AssistantRequestCancelled::class, $checkpoint);
            DB::table('ai_assistant_requests')->where('id', $request->id)->update(array_intersect_key((array) $original, $change));
            $checkpoint();
        }
        foreach ([['request_id' => (string) Str::uuid()], ['user_id' => $otherUser->id], ['organization_id' => $otherOrganization->id],
            ['conversation_id' => $otherConversation->id]] as $change) {
            DB::table('ai_assistant_requests')->where('id', $request->id)->update($change);
            $this->assertFails(AuthorizationException::class, $checkpoint);
            DB::table('ai_assistant_requests')->where('id', $request->id)->update(array_intersect_key((array) $original, $change));
        }
        DB::table('ai_assistant_requests')->where('id', $request->id)->delete();
        $this->assertFails(AssistantRequestCancelled::class, $checkpoint);
    }

    public function test_factory_preserves_original_identity_and_allows_legitimate_late_conversation_binding(): void
    {
        $request = $this->start();
        self::assertNull($request->conversation_id);
        $conversation = Conversation::query()->create(['organization_id' => $this->fixture->organization->id,
            'user_id' => $this->fixture->owner->id, 'title' => 'Legitimate late binding']);
        app(AssistantRequestLifecycle::class)->bindConversation($request, $conversation, $this->fixture->owner);
        ($this->execution->requestControlCheckpoint())();
        $originalRequestId = $request->request_id;
        $request->request_id = (string) Str::uuid();
        $this->assertFails(AuthorizationException::class, fn () => $this->execution->requestControlCheckpoint());
        $request->request_id = $originalRequestId;
        $request->id += 1000000;
        $this->assertFails(AuthorizationException::class, fn () => $this->execution->requestControlCheckpoint());
        $legacy = new AssistantRequestExecutionContext(app(AssistantRequestLifecycle::class), new AssistantRequest,
            $this->fixture->owner, 10000);
        self::assertGreaterThan(0, $legacy->remainingMilliseconds());
        $this->assertFails(AuthorizationException::class, fn () => $legacy->requestControlCheckpoint());
        $strict = AssistantRequest::preventsAccessingMissingAttributes();
        AssistantRequest::preventAccessingMissingAttributes();
        try {
            $partial = new AssistantRequest;
            $partial->setRawAttributes(['id' => 42, 'user_id' => $this->fixture->owner->id,
                'organization_id' => $this->fixture->organization->id, 'created_at' => now()->toDateTimeString()], true);
            $partial->exists = true;
            $partialContext = new AssistantRequestExecutionContext(app(AssistantRequestLifecycle::class), $partial,
                $this->fixture->owner, 10000);
            self::assertGreaterThan(0, $partialContext->remainingMilliseconds());
            $this->assertFails(AuthorizationException::class, fn () => $partialContext->requestControlCheckpoint());
        } finally { AssistantRequest::preventAccessingMissingAttributes($strict); }
    }

    public function test_control_denies_transactions_nondefault_connections_and_effective_deadlines(): void
    {
        $request = $this->start();
        $checkpoint = $this->execution->requestControlCheckpoint();
        DB::beginTransaction();
        try { $this->assertFails(AuthorizationException::class, $checkpoint); } finally { DB::rollBack(); }
        DB::connection()->getPdo()->beginTransaction();
        try { $this->assertFails(AuthorizationException::class, $checkpoint); } finally { DB::connection()->getPdo()->rollBack(); }
        $request->setConnection('unconfigured-control-connection');
        $this->assertFails(AuthorizationException::class, fn () => $this->execution->requestControlCheckpoint());
        $request->setConnection(null);
        $this->assertFails(AssistantRequestDeadlineExceeded::class, fn () => $this->execution->withCleanupBudget(
            fn () => $this->execution->requestControlCheckpoint()));
        $this->assertFails(AssistantRequestDeadlineExceeded::class, fn () => $this->execution->withCleanupBudget($checkpoint));
        self::assertSame('cleanup-ok', $this->execution->withCleanupBudget(static fn (): string => 'cleanup-ok'));
        $reentrancy = new \ReflectionProperty($this->execution, 'lifecycleCheckpointInProgress');
        $reentrancy->setValue($this->execution, true);
        try {
            $this->assertFails(AuthorizationException::class, fn () => $this->execution->requestControlCheckpoint());
            $this->assertFails(AuthorizationException::class, $checkpoint);
        } finally { $reentrancy->setValue($this->execution, false); }
        $this->assertFails(AssistantRequestDeadlineExceeded::class, fn () => $this->execution->withOperationBudget(
            static function () use ($checkpoint): void { usleep(3000); $checkpoint(); }, 1, 'pgsql'));
        $deadline = new \ReflectionProperty($this->execution, 'deadlineNanoseconds');
        $deadline->setValue($this->execution, hrtime(true) - 1);
        $this->assertFails(AssistantRequestDeadlineExceeded::class, $checkpoint);
    }

    public function test_actual_admitted_tool_uses_control_and_returns_exact_approved_fragment(): void
    {
        $scope = $this->enterDocumentScope();
        $controlReads = 0;
        DB::listen(static function (QueryExecuted $event) use (&$controlReads): void {
            if (str_contains($event->sql, '"ai_assistant_requests"') && str_contains($event->sql, '"cancel_requested_at"')
                && str_contains($event->sql, '"lease_expires_at"') && ! str_contains($event->sql, '*')) { $controlReads++; }
        });
        $result = app(SearchAssistantDocumentsTool::class)->execute($this->toolArguments(), $this->fixture->owner, $this->fixture->organization);
        self::assertIsArray($result);
        self::assertGreaterThan(0, $controlReads);
        self::assertSame($this->text, $result['data']['fragments'][0]['content']);
        self::assertGreaterThan(340, mb_strpos($result['data']['fragments'][0]['content'], 'EXACT_APPROVED_AFTER_340'));
        self::assertSame($this->chunk->id, $result['data']['fragments'][0]['chunk_id']);
        self::assertSame($result, $scope->projectToolResult(OrdinaryAssistantDocumentProjection::TOOL, $this->toolArguments(), $result));
    }

    public function test_full_post_read_authorization_still_rejects_current_actor_revocation(): void
    {
        $this->enterDocumentScope();
        $changed = false;
        DB::listen(function (QueryExecuted $event) use (&$changed): void {
            if (! $changed && $this->isRetrievalCandidateQuery($event)) {
                $changed = true;
                DB::table('users')->where('id', $this->fixture->owner->id)->update(['is_active' => false]);
            }
        });
        $caught = null;
        $returned = false;
        try {
            app(SearchAssistantDocumentsTool::class)->execute($this->toolArguments(), $this->fixture->owner, $this->fixture->organization);
            $returned = true;
        } catch (Throwable $failure) { $caught = $failure; }
        self::assertTrue($changed);
        self::assertFalse($returned);
        self::assertInstanceOf(AssistantRequestCancelled::class, $caught);
    }

    public function test_full_pre_read_authorization_still_rejects_current_actor_revocation(): void
    {
        $this->enterDocumentScope();
        DB::table('users')->where('id', $this->fixture->owner->id)->update(['is_active' => false]);
        $candidateRead = false;
        DB::listen(function (QueryExecuted $event) use (&$candidateRead): void {
            $candidateRead = $candidateRead || $this->isRetrievalCandidateQuery($event);
        });
        $this->assertFails(AssistantRequestCancelled::class, fn () => app(SearchAssistantDocumentsTool::class)->execute(
            $this->toolArguments(), $this->fixture->owner, $this->fixture->organization));
        self::assertFalse($candidateRead);
    }

    public function test_full_projection_still_rejects_source_changes_after_candidate_read(): void
    {
        $this->enterDocumentScope();
        $changed = false;
        DB::listen(function (QueryExecuted $event) use (&$changed): void {
            if (! $changed && $this->isRetrievalCandidateQuery($event)) {
                $changed = true;
                DB::table('ai_assistant_document_units')->where('id', $this->unit->id)->update(['text' => 'changed synthetic unit']);
            }
        });
        $caught = null;
        $returned = false;
        try {
            app(SearchAssistantDocumentsTool::class)->execute($this->toolArguments(), $this->fixture->owner, $this->fixture->organization);
            $returned = true;
        } catch (Throwable $failure) { $caught = $failure; }
        self::assertTrue($changed);
        self::assertFalse($returned);
        self::assertInstanceOf(AssistantEgressDenied::class, $caught);
    }
}
