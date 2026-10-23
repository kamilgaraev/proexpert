<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressEnrollment;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssistantEgressFixtureService;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantDocumentFollowupSearchTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private User $operator;
    private AIAssistantDocument $document;
    private array $chunks = [];
    private OrdinaryAssistantEnrollmentService $enrollments;
    private const SENTINEL = 'APPROVED_FRAGMENT_SENTINEL';
    private const ACCOUNT = 'PRIVATE_ACCOUNT_NOT_APPROVED';
    private const EMPTY_QUERY = 'алюмотермия';
    private const HIT_QUERY = 'арматура';
    private const ANSWER = 'Утверждённый фрагмент описывает арматуру.';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 10:00:00');
        Queue::fake();
        config(['cache.default' => 'array', 'logging.default' => 'stderr', 'ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true,
            'ai-assistant-credits.profiles.short' => ['input_tokens' => 12288, 'output_tokens' => 1024, 'max_calls' => 3],
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

    public function test_model_can_rephrase_an_empty_search_and_answer_from_the_second_approved_read(): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $calls = 0;
        $queries = [self::EMPTY_QUERY, self::HIT_QUERY];
        $provider = $this->responses(function (array $input, array $options) use (&$calls, $queries, $manifest): array {
            self::assertSame('auto', $options['tool_choice']);
            self::assertSame(3, $options['budget_limits']['max_calls']);
            self::assertSame([OrdinaryAssistantDocumentProjection::TOOL], array_column($options['tools'], 'name'));
            $this->assertTranscript($input, $calls, $queries, $manifest);
            self::assertLessThan(3, $calls++);
            return $calls < 3 ? $this->nativeCall($this->args($queries[$calls - 1]), $calls) : $this->answer();
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertSame(3, $calls);
        self::assertSame(self::ANSWER, $response['message']['content']);
        self::assertFalse($response['message']['metadata']['needs_clarification']);
        $this->assertApprovedReference($response['message']['metadata']['source_refs'], $manifest);
        $this->assertUsage($response['request_id'], 3);
        self::assertStringNotContainsString(self::SENTINEL, json_encode(Message::query()->findOrFail($response['message']['id'])->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public static function readChoices(): iterable
    {
        yield 'none' => [false];
        yield 'one' => [true];
    }

    #[DataProvider('readChoices')]
    public function test_model_can_still_finish_without_a_search_or_after_one_search(bool $read): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $calls = 0;
        $provider = $this->responses(function (array $input, array $options) use (&$calls, $read, $manifest): array {
            self::assertSame('auto', $options['tool_choice']);
            $this->assertTranscript($input, $calls, [self::HIT_QUERY], $manifest);
            if (++$calls === 1 && $read) { return $this->nativeCall($this->args(self::HIT_QUERY), 1); }
            return $this->answer($read ? self::ANSWER : '{"kind":"clarification","missing":"goal"}');
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertSame($read ? 2 : 1, $calls);
        self::assertSame($read ? self::ANSWER : trans_message('ai_assistant.clarification_goal'), $response['message']['content']);
        self::assertSame(! $read, $response['message']['metadata']['needs_clarification']);
        if ($read) { $this->assertApprovedReference($response['message']['metadata']['source_refs'], $manifest); }
        else { self::assertSame([], $response['message']['metadata']['source_refs']); }
        $this->assertUsage($response['request_id'], $calls);
    }

    public static function stalePhases(): iterable
    {
        yield 'before next send' => [1];
        yield 'before publication' => [3];
    }

    #[DataProvider('stalePhases')]
    public function test_an_unused_approved_fragment_change_blocks_the_next_send_or_publication(int $phase): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $calls = 0;
        $provider = $this->responses(function (array $input) use (&$calls, $phase, $manifest): array {
            $this->assertTranscript($input, $calls, [self::EMPTY_QUERY, self::HIT_QUERY], $manifest);
            $calls++;
            if ($calls === $phase) {
                AIAssistantDocumentUnit::query()->whereKey($this->chunks[0]->metadata['unit_id'])->update(['text' => 'Изменённый неиспользованный утверждённый фрагмент']);
            }
            self::assertLessThanOrEqual($phase, $calls);
            return $calls < 3 ? $this->nativeCall($this->args($calls === 1 ? self::EMPTY_QUERY : self::HIT_QUERY), $calls) : $this->answer();
        });
        try {
            AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
                app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
            self::fail('A stale approved corpus was published');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('assistant_egress_denied', $denied->getMessage());
            self::assertSame($phase, $calls);
            self::assertFalse(Message::query()->where('role', 'assistant')->whereIn('conversation_id',
                \App\BusinessModules\Features\AIAssistant\Models\Conversation::query()->where('organization_id', $cohort->organization_id)->select('id'))->exists());
        }
    }

    public function test_two_quoted_calls_cannot_hide_a_third_call_and_preserve_occurred_usage(): void
    {
        config(['ai-assistant-credits.profiles.short' => ['input_tokens' => 12288, 'output_tokens' => 1024, 'max_calls' => 2]]);
        [$cohort, $payload, $manifest] = $this->enroll();
        $calls = 0;
        $queries = [self::EMPTY_QUERY, self::HIT_QUERY];
        $provider = $this->responses(function (array $input, array $options) use (&$calls, $queries, $manifest): array {
            self::assertLessThan(2, $calls);
            self::assertSame(2, $options['budget_limits']['max_calls']);
            $this->assertTranscript($input, $calls, $queries, $manifest);
            $calls++;
            return $this->nativeCall($this->args($queries[$calls - 1]), $calls);
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertSame(2, $calls);
        $this->assertUsage($response['request_id'], 2);
        self::assertSame(2, AssistantRequest::query()->where('request_id', $response['request_id'])->sole()->max_calls);
        self::assertTrue($response['message']['metadata']['needs_clarification']);
        self::assertNotSame(self::ANSWER, $response['message']['content']);
        self::assertSame([], $response['message']['metadata']['source_refs']);
    }

    private function assertTranscript(array $input, int $count, array $queries, array $manifest): void
    {
        self::assertStringNotContainsString(self::ACCOUNT, json_encode($input, JSON_THROW_ON_ERROR));
        $calls = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call'));
        $outputs = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
        self::assertCount($count, $calls);
        self::assertCount($count, $outputs);
        self::assertCount($count, array_unique(array_column($calls, 'id')));
        self::assertCount($count, array_unique(array_column($calls, 'call_id')));
        foreach ($outputs as $index => $output) {
            self::assertSame('fc_followup_'.($index + 1), $calls[$index]['id']);
            self::assertSame('call_followup_'.($index + 1), $calls[$index]['call_id']);
            self::assertSame($calls[$index]['call_id'], $output['call_id']);
            self::assertSame(OrdinaryAssistantDocumentProjection::TOOL, $calls[$index]['name']);
            self::assertSame($this->args($queries[$index]), json_decode($calls[$index]['arguments'], true, 512, JSON_THROW_ON_ERROR));
            $expected = $manifest['sources'][0]['projection'];
            $expected['data']['fragments'] = $queries[$index] === self::EMPTY_QUERY ? [] : [$expected['data']['fragments'][1]];
            $expected['source_refs'] = $queries[$index] === self::EMPTY_QUERY ? [] : [$expected['source_refs'][1]];
            self::assertSame($expected, json_decode($output['output'], true, 512, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('НЕУТВЕРЖДЕННЫЙ', $output['output']);
        }
    }

    private function assertApprovedReference(array $references, array $manifest): void
    {
        self::assertCount(1, $references);
        $expectedIdentity = $manifest['sources'][0]['projection']['source_refs'][1];
        self::assertSame($expectedIdentity, array_intersect_key($references[0], $expectedIdentity));
        self::assertSame(OrdinaryAssistantDocumentProjection::VERSION, $references[0]['egress_projection_version']);
        $proof = AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id'])->sources[0];
        self::assertSame($proof['source_versions'][1], OrdinaryAssistantDocumentProjection::referenceHash($references[0]));
    }

    private function assertUsage(string $requestId, int $calls): void
    {
        $request = AssistantRequest::query()->where('request_id', $requestId)->sole();
        self::assertSame($calls, $request->calls_used);
        $usage = \App\Models\Credits\AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->get();
        self::assertCount($calls, $usage);
        self::assertSame($calls * 100, (int) $usage->sum(static fn ($row): int => (int) $row->metadata['input_tokens']));
        self::assertSame($calls * 20, (int) $usage->sum(static fn ($row): int => (int) $row->metadata['output_tokens']));
    }

    private function args(string $query): array
    {
        return ['query' => $query, 'project_id' => $this->project->id, 'source_types' => ['file_document'], 'limit' => 1, 'retrieval_mode' => 'lexical'];
    }

    private function enroll(): array
    {
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, [OrdinaryAssistantDocumentProjection::TOOL], OrdinaryAssistantDocumentProjection::VERSION);
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'Найди в утверждённом тексте ответ и при необходимости уточни поиск.',
            'conversation_id' => null, 'profile' => 'short', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        $manifest = $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
            [['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => ['project_id' => $this->project->id,
                'document_id' => $this->document->id, 'chunk_ids' => array_column(array_slice($this->chunks, 0, 3), 'id')]]]);
        $this->enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        return [$cohort, $payload, $manifest];
    }

    private function responses(callable $callback): DocumentFollowupResponsesProvider
    {
        $provider = $this->createMock(DocumentFollowupResponsesProvider::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->method('responses')->willReturnCallback($callback);
        return $provider;
    }

    private function nativeCall(array $arguments, int $index): array
    {
        $call = ['type' => 'function_call', 'id' => 'fc_followup_'.$index, 'call_id' => 'call_followup_'.$index, 'status' => 'completed',
            'name' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)];
        return ['content' => '', 'output' => [$call], 'function_calls' => [$call], 'input_tokens' => 100, 'output_tokens' => 20,
            'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
    }

    private function answer(string $content = self::ANSWER): array
    {
        return ['content' => $content, 'output' => [], 'function_calls' => [], 'input_tokens' => 100, 'output_tokens' => 20,
            'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
    }
}

interface DocumentFollowupResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
