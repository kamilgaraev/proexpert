<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Jobs\ExecuteAssistantChatJob;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantProviderFactory;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\QueuedAssistantChatService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Logging\LoggingService;
use App\Services\Modules\PackageCatalogService;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantGuardedFinalizationTest extends TestCase
{
    protected array $connectionsToTransact = [];

    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private QualityDefect $defect;
    private AIAssistantDocument $document;
    private RagChunk $chunk;
    private User $reviewer;
    private UserRoleAssignment $reviewerAssignment;
    private OrdinaryAssistantEnrollmentService $enrollments;
    private mixed $originalRedis;
    private const ACCOUNT = 'PRIVATE_ACCOUNT_NOT_APPROVED';
    private const ANSWER = 'В утверждённом фрагменте указано 17 тонн арматуры.';

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(0, DB::transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
        Carbon::setTestNow('2026-10-23 10:00:00');
        Queue::fake();
        $this->originalRedis = Redis::getFacadeRoot();
        Redis::swap(new class
        {
            public function connection(?string $name = null): object
            {
                return new class
                {
                    public function eval(mixed ...$arguments): int { return 1; }
                };
            }
        });
        config(['cache.default' => 'array', 'logging.default' => 'stderr', 'ai-assistant.enabled' => true,
            'ai-assistant-credits.enforce' => true, 'ai-assistant.llm.provider' => 'timeweb',
            'ai-assistant.llm.timeweb.base_uri' => 'https://fixture.invalid/v1',
            'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->fixture->owner->updateQuietly(['name' => self::ACCOUNT]);
        $this->project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        $this->defect = QualityDefect::withoutEvents(fn () => QualityDefect::query()->create([
            'organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id,
            'defect_number' => (string) Str::uuid(), 'title' => 'Учебное критическое замечание',
            'severity' => 'critical', 'status' => 'open', 'due_date' => '2026-10-12']));
        $this->reviewer = User::factory()->create(['current_organization_id' => $this->fixture->organization->id, 'is_active' => true]);
        $this->fixture->organization->users()->attach($this->reviewer->id,
            ['is_owner' => true, 'is_active' => true, 'project_access_mode' => 'all_projects']);
        $this->reviewerAssignment = UserRoleAssignment::query()->create(['user_id' => $this->reviewer->id,
            'role_slug' => 'organization_owner', 'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => AuthorizationContext::getOrganizationContext($this->fixture->organization->id)->id,
            'assigned_by' => $this->fixture->owner->id, 'is_active' => true]);
        $provider = $this->createMock(RagEmbeddingProviderInterface::class);
        $provider->method('provider')->willReturn('offline');
        $provider->method('model')->willReturn('offline');
        $provider->method('dimensions')->willReturn(256);
        $provider->expects(self::never())->method('embed');
        $this->app->instance(RagEmbeddingProviderInterface::class, $provider);
        $file = Model::withoutEvents(fn () => File::query()->create(['organization_id' => $this->fixture->organization->id,
            'user_id' => $this->fixture->owner->id, 'fileable_type' => $this->project->getMorphClass(), 'fileable_id' => $this->project->id,
            'name' => 'Учебный документ.txt', 'original_name' => 'Учебный документ.txt', 'path' => 'private/'.$this->project->id.'/sample.txt',
            'disk' => 's3', 'mime_type' => 'text/plain', 'size' => 100]));
        $this->document = Model::withoutEvents(fn () => AIAssistantDocument::query()->create([
            'organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id, 'file_id' => $file->id,
            'parent_entity_type' => 'project', 'parent_entity_id' => (string) $this->project->id, 'storage_path' => $file->path,
            'filename' => $file->name, 'mime_type' => 'text/plain', 'checksum' => hash('sha256', 'document'), 'size_bytes' => 100,
            'status' => 'ready', 'coverage_status' => 'full', 'processed_at' => now(), 'extracted_text' => 'Учебный текст', 'metadata' => []]));
        $text = 'арматура: '.str_repeat('учебные сведения ', 32).'Утверждённый объём: 17 тонн';
        $unit = AIAssistantDocumentUnit::query()->create(['document_id' => $this->document->id, 'unit_type' => 'page',
            'unit_index' => 0, 'text' => $text, 'checksum' => hash('sha256', $text), 'provenance' => ['page' => 1]]);
        $data = FileRagSource::chunkForUnit($this->document, $unit);
        $source = RagSource::query()->create((new RagIndexer($provider, new RagSourceRegistry([])))->coverageIdentity($data)
            + ['title' => $data->title, 'source_version' => now()->toAtomString(), 'metadata' => $data->metadata, 'indexed_at' => now()]);
        $this->chunk = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $data->organizationId,
            'project_id' => $data->projectId, 'chunk_index' => 0, 'content' => $text, 'content_hash' => hash('sha256', $text),
            'metadata' => $data->metadata + ['chunk_index' => 0, 'chunk_count' => 1], 'embedding_provider' => 'offline', 'embedding_model' => 'offline']);
        $this->enrollments = app(OrdinaryAssistantEnrollmentService::class);
        $this->actingAs($this->fixture->owner);
        app(AICreditService::class)->grant($this->fixture->organization, 100000, 'purchase', null, 'finalization-'.Str::uuid());
    }

    protected function tearDown(): void
    {
        Redis::swap($this->originalRedis);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_actual_queued_mixed_manifest_keeps_two_document_outputs_and_final_publication_guards(): void
    {
        $this->runQueued();
    }

    public function test_unused_sql_revocation_after_actual_job_membership_blocks_publication(): void
    {
        $this->runQueued(true);
    }

    public function test_canonical_matcher_preserves_nested_identity_and_top_level_extra_semantics(): void
    {
        [$context, $local] = $this->recordDocument();
        $result = $local + ['_tool_name' => OrdinaryAssistantDocumentProjection::TOOL];
        $reordered = array_reverse($result, true);
        $reordered['source_refs'] = array_map(static fn (array $ref): array => array_reverse($ref, true), $local['source_refs']);
        self::assertSame($local['source_refs'], $context->canonicalReferencesForPublication([$reordered + ['extra_top_level' => true]]));
        self::assertSame($local['source_refs'], $context->validateResults([$result]));
        foreach (['status', 'data', 'refs', 'projection', 'tool'] as $kind) {
            $forged = $result;
            match ($kind) {
                'status' => $forged['status'] = 'forged',
                'data' => $forged['data']['unapproved'] = true,
                'refs' => $forged['source_refs'][0]['source_version'] = 'not-recorded',
                'projection' => $forged['egress_projection_version'] = 'not-recorded',
                'tool' => $forged['_tool_name'] = 'get_project_attention',
            };
            $this->denied(fn () => $context->canonicalReferencesForPublication([$forged]));
        }
        $context->clear();
        $this->denied(fn () => $context->canonicalReferencesForPublication([$result]));
    }

    public static function revocations(): iterable
    {
        foreach (['used document', 'unused SQL', 'reviewer', 'cohort', 'actor ACL'] as $kind) { yield $kind => [$kind]; }
    }

    #[DataProvider('revocations')]
    public function test_membership_never_replaces_fresh_publication_or_the_existing_full_validation(string $kind): void
    {
        [$context, $local, $cohort] = $this->recordDocument();
        $result = $local + ['_tool_name' => OrdinaryAssistantDocumentProjection::TOOL];
        $references = $context->canonicalReferencesForPublication([$result]);
        self::assertNotSame($this->reviewer->id, $this->fixture->owner->id);
        match ($kind) {
            'used document' => $this->chunk->updateQuietly(['content' => 'Изменённый текст', 'content_hash' => hash('sha256', 'Изменённый текст')]),
            'unused SQL' => $this->defect->updateQuietly(['title' => 'Изменённое критическое замечание']),
            'reviewer' => $this->reviewerAssignment->updateQuietly(['is_active' => false]),
            'cohort' => $cohort->updateQuietly(['generation' => (string) Str::uuid()]),
            'actor ACL' => $this->fixture->ownerAssignment->updateQuietly(['is_active' => false]),
        };
        if (in_array($kind, ['used document', 'unused SQL'], true)) {
            self::assertSame($references, $context->canonicalReferencesForPublication([$result]));
        }
        $this->denied(fn () => $context->assertPublication($references));
        $this->denied(fn () => $context->validateResults([$result]));
        self::assertTrue($this->fixture->owner->refresh()->is_active);
    }

    private function recordDocument(): array
    {
        [$cohort, $payload] = $this->enroll();
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        $tool = app(AIToolRegistry::class)->getTool(OrdinaryAssistantDocumentProjection::TOOL);
        self::assertNotNull($tool);
        $raw = $tool->execute($this->args('арматура'), $this->fixture->owner, $this->fixture->organization);
        $local = $context->projectToolResult(OrdinaryAssistantDocumentProjection::TOOL, $this->args('арматура'), $raw);
        self::assertCount(1, $local['data']['fragments']);
        return [$context, $local, $cohort];
    }

    private function runQueued(bool $revokeUnused = false): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $expected = array_column($manifest['sources'], 'projection', 'tool')[OrdinaryAssistantDocumentProjection::TOOL];
        self::assertCount(2, $manifest['sources']);
        self::assertStringContainsString($this->defect->title, json_encode($manifest['sources'][0]['projection'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertGreaterThan(340, mb_strpos($expected['data']['fragments'][0]['content'], '17 тонн'));
        $wire = [];
        $responses = [];
        foreach (['арматура', '17 тонн', null] as $index => $query) {
            $responses[] = function (RequestInterface $request) use (&$wire, $index, $query): Response {
                self::assertTrue(app()->bound(AssistantRequestExecutionContext::class));
                self::assertStringNotContainsString(self::ACCOUNT, (string) $request->getBody());
                $wire[] = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                usleep(20_000);
                return $query === null
                    ? $this->sdkResponse('resp_finalization_3', [['type' => 'message', 'id' => 'msg_finalization_3', 'status' => 'completed',
                        'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => self::ANSWER, 'annotations' => []]]]])
                    : $this->sdkResponse('resp_finalization_'.($index + 1), [['type' => 'function_call', 'id' => 'fc_finalization_'.($index + 1),
                        'call_id' => 'call_finalization_'.($index + 1), 'status' => 'completed', 'name' => OrdinaryAssistantDocumentProjection::TOOL,
                        'arguments' => json_encode($this->args($query), JSON_THROW_ON_ERROR)]]);
            };
        }
        $context = app(OrdinaryAssistantScopedContext::class);
        $egress = new OrdinaryAssistantEgress($context);
        $provider = (new OrdinaryAssistantProviderFactory($egress, app(LoggingService::class)))->make(new MockHandler($responses));
        $service = app()->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ordinaryEgress' => $egress, 'scopedContext' => $context]);
        $this->app->instance(AIAssistantService::class, $service);
        $started = app(QueuedAssistantChatService::class)->submit($cohort->organization_id, $this->fixture->owner, null, $payload, 'admin');
        $membership = false;
        $mutated = false;
        $captureQueries = ['membership' => 0, 'publication' => 0, 'transactional' => 0];
        $active = true;
        DB::listen(static function (QueryExecuted $event) use (&$membership, &$captureQueries, &$active): void {
            if (! $active) { return; }
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96);
            $methods = [];
            $capture = false;
            foreach ($frames as $frame) {
                if (($frame['class'] ?? null) === OrdinaryAssistantScopedContext::class || ($frame['class'] ?? null) === AIAssistantService::class) {
                    $methods[] = $frame['function'] ?? '';
                }
                $capture = $capture || (($frame['class'] ?? null) === OrdinaryAssistantSourceCatalog::class && ($frame['function'] ?? null) === 'captureForReview');
            }
            if (in_array('canonicalReferencesForPublication', $methods, true)) { $membership = true; }
            if (! $membership || ! $capture) { return; }
            $category = in_array('canonicalReferencesForPublication', $methods, true) ? 'membership'
                : (in_array('decorateMetadata', $methods, true) ? 'publication' : (in_array('savePendingSummary', $methods, true) ? 'transactional' : null));
            if ($category !== null) { $captureQueries[$category]++; }
        });
        DB::connection()->beforeExecuting(function () use ($revokeUnused, &$mutated, &$membership, &$active): void {
            if (! $active || ! $revokeUnused || ! $membership || $mutated) { return; }
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96) as $frame) {
                if (($frame['class'] ?? null) === AIAssistantService::class && ($frame['function'] ?? null) === 'decorateMetadata') {
                    $mutated = true;
                    $this->defect->updateQuietly(['title' => 'Источник SQL отозван после проверки результатов']);
                    return;
                }
            }
        });
        try {
            (new ExecuteAssistantChatJob($started['request']->id))->handle(app(AssistantRequestLifecycle::class), app(AssistantDataAccessPolicy::class));
            self::assertFalse($revokeUnused);
        } catch (AssistantEgressDenied $denied) {
            self::assertTrue($revokeUnused);
            self::assertSame('source_stale', $denied->reason);
        } finally { $active = false; }
        self::assertTrue($membership);
        self::assertSame($revokeUnused, $mutated);
        self::assertSame(0, $captureQueries['membership']);
        self::assertGreaterThan(0, $captureQueries['publication']);
        self::assertFalse(app()->bound(AssistantRequestExecutionContext::class));
        self::assertCount(3, $wire);
        foreach ($wire as $index => $body) {
            self::assertSame('auto', $body['tool_choice']);
            self::assertEqualsCanonicalizing(['get_project_attention', OrdinaryAssistantDocumentProjection::TOOL], array_column($body['tools'], 'name'));
            $calls = array_values(array_filter($body['input'], static fn (array $item): bool => ($item['type'] ?? '') === 'function_call'));
            $outputs = array_values(array_filter($body['input'], static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            self::assertCount($index, $calls);
            self::assertCount($index, $outputs);
            foreach ($calls as $number => $call) {
                self::assertSame(OrdinaryAssistantDocumentProjection::TOOL, $call['name']);
                self::assertSame('fc_finalization_'.($number + 1), $call['id']);
                self::assertSame('call_finalization_'.($number + 1), $call['call_id']);
                self::assertSame($this->args($number === 0 ? 'арматура' : '17 тонн'), json_decode($call['arguments'], true, 512, JSON_THROW_ON_ERROR));
                self::assertSame($call['call_id'], $outputs[$number]['call_id']);
                self::assertSame($expected, json_decode($outputs[$number]['output'], true, 512, JSON_THROW_ON_ERROR));
            }
        }
        $current = AssistantRequest::query()->findOrFail($started['request']->id);
        self::assertSame(3, $current->calls_used);
        self::assertSame((int) config('ai-assistant-credits.profiles.normal.max_calls'), $current->max_calls);
        $usage = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $current->reservation_id)->get();
        self::assertCount(3, $usage);
        self::assertTrue($usage->every(static fn ($row): bool => (bool) $row->is_successful));
        if ($revokeUnused) {
            self::assertSame('failed', $current->status);
            self::assertSame('assistant_egress_denied', $current->error_code);
            self::assertSame(0, Conversation::query()->findOrFail($current->conversation_id)->messages()->where('role', 'assistant')->count());
        } else {
            self::assertGreaterThan(0, $captureQueries['transactional']);
            self::assertSame('completed', $current->status);
            self::assertSame(self::ANSWER, $current->response['message']['content']);
            self::assertCount(1, $current->response['message']['metadata']['source_refs']);
            self::assertStringNotContainsString(self::ACCOUNT, json_encode($current->response, JSON_THROW_ON_ERROR));
        }
    }

    private function enroll(): array
    {
        $cohort = $this->enrollments->assign($this->fixture->owner, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, ['get_project_attention', OrdinaryAssistantDocumentProjection::TOOL], OrdinaryAssistantSourceProjection::MIXED_VERSION);
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'Найди в утверждённом тексте ответ и при необходимости уточни поиск.',
            'conversation_id' => null, 'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        $manifest = $this->enrollments->preview($this->reviewer, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
            [['tool' => 'get_project_attention', 'arguments' => ['project_id' => $this->project->id, 'focus' => 'quality', 'limit' => 3]],
                ['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => ['project_id' => $this->project->id,
                    'document_id' => $this->document->id, 'chunk_ids' => [$this->chunk->id]]]]);
        $this->enrollments->confirm($this->reviewer, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        return [$cohort, $payload, $manifest];
    }

    private function args(string $query): array
    {
        return ['query' => $query, 'project_id' => $this->project->id, 'source_types' => ['file_document'], 'limit' => 1, 'retrieval_mode' => 'lexical'];
    }

    private function denied(callable $operation): void
    {
        try { $operation(); self::fail('Unverified data was accepted'); }
        catch (AssistantEgressDenied) { self::assertTrue(true); }
    }

    private function sdkResponse(string $id, array $output): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => $id, 'object' => 'response', 'created_at' => 1791540000, 'status' => 'completed',
            'model' => 'openai/gpt-6-luna', 'incomplete_details' => null, 'output' => $output,
            'max_output_tokens' => 2048, 'parallel_tool_calls' => false, 'previous_response_id' => null,
            'temperature' => null, 'top_p' => null, 'tool_choice' => 'auto', 'tools' => [],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120,
                'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]],
        ], JSON_THROW_ON_ERROR));
    }
}
