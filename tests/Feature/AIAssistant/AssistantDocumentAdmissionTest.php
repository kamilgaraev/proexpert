<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDocumentsTool;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressEnrollment;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceCatalog;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssistantEgressFixtureService;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantDocumentAdmissionTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private User $operator;
    private AIAssistantDocument $document;
    private array $chunks = [];
    private OrdinaryAssistantEnrollmentService $enrollments;
    private const SENTINEL = 'APPROVED_FRAGMENT_SENTINEL';
    private const ACCOUNT = 'PRIVATE_ACCOUNT_NOT_APPROVED';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 10:00:00');
        Queue::fake();
        config(['cache.default' => 'array', 'logging.default' => 'stderr', 'ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true,
            'ai-assistant.llm.provider' => 'timeweb', 'ai-assistant.llm.timeweb.base_uri' => 'https://fixture.invalid/v1',
            'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->fixture->owner->updateQuietly(['name' => self::ACCOUNT]);
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        $this->operator = User::factory()->create(['is_active' => true]);
        UserRoleAssignment::query()->create(['user_id' => $this->operator->id, 'role_slug' => 'system_admin',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM, 'context_id' => AuthorizationContext::getSystemContext()->id,
            'assigned_by' => $this->operator->id, 'is_active' => true]);
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
        $this->document = Model::withoutEvents(fn () => AIAssistantDocument::query()->create(['organization_id' => $this->fixture->organization->id,
            'project_id' => $this->project->id, 'file_id' => $file->id, 'parent_entity_type' => 'project', 'parent_entity_id' => (string) $this->project->id,
            'storage_path' => $file->path, 'filename' => $file->name, 'mime_type' => 'text/plain', 'checksum' => hash('sha256', 'document'),
            'size_bytes' => 100, 'status' => 'ready', 'coverage_status' => 'full', 'processed_at' => now(), 'extracted_text' => 'Учебный текст', 'metadata' => []]));
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        foreach (['бетон '.self::SENTINEL, 'арматура: 17 тонн '.str_repeat('сведения ', 48).self::SENTINEL, 'КС-2 %_\\ буквальный код', 'НЕУТВЕРЖДЕННЫЙ бетон бетон бетон'] as $number => $text) {
            $unit = AIAssistantDocumentUnit::query()->create(['document_id' => $this->document->id, 'unit_type' => 'page', 'unit_index' => $number,
                'text' => $text, 'checksum' => hash('sha256', $text), 'provenance' => ['page' => $number + 1]]);
            $data = FileRagSource::chunkForUnit($this->document, $unit);
            $source = RagSource::query()->create($indexer->coverageIdentity($data) + ['title' => $data->title, 'source_version' => now()->toAtomString(),
                'metadata' => $data->metadata, 'indexed_at' => now()]);
            $this->chunks[] = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $data->organizationId, 'project_id' => $data->projectId,
                'chunk_index' => 0, 'content' => $text, 'content_hash' => hash('sha256', $text),
                'metadata' => $data->metadata + ['chunk_index' => 0, 'chunk_count' => 1], 'embedding_provider' => 'offline', 'embedding_model' => 'offline']);
        }
        $this->enrollments = app(OrdinaryAssistantEnrollmentService::class);
        $this->actingAs($this->fixture->owner);
        app(AICreditService::class)->grant($this->fixture->organization, 100000, 'purchase', null, 'document-admission-'.Str::uuid());
        DB::commit();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (DB::transactionLevel() === 0) { DB::beginTransaction(); }
        parent::tearDown();
    }

    private function args(string $query = 'бетон', int $limit = 3): array
    {
        return ['query' => $query, 'project_id' => $this->project->id, 'source_types' => ['file_document'], 'limit' => $limit, 'retrieval_mode' => 'lexical'];
    }

    private function selection(): array
    {
        return ['project_id' => $this->project->id, 'document_id' => $this->document->id, 'chunk_ids' => array_column(array_slice($this->chunks, 0, 3), 'id')];
    }

    private function enroll(string $message = 'Покажи документы и объясни учебные данные.'): array
    {
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, [OrdinaryAssistantDocumentProjection::TOOL], OrdinaryAssistantDocumentProjection::VERSION);
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $message, 'conversation_id' => null,
            'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        $manifest = $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
            [['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => $this->selection()]]);
        $this->enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        return [$cohort, $payload, $manifest];
    }

    #[DataProvider('documentRequestWordings')]
    public function test_native_loop_transmits_only_approved_subset_and_keeps_raw_fragments_out_of_durable_metadata(string $message): void
    {
        [$cohort, $payload, $manifest] = $this->enroll($message);
        $storedProof = AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id'])->sources;
        self::assertStringNotContainsString(self::SENTINEL, json_encode($storedProof, JSON_THROW_ON_ERROR));
        self::assertCount(3, $manifest['sources'][0]['projection']['data']['fragments']);
        $context = app(OrdinaryAssistantScopedContext::class);
        $approved = $manifest['sources'][0]['projection'];
        $approved['data']['fragments'] = [$approved['data']['fragments'][1]];
        $approved['source_refs'] = [$approved['source_refs'][1]];
        self::assertGreaterThan(340, mb_strpos($approved['data']['fragments'][0]['content'], self::SENTINEL));
        $calls = 0;
        $provider = $this->responses(function (array $input, array $options) use (&$calls, $approved): array {
            self::assertStringNotContainsString(self::ACCOUNT, json_encode([$input, $options], JSON_THROW_ON_ERROR));
            self::assertSame([OrdinaryAssistantDocumentProjection::TOOL], array_column($options['tools'], 'name'));
            if (++$calls === 1) { return $this->nativeCall($this->args('арматура', 1)); }
            $outputs = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            self::assertCount(1, $outputs);
            $result = json_decode($outputs[0]['output'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($approved, $result);
            self::assertStringContainsString(self::SENTINEL, $outputs[0]['output']);
            self::assertFalse($result['data']['search_complete']);
            self::assertStringNotContainsString('private/', $outputs[0]['output']);
            return $this->answer();
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, $context)
            ->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertSame(2, $calls);
        self::assertNotEmpty($response['message']['metadata']['source_refs']);
        self::assertSame(OrdinaryAssistantDocumentProjection::VERSION, $response['message']['metadata']['source_refs'][0]['egress_projection_version']);
        self::assertStringNotContainsString(self::SENTINEL, json_encode(Message::query()->findOrFail($response['message']['id'])->getAttributes(), JSON_THROW_ON_ERROR));
        $request = AssistantRequest::query()->where('request_id', $response['request_id'])->sole();
        $conversation = \App\BusinessModules\Features\AIAssistant\Models\Conversation::query()->findOrFail($response['conversation_id']);
        $copies = [$request->getAttributes(), $conversation->getAttributes(), $conversation->summary()->first()?->getAttributes(),
            \App\Models\Credits\AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->get()->toArray()];
        self::assertStringNotContainsString(self::SENTINEL, json_encode($copies, JSON_THROW_ON_ERROR));
        self::assertSame(0, \App\BusinessModules\Features\AIAssistant\Models\AssistantMemory::query()->where('organization_id', $cohort->organization_id)->count());
        self::assertFalse($context->allowsExpansion('rag'));
        self::assertFalse(config('ai-assistant.rag.expanded_context_enabled'));
        Carbon::setTestNow(now()->addHours(2));
        self::assertFalse(app(\App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard::class)
            ->fresh($this->fixture->owner, $cohort->organization_id, $response['message']['metadata']['source_refs']));
        $lifecycle = app(AssistantRequestLifecycle::class);
        self::assertSame('completed', $lifecycle->status($response['request_id'], $this->fixture->owner, $cohort->organization_id)['status']);
        self::assertFalse($lifecycle->start($this->fixture->organization, $this->fixture->owner, null, $payload)['created']);
        $this->enrollments->revoke($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id']);
        self::assertSame('completed', $lifecycle->status($response['request_id'], $this->fixture->owner, $cohort->organization_id)['status']);
        self::assertFalse($lifecycle->start($this->fixture->organization, $this->fixture->owner, null, $payload)['created']);
        File::query()->whereKey($this->document->file_id)->update(['deleted_at' => now()]);
        $this->expectException(AuthorizationException::class);
        $lifecycle->status($response['request_id'], $this->fixture->owner, $cohort->organization_id);
    }

    public static function documentRequestWordings(): iterable
    {
        yield 'ordinary wording' => ['Покажи документы и объясни учебные данные.'];
        yield 'metadata wording' => ['Какие документы с готовым текстом доступны? Покажи имена и идентификаторы.'];
    }

    public function test_actual_sdk_error_echo_cannot_copy_an_admitted_fragment_to_failure_stores_or_logs(): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $fragment = $manifest['sources'][0]['projection']['data']['fragments'][1]['content'];
        self::assertGreaterThan(340, mb_strpos($fragment, self::SENTINEL));
        $native = $this->nativeCall($this->args('арматура', 1));
        $success = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'resp_document_first', 'object' => 'response', 'created_at' => 1791540000, 'status' => 'completed',
            'model' => 'openai/gpt-6-luna', 'incomplete_details' => null, 'output' => $native['output'],
            'max_output_tokens' => 2048, 'parallel_tool_calls' => false, 'previous_response_id' => null,
            'temperature' => null, 'top_p' => null, 'tool_choice' => 'auto', 'tools' => [],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120,
                'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]],
        ], JSON_THROW_ON_ERROR));
        $failure = new \GuzzleHttp\Psr7\Response(400, ['Content-Type' => 'application/json'], json_encode([
            'error' => ['message' => $fragment, 'type' => 'invalid_request_error'],
            'usage' => ['input_tokens' => 71, 'output_tokens' => 9], 'model' => 'openai/gpt-6-luna',
        ], JSON_THROW_ON_ERROR));
        $history = $logs = [];
        $logging = $this->createMock(\App\Services\Logging\LoggingService::class);
        $logging->method('technical')->willReturnCallback(static function (string $event, array $context = []) use (&$logs): void { $logs[] = [$event, $context]; });
        \Illuminate\Support\Facades\Log::listen(static function (\Illuminate\Log\Events\MessageLogged $event) use (&$logs): void { $logs[] = [$event->message, $event->context]; });
        $context = app(OrdinaryAssistantScopedContext::class);
        $egress = new \App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress($context);
        $provider = (new \App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantProviderFactory($egress, $logging))
            ->make(\GuzzleHttp\Middleware::history($history)(new \GuzzleHttp\Handler\MockHandler([$success, $failure])));
        $service = app()->makeWith(\App\BusinessModules\Features\AIAssistant\Services\AIAssistantService::class,
            ['llmProvider' => $provider, 'ordinaryEgress' => $egress, 'scopedContext' => $context, 'logging' => $logging]);
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                $service->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
                self::fail('Actual SDK error did not fail the request');
            } catch (\Throwable $escaped) {
                self::assertCount(2, $history, 'Expected native read followed by actual HTTP400 without retry');
                self::assertSame('assistant_provider_failed', $escaped->getMessage());
                self::assertNull($escaped->getPrevious());
                self::assertStringNotContainsString(self::SENTINEL, (string) $escaped);
                self::assertStringNotContainsString(self::SENTINEL, json_encode($escaped->getTrace(), JSON_THROW_ON_ERROR));
            }
        } finally { ini_set('zend.exception_ignore_args', $previous); }
        $wire = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $outputs = array_values(array_filter($wire['input'], static fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'));
        self::assertCount(1, $outputs);
        self::assertStringContainsString(self::SENTINEL, $outputs[0]['output']);
        $request = AssistantRequest::query()->where('request_id', $payload['request_id'])->sole();
        self::assertSame('failed', $request->status);
        $copies = [$logs, $request->getAttributes(),
            \App\Models\Credits\AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->get()->toArray(),
            Message::query()->where('conversation_id', $request->conversation_id)->get()->toArray(),
            \App\BusinessModules\Features\AIAssistant\Models\ConversationSummary::query()->where('conversation_id', $request->conversation_id)->get()->toArray()];
        self::assertStringNotContainsString(self::SENTINEL, print_r($logs, true));
        self::assertStringNotContainsString(self::SENTINEL, json_encode($copies, JSON_THROW_ON_ERROR));
    }

    private function replaceFragment(int $index, string $text, ?string $title = null): void
    {
        config(['ai-assistant.rag.chunk_chars' => 6000]);
        $unit = AIAssistantDocumentUnit::query()->findOrFail($this->chunks[$index]->metadata['unit_id']);
        $unit->updateQuietly(['text' => $text, 'checksum' => hash('sha256', $text)]);
        if ($title !== null) { $this->document->updateQuietly(['filename' => $title]); }
        $indexer = new RagIndexer(app(RagEmbeddingProviderInterface::class), new RagSourceRegistry([]));
        foreach ($this->chunks as $chunk) {
            $currentUnit = AIAssistantDocumentUnit::query()->findOrFail($chunk->metadata['unit_id']);
            $data = FileRagSource::chunkForUnit($this->document, $currentUnit);
            RagSource::query()->whereKey($chunk->source_id)->firstOrFail()->updateQuietly(['title' => $data->title,
                'checksum' => $indexer->coverageIdentity($data)['checksum'], 'metadata' => $data->metadata]);
            $chunk->updateQuietly(['content' => $data->content, 'content_hash' => hash('sha256', $data->content),
                'metadata' => $data->metadata + ['chunk_index' => 0, 'chunk_count' => 1]]);
        }
    }

    public function test_scoped_ranking_prefers_direct_query_terms_and_keeps_expansion_fallback(): void
    {
        $this->replaceFragment(0, 'оплата '.self::SENTINEL);
        $this->replaceFragment(1, 'сумма договор контракт бюджет остаток счет акт');
        $this->replaceFragment(2, 'платеж');
        [$cohort, $payload, $manifest] = $this->enroll();
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        $tool = app(SearchAssistantDocumentsTool::class);
        $first = $tool->execute($this->args('оплата', 1), $this->fixture->owner, $this->fixture->organization);
        self::assertSame([$manifest['sources'][0]['projection']['data']['fragments'][0]], $first['data']['fragments']);
        $both = $tool->execute($this->args('оплата', 2), $this->fixture->owner, $this->fixture->organization);
        self::assertSame([$this->chunks[0]->id, $this->chunks[1]->id], array_column($both['data']['fragments'], 'chunk_id'));
        $fallback = $tool->execute($this->args('финансы', 1), $this->fixture->owner, $this->fixture->organization);
        self::assertSame([$this->chunks[1]->id], array_column($fallback['data']['fragments'], 'chunk_id'));
        self::assertSame('approved_fragment_universe', $first['data']['coverage_scope']);
        self::assertFalse($first['data']['search_complete']);
        self::assertSame(3, $first['data']['approved_fragments']);
    }

    public function test_direct_query_rank_reaches_actual_wire_as_an_unchanged_approved_subset(): void
    {
        $this->replaceFragment(0, 'оплата '.str_repeat('сведения ', 45).self::SENTINEL);
        $this->replaceFragment(1, 'сумма договор контракт бюджет остаток счет акт');
        $this->replaceFragment(2, 'платеж');
        [$cohort, $payload, $manifest] = $this->enroll();
        $approved = $manifest['sources'][0]['projection'];
        $approved['data']['fragments'] = [$approved['data']['fragments'][0]];
        $approved['source_refs'] = [$approved['source_refs'][0]];
        self::assertGreaterThan(340, mb_strpos($approved['data']['fragments'][0]['content'], self::SENTINEL));
        $response = static fn (array $output): \GuzzleHttp\Psr7\Response => new \GuzzleHttp\Psr7\Response(200,
            ['Content-Type' => 'application/json'], json_encode([
                'id' => 'resp_ranked_'.$output[0]['type'], 'object' => 'response', 'created_at' => 1791540000, 'status' => 'completed',
                'model' => 'openai/gpt-6-luna', 'incomplete_details' => null, 'output' => $output,
                'max_output_tokens' => 2048, 'parallel_tool_calls' => false, 'previous_response_id' => null,
                'temperature' => null, 'top_p' => null, 'tool_choice' => 'auto', 'tools' => [],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120,
                    'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]],
            ], JSON_THROW_ON_ERROR));
        $history = [];
        $logging = $this->createMock(\App\Services\Logging\LoggingService::class);
        $context = app(OrdinaryAssistantScopedContext::class);
        $egress = new \App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress($context);
        $provider = (new \App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantProviderFactory($egress, $logging))
            ->make(\GuzzleHttp\Middleware::history($history)(new \GuzzleHttp\Handler\MockHandler([
                $response($this->nativeCall($this->args('оплата', 1))['output']),
                $response([['id' => 'msg_ranked', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => 'В учебном фрагменте указана оплата.', 'annotations' => [], 'logprobs' => []]]]]),
            ])));
        $service = app()->makeWith(\App\BusinessModules\Features\AIAssistant\Services\AIAssistantService::class,
            ['llmProvider' => $provider, 'ordinaryEgress' => $egress, 'scopedContext' => $context, 'logging' => $logging]);
        $result = $service->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertCount(2, $history);
        $wire = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $outputs = array_values(array_filter($wire['input'], static fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'));
        self::assertCount(1, $outputs);
        self::assertSame($approved, json_decode($outputs[0]['output'], true, 512, JSON_THROW_ON_ERROR));
        $request = AssistantRequest::query()->where('request_id', $result['request_id'])->sole();
        $conversation = \App\BusinessModules\Features\AIAssistant\Models\Conversation::query()->findOrFail($result['conversation_id']);
        $copies = [$request->getAttributes(), $conversation->getAttributes(), $conversation->summary()->first()?->getAttributes(),
            Message::query()->findOrFail($result['message']['id'])->getAttributes(),
            \App\Models\Credits\AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->get()->toArray()];
        self::assertStringNotContainsString(self::SENTINEL, json_encode($copies, JSON_THROW_ON_ERROR));
    }

    public function test_unapproved_content_tail_is_not_a_scoped_sql_hit(): void
    {
        $this->replaceFragment(1, str_repeat('X', 2500).' hiddentail');
        [$cohort, $payload, $manifest] = $this->enroll();
        self::assertStringNotContainsString('hiddentail', json_encode($manifest['sources'][0]['projection'], JSON_THROW_ON_ERROR));
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        $result = app(SearchAssistantDocumentsTool::class)->execute($this->args('hiddentail'), $this->fixture->owner, $this->fixture->organization);
        self::assertSame([], $result['data']['fragments']);
    }

    public function test_unapproved_title_tail_is_not_a_scoped_sql_hit(): void
    {
        $this->replaceFragment(1, 'арматура', str_repeat('X', 205).' hiddentitle');
        [$cohort, $payload, $manifest] = $this->enroll();
        self::assertStringNotContainsString('hiddentitle', json_encode($manifest['sources'][0]['projection'], JSON_THROW_ON_ERROR));
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        $result = app(SearchAssistantDocumentsTool::class)->execute($this->args('hiddentitle'), $this->fixture->owner, $this->fixture->organization);
        self::assertSame([], $result['data']['fragments']);
    }

    public function test_unapproved_tail_cannot_promote_a_scoped_sql_rank(): void
    {
        $this->replaceFragment(1, 'бетон '.str_repeat('X', 2394).' hiddentail');
        [$cohort, $payload, $manifest] = $this->enroll();
        self::assertStringNotContainsString('hiddentail', json_encode($manifest['sources'][0]['projection'], JSON_THROW_ON_ERROR));
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        $queries = [];
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $event) use (&$queries): void { $queries[] = $event->sql; });
        $result = app(SearchAssistantDocumentsTool::class)->execute($this->args('бетон hiddentail', 1), $this->fixture->owner, $this->fixture->organization);
        self::assertNotEmpty(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'left(candidate_chunks.content, 2400)') && str_contains($sql, 'left(ai_rag_sources.title, 200)')));
        self::assertNotEmpty(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'left(c.content, 2400)') && str_contains($sql, 'left(s.title, 200)')));
        self::assertSame([$this->chunks[0]->id], array_column($result['data']['fragments'], 'chunk_id'));
    }

    public function test_projection_reports_the_2400_boundary_without_egressing_the_tail(): void
    {
        $this->replaceFragment(1, 'арматура '.str_repeat('X', 2500).' TAIL_SENTINEL');
        [$cohort, $payload, $manifest] = $this->enroll();
        $fragment = $manifest['sources'][0]['projection']['data']['fragments'][1];
        self::assertSame(2400, mb_strlen($fragment['content']));
        self::assertSame(['scope' => 'rag_chunk', 'offset' => 0, 'length' => 2400,
            'total_length' => mb_strlen($this->chunks[1]->content), 'truncated' => true], $fragment['content_range']);
        self::assertStringNotContainsString('TAIL_SENTINEL', json_encode($manifest, JSON_THROW_ON_ERROR));
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        $result = app(SearchAssistantDocumentsTool::class)->execute($this->args('арматура', 1), $this->fixture->owner, $this->fixture->organization);
        self::assertSame([$fragment], $result['data']['fragments']);
    }

    public function test_changed_approved_source_prevents_the_first_actual_wire_send(): void
    {
        [$cohort, $payload] = $this->enroll();
        AIAssistantDocumentUnit::query()->whereKey($this->chunks[0]->metadata['unit_id'])->update(['text' => 'changed before send']);
        $provider = $this->responses(static function (): never { self::fail('Stale document reached actual transport'); });
        $this->expectException(AssistantEgressDenied::class);
        AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, app(OrdinaryAssistantScopedContext::class))
            ->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
    }

    public function test_approved_scope_is_applied_before_ranking_and_ignores_unrelated_pending_documents(): void
    {
        [$cohort, $payload] = $this->enroll();
        $file = File::query()->findOrFail($this->document->file_id)->replicate();
        $file->path .= '.pending'; $file->saveQuietly();
        $pending = $this->document->replicate();
        $pending->forceFill(['file_id' => $file->id, 'storage_path' => $file->path, 'status' => 'processing'])->saveQuietly();
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        $result = app(SearchAssistantDocumentsTool::class)->execute($this->args('бетон НЕУТВЕРЖДЕННЫЙ', 1), $this->fixture->owner, $this->fixture->organization);
        self::assertSame([$this->chunks[0]->id], array_column($result['data']['fragments'], 'chunk_id'));
        self::assertSame('approved_fragment_universe', $result['data']['coverage_scope']);
        self::assertSame(3, $result['data']['approved_fragments']);
        $literal = app(SearchAssistantDocumentsTool::class)->execute($this->args('КС-2 %_\\', 3), $this->fixture->owner, $this->fixture->organization);
        self::assertSame([$this->chunks[2]->id], array_column($literal['data']['fragments'], 'chunk_id'));
        $this->document->updateQuietly(['status' => 'processing']);
        $this->expectException(AssistantEgressDenied::class);
        app(SearchAssistantDocumentsTool::class)->execute($this->args(), $this->fixture->owner, $this->fixture->organization);
    }

    #[DataProvider('sourceChanges')]
    public function test_fullset_changes_close_send_or_publication_even_when_query_does_not_select_the_changed_fragment(string $change): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        $result = app(SearchAssistantDocumentsTool::class)->execute($this->args('арматура', 1), $this->fixture->owner, $this->fixture->organization);
        $context->projectToolResult(OrdinaryAssistantDocumentProjection::TOOL, $this->args('арматура', 1), $result);
        match ($change) {
            'unit' => AIAssistantDocumentUnit::query()->whereKey($this->chunks[0]->metadata['unit_id'])->update(['text' => 'Подмененный текст']),
            'chunk' => $this->chunks[0]->updateQuietly(['content' => 'Подмененный chunk']),
            'document' => $this->document->updateQuietly(['checksum' => hash('sha256', 'changed')]),
            'file ACL' => File::query()->whereKey($this->document->file_id)->update(['deleted_at' => now()]),
            'revoke' => $this->enrollments->revoke($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id']),
            'generation' => $cohort->update(['generation' => (string) Str::uuid()]),
        };
        $this->expectException(AssistantEgressDenied::class);
        $context->assertPublication($result['source_refs']);
    }

    public static function sourceChanges(): iterable
    {
        foreach (['unit', 'chunk', 'document', 'file ACL', 'revoke', 'generation'] as $case) { yield $case => [$case]; }
    }

    public function test_forged_bytes_metadata_and_foreign_corpus_are_denied(): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        $result = app(SearchAssistantDocumentsTool::class)->execute($this->args(), $this->fixture->owner, $this->fixture->organization);
        foreach (['bytes', 'metadata', 'reference'] as $mutation) {
            $forged = $result;
            match ($mutation) {
                'bytes' => $forged['data']['fragments'][0]['content'] = 'forged',
                'metadata' => $forged['data']['new_metadata'] = 'forged',
                'reference' => $forged['source_refs'][0]['organization_id'] = $this->fixture->foreignOrganization->id,
            };
            try { $context->projectToolResult(OrdinaryAssistantDocumentProjection::TOOL, $this->args(), $forged); self::fail('Forged output admitted'); }
            catch (AssistantEgressDenied) { self::assertTrue(true); }
        }
        foreach ([null, $this->project->id + 999] as $projectId) {
            try {
                app(OrdinaryAssistantSourceCatalog::class)->capture($this->fixture->owner, $cohort->organization_id, $this->project->id,
                    OrdinaryAssistantDocumentProjection::TOOL, array_replace($this->selection(), ['project_id' => $projectId]));
                self::fail('Foreign corpus admitted');
            } catch (AssistantEgressDenied) { self::assertTrue(true); }
        }
        $this->expectException(AssistantEgressDenied::class);
        $context->assertTool(OrdinaryAssistantDocumentProjection::TOOL, $this->args() + ['cursor' => 'forged'], $this->fixture->owner, $cohort->organization_id);
    }

    public function test_platform_api_requires_explicit_document_policy_and_owner_cannot_assign_it(): void
    {
        $this->withoutMiddleware();
        $path = '/api/v1/admin/ai-assistant/egress/cohorts';
        $target = ['organization_id' => $this->fixture->organization->id, 'actor_id' => $this->fixture->owner->id,
            'project_id' => $this->project->id, 'tools' => [OrdinaryAssistantDocumentProjection::TOOL]];
        $this->actingAs($this->fixture->owner)->postJson($path, $target + ['policy_version' => OrdinaryAssistantDocumentProjection::VERSION])->assertForbidden();
        $this->actingAs($this->operator)->postJson($path, $target)->assertForbidden();
        $this->postJson($path, $target + ['policy_version' => 'client-grant'])->assertUnprocessable();
        $this->postJson($path, $target + ['policy_version' => OrdinaryAssistantDocumentProjection::VERSION])
            ->assertCreated()->assertJsonPath('data.policy_version', OrdinaryAssistantDocumentProjection::VERSION);
    }

    public function test_existing_sql_policy_keeps_exact_model_arguments_and_no_document_capability(): void
    {
        $args = ['project_id' => $this->project->id, 'focus' => 'schedule', 'limit' => 3];
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, ['get_project_attention']);
        self::assertSame(OrdinaryAssistantSourceProjection::VERSION, $cohort->policy_version);
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'Учебный вопрос по графику.', 'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        $manifest = $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
            [['tool' => 'get_project_attention', 'arguments' => $args]]);
        $this->enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        self::assertFalse($context->allowsExpansion('document_rag'));
        $context->assertTool('get_project_attention', $args, $this->fixture->owner, $cohort->organization_id);
        $this->expectException(AssistantEgressDenied::class);
        $context->assertTool('get_project_attention', array_replace($args, ['limit' => 2]), $this->fixture->owner, $cohort->organization_id);
    }

    public function test_guarded_sql_model_arguments_survive_location_wording_without_semantic_defaults(): void
    {
        $tool = 'get_project_production_schedule_summary';
        $query = 'Сверь выполненные работы с графиком с первого по пятый этаж';
        $args = ['project_id' => $this->project->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'];
        $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => $this->project->id]]]]];
        self::assertSame($tool, \App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead::fromPlan($plan)['name']);
        self::assertSame('location', \App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead::bindArguments($plan, $tool, $args)['scope_clarification']);
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner, $this->project->id, [$tool]);
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $query, 'profile' => 'normal', 'allow_actions' => false,
            'context' => $plan['request']['context']];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        $manifest = $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
            [['tool' => $tool, 'arguments' => $args]]);
        $this->enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        $calls = 0;
        $provider = $this->responses(function (array $input) use (&$calls, $args, $tool, $manifest): array {
            if (++$calls === 1) {
                $native = $this->nativeCall($args);
                $native['output'][0]['name'] = $native['function_calls'][0]['name'] = $tool;
                return $native;
            }
            $outputs = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'));
            self::assertCount(1, $outputs);
            self::assertSame($manifest['sources'][0]['projection'], json_decode($outputs[0]['output'], true, 512, JSON_THROW_ON_ERROR));
            return array_replace($this->answer(), ['content' => 'Проверен учебный график проекта.']);
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($query, $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertSame(2, $calls);
        self::assertSame('completed', $response['status']);
    }

    public static function clarifications(): iterable
    {
        yield 'before read' => [false, false];
        yield 'after read' => [true, false];
        yield 'stale before publication' => [true, true];
    }

    #[DataProvider('clarifications')]
    public function test_document_clarification_keeps_empty_final_refs_and_fresh_publication(bool $read, bool $stale): void
    {
        [$cohort, $payload] = $this->enroll();
        $calls = 0;
        $provider = $this->responses(function (array $input) use (&$calls, $read, $stale): array {
            self::assertStringContainsString('"kind":"clarification"', $input[0]['content']);
            if (++$calls === 1 && $read) { return $this->nativeCall($this->args()); }
            if ($stale) { AIAssistantDocumentUnit::query()->whereKey($this->chunks[1]->metadata['unit_id'])->update(['text' => 'changed before publication']); }
            return array_replace($this->answer(), ['content' => '{"kind":"clarification","missing":"goal"}']);
        });
        try {
            $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, app(OrdinaryAssistantScopedContext::class))
                ->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
            self::assertFalse($stale, 'Clarification skipped publication freshness');
            self::assertSame($read ? 2 : 1, $calls);
            self::assertSame(trans_message('ai_assistant.clarification_goal'), $response['message']['content']);
            self::assertSame([], $response['message']['metadata']['source_refs']);
            self::assertTrue($response['message']['metadata']['needs_clarification']);
            $message = Message::query()->findOrFail($response['message']['id']);
            self::assertSame([], $message->metadata['source_refs']);
            self::assertStringNotContainsString(self::SENTINEL, json_encode($message->getAttributes(), JSON_THROW_ON_ERROR));
            self::assertSame([], AssistantRequest::query()->where('request_id', $response['request_id'])->sole()->response['message']['metadata']['source_refs']);
        } catch (AssistantEgressDenied) {
            self::assertTrue($stale, 'Fresh clarification was denied');
            self::assertSame(0, Message::query()->where('role', 'assistant')->whereIn('conversation_id',
                \App\BusinessModules\Features\AIAssistant\Models\Conversation::query()->where('organization_id', $cohort->organization_id)->select('id'))->count());
        }
    }

    public function test_default_sql_policy_cannot_assign_the_document_tool(): void
    {
        $this->expectException(AssistantEgressDenied::class);
        $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, [OrdinaryAssistantDocumentProjection::TOOL]);
    }

    public function test_owner_preview_requires_exact_current_document_and_chunk_scope(): void
    {
        $capture = fn () => app(OrdinaryAssistantSourceCatalog::class)->capture($this->fixture->owner, $this->fixture->organization->id,
            $this->project->id, OrdinaryAssistantDocumentProjection::TOOL, $this->selection());
        self::assertCount(3, $capture()['preview']['data']['fragments']);
        $otherProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        foreach ([['project_id' => null], ['project_id' => $otherProject->id], ['organization_id' => $this->fixture->foreignOrganization->id],
            ['status' => 'processing'], ['metadata' => ['partial' => true]]] as $changes) {
            $original = [];
            foreach (array_keys($changes) as $key) { $original[$key] = $this->document->getAttribute($key); }
            $this->document->updateQuietly($changes);
            try { $capture(); self::fail('Unavailable document entered owner preview'); }
            catch (AssistantEgressDenied) { self::assertTrue(true); }
            finally { $this->document->forceFill($original)->saveQuietly(); }
        }
        $this->chunks[0]->updateQuietly(['project_id' => null]);
        $this->expectException(AssistantEgressDenied::class);
        $capture();
    }

    #[DataProvider('storedSourceChanges')]
    public function test_local_replay_keeps_existing_expiry_policy_but_checks_current_document_source(string $change): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        $result = app(SearchAssistantDocumentsTool::class)->execute($this->args(), $this->fixture->owner, $this->fixture->organization);
        $capturedAt = now();
        Carbon::setTestNow($capturedAt->copy()->addMinutes(4)->addSeconds(59));
        self::assertTrue(app(\App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard::class)
            ->fresh($this->fixture->owner, $cohort->organization_id, $result['source_refs']));
        Carbon::setTestNow($capturedAt->copy()->addMinutes(5)->addSecond());
        self::assertFalse(app(\App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard::class)
            ->fresh($this->fixture->owner, $cohort->organization_id, $result['source_refs']));
        $this->enrollments->revoke($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id']);
        $cohort->update(['expires_at' => now()->subMinute()]);
        AssistantEgressEnrollment::query()->whereKey($manifest['enrollment_id'])->update(['expires_at' => now()->subMinute()]);
        Carbon::setTestNow($capturedAt->copy()->addHours(2));
        $method = new \ReflectionMethod(AssistantRequestLifecycle::class, 'assertResponseReferences');
        $method->invoke(app(AssistantRequestLifecycle::class), $result['source_refs'], $this->fixture->owner, $cohort->organization_id, null, null, true);
        self::assertTrue(true);
        match ($change) {
            'raw unit' => AIAssistantDocumentUnit::query()->whereKey($this->chunks[0]->metadata['unit_id'])->update(['text' => 'stale']),
            'source checksum' => RagSource::query()->whereKey($this->chunks[0]->source_id)->update(['checksum' => hash('sha256', 'changed')]),
            'source identity' => RagSource::query()->whereKey($this->chunks[0]->source_id)->update(['entity_id' => '999999999']),
            'current ACL' => File::query()->whereKey($this->document->file_id)->update(['deleted_at' => now()]),
            'future timestamp' => $result['source_refs'][0]['fetched_at'] = now()->addHour()->toISOString(),
            'invalid timestamp' => $result['source_refs'][0]['fetched_at'] = 'invalid timestamp',
        };
        $this->expectException(AuthorizationException::class);
        $method->invoke(app(AssistantRequestLifecycle::class), $result['source_refs'], $this->fixture->owner, $cohort->organization_id, null, null, true);
    }

    public static function storedSourceChanges(): iterable
    {
        foreach (['raw unit', 'source checksum', 'source identity', 'current ACL', 'future timestamp', 'invalid timestamp'] as $change) {
            yield $change => [$change];
        }
    }

    public function test_delayed_completion_keeps_transient_reference_ttl_with_an_active_request_lease(): void
    {
        [$cohort, $payload] = $this->enroll();
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        $result = app(SearchAssistantDocumentsTool::class)->execute($this->args(), $this->fixture->owner, $this->fixture->organization);
        $lifecycle = app(AssistantRequestLifecycle::class);
        $request = $lifecycle->start($this->fixture->organization, $this->fixture->owner, null, $payload)['request'];
        Carbon::setTestNow(now()->addHours(2));
        $request->updateQuietly(['lease_expires_at' => now()->addMinute()]);
        self::assertTrue(app(\App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard::class)
            ->storedDocumentCurrent($this->fixture->owner, $cohort->organization_id, $result['source_refs']));
        try {
            $lifecycle->complete($request, $this->fixture->owner, ['message' => ['metadata' => ['source_refs' => $result['source_refs']]]], false);
            self::fail('Delayed completion published expired transient references');
        } catch (AuthorizationException $denied) {
            self::assertSame(trans_message('ai_assistant.sources_access_revoked'), $denied->getMessage());
        }
        self::assertSame('running', $request->refresh()->status);
    }

    private function responses(callable $callback): DocumentAdmissionResponsesProvider
    {
        $provider = $this->createMock(DocumentAdmissionResponsesProvider::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->method('responses')->willReturnCallback($callback);
        return $provider;
    }

    private function nativeCall(array $arguments): array
    {
        $call = ['type' => 'function_call', 'id' => 'fc_document', 'call_id' => 'call_document', 'status' => 'completed',
            'name' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)];
        return ['content' => '', 'output' => [$call], 'function_calls' => [$call], 'input_tokens' => 100, 'output_tokens' => 20,
            'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
    }

    private function answer(): array
    {
        return ['content' => 'В учебном документе указано 17 тонн арматуры.', 'output' => [], 'function_calls' => [],
            'input_tokens' => 100, 'output_tokens' => 20, 'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
    }
}

interface DocumentAdmissionResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
