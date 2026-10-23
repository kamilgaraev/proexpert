<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\AIAssistant\Services\Rag\OpenAIRagEmbeddingProvider;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentProcessingPolicyService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\FileService;
use App\Services\Credits\AICreditService;
use App\Models\Credits\AICreditReservation;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentBudgetService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentOcrClient;
use App\Jobs\ProcessAssistantDocumentOcr;
use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantLocalDocumentProcessingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private User $operator;
    private AssistantDocumentService $documents;
    private const SENTINEL = 'B3_PRIVATE_DOCUMENT_SENTINEL';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('s3');
        Http::preventStrayRequests();
        config(['cache.default' => 'array', 'logging.default' => 'stderr', 'ai-assistant-credits.enforce' => true,
            'ai-assistant.llm.timeweb.api_key' => 'offline-only-key', 'ai-assistant.llm.timeweb.base_uri' => 'https://fixture.invalid/v1']);
        $this->fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        $this->operator = User::factory()->create(['is_active' => true]);
        UserRoleAssignment::query()->create(['user_id' => $this->operator->id, 'role_slug' => 'system_admin',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM, 'context_id' => AuthorizationContext::getSystemContext()->id,
            'assigned_by' => $this->operator->id, 'is_active' => true]);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('readCurrentBounded')->andReturnUsing(static fn (string $path) => Storage::disk('s3')->readStream($path));
        $this->app->instance(FileService::class, $files);
        $this->documents = app(AssistantDocumentService::class);
        app(AICreditService::class)->grant($this->fixture->organization, 1_000_000, 'purchase', null, 'b3-local-processing');
    }

    private function document(string $text = 'Локальный документ: бетон 12 кубов.', string $mime = 'text/plain'): AIAssistantDocument
    {
        $name = $mime === 'image/png' ? 'scan.png' : 'document.txt';
        $path = 'org-'.$this->fixture->organization->id.'/b3/'.$name;
        Storage::disk('s3')->put($path, $text);
        $file = File::withoutEvents(fn () => File::query()->create(['organization_id' => $this->fixture->organization->id,
            'fileable_type' => $this->project->getMorphClass(), 'fileable_id' => $this->project->id,
            'user_id' => $this->fixture->owner->id, 'name' => $name, 'original_name' => $name,
            'path' => $path, 'mime_type' => $mime, 'size' => strlen($text), 'disk' => 's3']));
        return $this->documents->registerFile($file);
    }

    public function test_selected_local_document_is_ready_and_indexed_without_embedding_calls(): void
    {
        $document = $this->document();
        app(AssistantDocumentProcessingPolicyService::class)->assign($this->operator, $this->fixture->organization->id,
            $this->project->id, $document->id, $this->fixture->owner->id);
        $document = $this->documents->process($document->id);
        self::assertSame(AIAssistantDocument::STATUS_READY, $document->status);
        $embeddings = new LocalProcessingRecordingEmbeddings;
        $indexer = new RagIndexer($embeddings, new RagSourceRegistry([new FileRagSource]));
        self::assertSame(1, $indexer->indexEntity($document->organization_id, 'file_document', 'assistant_document', $document->id));
        self::assertSame(0, $embeddings->calls);
        $chunks = RagChunk::query()->where('organization_id', $document->organization_id)->get();
        self::assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            self::assertNull($chunk->embedding);
            self::assertNull($chunk->embedding_provider);
            self::assertNull($chunk->embedding_model);
            self::assertNull($chunk->embedding_created_at);
        }
        self::assertSame(1, $indexer->indexOrganization($document->organization_id, $document->project_id, 'file_document'));
        (new IndexRagSourceJob($document->organization_id, $document->project_id, 'file_document', entityType: 'assistant_document', entityId: $document->id))->handle($indexer);
        self::assertSame(0, $embeddings->calls);
        $chunks = RagChunk::query()->where('organization_id', $document->organization_id)->get();
        $projection = app(OrdinaryAssistantDocumentProjection::class)->capture($this->fixture->owner, $document->organization_id,
            $document->project_id, ['project_id' => $document->project_id, 'document_id' => $document->id, 'chunk_ids' => $chunks->modelKeys()]);
        self::assertSame('Локальный документ: бетон 12 кубов.', $projection['preview']['data']['fragments'][0]['content']);
        $results = (new RagRetriever($embeddings, app(\App\Services\Project\UserProjectAccessService::class),
            app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class)))
            ->search('бетон', $document->organization_id, $this->fixture->owner,
                ['project_id' => $document->project_id, 'source_types' => ['file_document'], 'retrieval_mode' => 'lexical', 'limit' => 1], corpus: $projection['corpus']);
        self::assertCount(1, $results);
        self::assertSame(0, $embeddings->calls);
        $coverage = app(AssistantDocumentCoverageService::class)->coverage($document->organization_id, $this->fixture->owner,
            scope: ['project_id' => $document->project_id, 'source_types' => ['file_document'], 'retrieval_mode' => 'semantic']);
        self::assertFalse(AssistantDocumentCoverageService::isCompleteForAnswer($coverage));
    }

    public function test_legacy_vectors_and_checkpoints_do_not_bypass_a_later_local_policy(): void
    {
        $document = $this->documents->process($this->document()->id);
        $embeddings = new LocalProcessingRecordingEmbeddings;
        $indexer = new RagIndexer($embeddings, new RagSourceRegistry([new FileRagSource]));
        $chunk = iterator_to_array((new FileRagSource)->collectEntity($document->organization_id, 'assistant_document', $document->id))[0];
        $indexer->indexChunk($chunk);
        self::assertSame(1, $embeddings->calls);
        self::assertNotNull(RagChunk::query()->where('organization_id', $document->organization_id)->firstOrFail()->embedding);
        $sourceKey = (new \ReflectionMethod(RagIndexer::class, 'sourceIndexLockKey'))->invoke($indexer, $chunk);
        app(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingCheckpointStore::class)->save(
            $document->organization_id, $sourceKey, 'old-profile', hash('sha256', $chunk->content), json_encode([1.0, ...array_fill(0, 255, 0.0)], JSON_THROW_ON_ERROR));
        app(AssistantDocumentProcessingPolicyService::class)->assign($this->operator, $document->organization_id, $document->project_id, $document->id, $this->fixture->owner->id);
        $indexer->indexChunk($chunk);
        self::assertSame(1, $embeddings->calls);
        self::assertNull(RagChunk::query()->where('organization_id', $document->organization_id)->firstOrFail()->embedding);
        self::assertSame(0, DB::table('ai_rag_embedding_checkpoints')->where('organization_id', $document->organization_id)->count());
    }

    #[DataProvider('processingChanges')]
    public function test_interleaved_selected_policy_or_source_changes_do_not_publish_chunks(string $change): void
    {
        $document = $this->document(self::SENTINEL);
        $policies = app(AssistantDocumentProcessingPolicyService::class);
        $policy = $policies->assign($this->operator, $document->organization_id, $document->project_id, $document->id, $this->fixture->owner->id);
        $document = $this->documents->process($document->id);
        $chunk = iterator_to_array((new FileRagSource)->collectEntity($document->organization_id, 'assistant_document', $document->id))[0];
        $embeddings = new LocalProcessingRecordingEmbeddings;
        $indexer = new RagIndexer($embeddings, new RagSourceRegistry([new FileRagSource]));
        $checks = 0;
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                $indexer->indexChunk($chunk, guard: function () use (&$checks, $change, $document, $policies, $policy): void {
                    if (++$checks !== 2) { return; }
                    match ($change) {
                        'generation' => $policies->assign($this->operator, $document->organization_id, $document->project_id, $document->id, $this->fixture->owner->id),
                        'revoke' => $policies->revoke($this->operator, $document->organization_id, $document->id),
                        'unknown version' => $policy->update(['policy_version' => 'unknown']),
                        'source' => $document->update(['checksum' => hash('sha256', 'changed')]),
                        'raw unit' => AIAssistantDocumentUnit::query()->where('document_id', $document->id)->update(['text' => 'changed']),
                        'file' => File::query()->whereKey($document->file_id)->update(['deleted_at' => now()]),
                        'ACL' => $this->fixture->organization->users()->updateExistingPivot($this->fixture->owner->id, ['is_active' => false]),
                    };
                });
                self::fail('Stale selected processing published chunks');
            } catch (AssistantEgressDenied $denied) {
                self::assertNull($denied->getPrevious());
                self::assertStringNotContainsString(self::SENTINEL, (string) $denied);
                self::assertStringNotContainsString(self::SENTINEL, json_encode($denied->getTrace(), JSON_THROW_ON_ERROR));
            }
        } finally { ini_set('zend.exception_ignore_args', $previous); }
        self::assertSame(0, $embeddings->calls);
        self::assertSame(0, RagChunk::query()->where('organization_id', $document->organization_id)->count());
    }

    public static function processingChanges(): iterable
    {
        foreach (['generation', 'revoke', 'unknown version', 'source', 'raw unit', 'file', 'ACL'] as $change) { yield $change => [$change]; }
    }

    public function test_assignment_between_real_embedding_attempts_stops_retry_and_keeps_unknown_usage(): void
    {
        $document = $this->documents->process($this->document(self::SENTINEL)->id);
        $history = [];
        $handler = new \GuzzleHttp\Handler\MockHandler([function () use ($document): \GuzzleHttp\Psr7\Response {
            app(AssistantDocumentProcessingPolicyService::class)->assign($this->operator, $document->organization_id,
                $document->project_id, $document->id, $this->fixture->owner->id);
            return new \GuzzleHttp\Psr7\Response(503, ['Content-Type' => 'application/json'], json_encode(['error' => ['message' => self::SENTINEL, 'type' => 'server_error']], JSON_THROW_ON_ERROR));
        }]);
        $client = \OpenAI::factory()->withApiKey('offline-only-key')->withBaseUri('https://fixture.invalid/v1')
            ->withHttpClient(new \GuzzleHttp\Client(['handler' => \GuzzleHttp\Middleware::history($history)($handler)]))->make();
        $provider = new OpenAIRagEmbeddingProvider($client, 'offline-only-key', 'text-embedding-3-small', 256, 'https://fixture.invalid/v1', 'openai');
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                (new RagIndexer($provider, new RagSourceRegistry([new FileRagSource])))
                    ->indexEntity($document->organization_id, 'file_document', 'assistant_document', $document->id);
                self::fail('Late local assignment permitted a retry');
            } catch (AssistantEgressDenied $denied) {
                self::assertNull($denied->getPrevious());
                self::assertStringNotContainsString(self::SENTINEL, (string) $denied);
                self::assertStringNotContainsString(self::SENTINEL, json_encode($denied->getTrace(), JSON_THROW_ON_ERROR));
            }
        } finally { ini_set('zend.exception_ignore_args', $previous); }
        self::assertCount(1, $history);
        self::assertCount(1, $provider->usageAttempts());
        self::assertFalse($provider->usageAttempts()[0]['provider_usage_available']);
        self::assertSame(1, \App\BusinessModules\Features\AIAssistant\Models\AIUsageRecord::query()->where('organization_id', $document->organization_id)->where('operation', 'rag_index')->count());
        self::assertSame(0, RagChunk::query()->where('organization_id', $document->organization_id)->count());
    }

    public function test_selected_scan_cannot_quote_reserve_or_dispatch_ocr_and_keeps_partial_coverage(): void
    {
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $document = $this->document($image, 'image/png');
        app(AssistantDocumentProcessingPolicyService::class)->assign($this->operator, $document->organization_id, $document->project_id, $document->id, $this->fixture->owner->id);
        $document = $this->documents->process($document->id);
        self::assertSame(AIAssistantDocument::STATUS_OCR_QUOTE_REQUIRED, $document->status);
        self::assertNotSame('full', $document->coverage_status);
        self::assertFalse(app(AssistantDocumentBudgetService::class)->authorizeBackground($document));
        $quotes = \App\Models\Credits\AICreditQuote::query()->count();
        try { $this->documents->quoteOcr($this->fixture->owner, $document->organization_id, $document); self::fail('Local scan received OCR quote'); }
        catch (AssistantEgressDenied) { self::assertSame($quotes, \App\Models\Credits\AICreditQuote::query()->count()); }
        try { $this->documents->confirmOcr($this->fixture->owner, $document->organization_id, $document, 'forged', 'forged'); self::fail('Local scan reserved OCR'); }
        catch (AssistantEgressDenied) { self::assertSame(0, AICreditReservation::query()->where('organization_id', $document->organization_id)->count()); }
        Queue::assertNotPushed(ProcessAssistantDocumentOcr::class);
        Http::assertNothingSent();
    }

    #[DataProvider('pastOcrUsage')]
    public function test_already_queued_ocr_is_terminal_locally_without_erasing_existing_usage(bool $known): void
    {
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $document = $this->documents->process($this->document($image, 'image/png')->id);
        $quote = $this->documents->quoteOcr($this->fixture->owner, $document->organization_id, $document);
        $document = $this->documents->confirmOcr($this->fixture->owner, $document->organization_id, $document, $quote['quote_id'], $quote['request_id']);
        $reservation = AICreditReservation::query()->findOrFail($document->ocr_reservation_id);
        app(AICreditService::class)->recordProviderCost($reservation, $known ? 12345 : 0, 'timeweb', \App\Support\AI\LunaModelPolicy::TIMEWEB, 'ocr',
            ['usage_key' => 'b3-prior-attempt', 'provider_usage_available' => $known, 'cost_available' => $known,
                'usage_source' => $known ? 'provider_response' : 'unavailable', 'input_tokens' => $known ? 71 : 0,
                'output_tokens' => $known ? 9 : 0, 'is_successful' => $known], $known);
        $before = \App\Models\Credits\AICreditProviderUsage::query()->where('ai_credit_reservation_id', $reservation->id)->get()->toArray();
        $job = new ProcessAssistantDocumentOcr($document->id);
        app(AssistantDocumentProcessingPolicyService::class)->assign($this->operator, $document->organization_id, $document->project_id, $document->id, $this->fixture->owner->id);
        $job->handle($this->documents);
        self::assertSame('local_processing_ocr_disabled', $document->refresh()->last_error);
        self::assertNotSame('reserved', $reservation->refresh()->status);
        self::assertSame($before, \App\Models\Credits\AICreditProviderUsage::query()->where('ai_credit_reservation_id', $reservation->id)->get()->toArray());
        Http::assertNothingSent();
        Queue::assertNotPushed(ProcessAssistantDocumentOcr::class);
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            try { app(AssistantDocumentOcrClient::class)->recognize($document, $reservation, self::SENTINEL, static fn (): bool => true); self::fail('Direct OCR bypassed local policy'); }
            catch (AssistantEgressDenied $denied) { self::assertStringNotContainsString(self::SENTINEL, (string) $denied); self::assertNull($denied->getPrevious()); }
        } finally { ini_set('zend.exception_ignore_args', $previous); }
        Http::assertNothingSent();
    }

    public static function pastOcrUsage(): iterable
    {
        yield 'known occurred usage' => [true];
        yield 'unknown occurred usage' => [false];
    }

    public function test_legacy_ocr_still_sends_the_existing_page_and_finishes_with_usage(): void
    {
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $document = $this->documents->process($this->document($image, 'image/png')->id);
        self::assertSame(AIAssistantDocument::STATUS_OCR_QUOTE_REQUIRED, $document->status);
        $quote = $this->documents->quoteOcr($this->fixture->owner, $document->organization_id, $document);
        $document = $this->documents->confirmOcr($this->fixture->owner, $document->organization_id, $document, $quote['quote_id'], $quote['request_id']);
        Http::fake(['https://fixture.invalid/v1/chat/completions' => Http::response(['model' => \App\Support\AI\LunaModelPolicy::TIMEWEB,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Распознанный учебный текст.']]]])]);
        $result = $this->documents->processOcr($document->id);
        self::assertSame(AIAssistantDocument::STATUS_READY, $result->status);
        self::assertSame('ocr', $result->coverage_status);
        Http::assertSentCount(1);
        Http::assertSent(static fn (\Illuminate\Http\Client\Request $request): bool =>
            $request['messages'][1]['content'][1]['image_url']['url'] === 'data:image/png;base64,'.base64_encode($image));
        self::assertSame(1, \App\Models\Credits\AICreditProviderUsage::query()->where('ai_credit_reservation_id', $document->ocr_reservation_id)->count());
    }

    public function test_assignment_from_the_last_ocr_authorization_callback_prevents_the_actual_post(): void
    {
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $document = $this->documents->process($this->document($image, 'image/png')->id);
        $quote = $this->documents->quoteOcr($this->fixture->owner, $document->organization_id, $document);
        $document = $this->documents->confirmOcr($this->fixture->owner, $document->organization_id, $document, $quote['quote_id'], $quote['request_id']);
        $reservation = AICreditReservation::query()->findOrFail($document->ocr_reservation_id);
        try {
            app(AssistantDocumentOcrClient::class)->recognize($document, $reservation, $image, function () use ($document): bool {
                app(AssistantDocumentProcessingPolicyService::class)->assign($this->operator, $document->organization_id,
                    $document->project_id, $document->id, $this->fixture->owner->id);
                return true;
            });
            self::fail('Late assignment reached OCR transport');
        } catch (AssistantEgressDenied) { Http::assertNothingSent(); }
        self::assertSame(0, \App\Models\Credits\AICreditProviderUsage::query()->where('ai_credit_reservation_id', $reservation->id)->count());
        self::assertSame(0, (int) ($document->refresh()->metadata['ocr_attempt'] ?? 0));
    }

    public function test_only_platform_can_select_exact_scope_and_revoke_keeps_a_tombstone(): void
    {
        $document = $this->document();
        $queue = Queue::getFacadeRoot();
        self::assertInstanceOf(\Illuminate\Support\Testing\Fakes\QueueFake::class, $queue);
        $queuedBefore = $queue->pushed(IndexRagSourceJob::class)->count();
        $this->withoutMiddleware();
        $path = '/api/v1/admin/ai-assistant/documents/'.$document->id.'/local-processing';
        $payload = ['project_id' => $document->project_id, 'actor_user_id' => $this->fixture->owner->id,
            'policy_version' => AssistantDocumentProcessingPolicyService::VERSION, 'synthetic' => true];
        $this->actingAs($this->fixture->owner)->postJson($path, $payload)->assertForbidden();
        $this->operator->updateQuietly(['current_organization_id' => $document->organization_id]);
        $this->actingAs($this->operator)->postJson($path, array_replace($payload, ['policy_version' => 'external-allowed']))->assertUnprocessable();
        $otherProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $document->organization_id]));
        $this->postJson($path, array_replace($payload, ['project_id' => $otherProject->id]))->assertForbidden();
        $this->postJson($path, array_replace($payload, ['actor_user_id' => $this->operator->id]))->assertForbidden();
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.policy_version', AssistantDocumentProcessingPolicyService::VERSION)
            ->assertJsonPath('data.state', 'active');
        self::assertSame($queuedBefore, $queue->pushed(IndexRagSourceJob::class)->count());
        UserRoleAssignment::query()->where('user_id', $this->operator->id)->update(['is_active' => false]);
        $this->postJson($path, $payload)->assertForbidden();
        UserRoleAssignment::query()->where('user_id', $this->operator->id)->update(['is_active' => true]);
        $this->deleteJson($path)->assertOk()->assertJsonPath('data.state', 'revoked');
        self::assertTrue(app(AssistantDocumentProcessingPolicyService::class)->selected($document->id));
        $this->expectException(AssistantEgressDenied::class);
        app(AssistantDocumentProcessingPolicyService::class)->capture($document->id);
    }
}

final class LocalProcessingRecordingEmbeddings implements RagEmbeddingProviderInterface
{
    public int $calls = 0;
    public function provider(): string { return 'offline'; }
    public function model(): string { return 'offline'; }
    public function dimensions(): int { return 256; }
    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        $this->calls++;
        return [1.0, ...array_fill(0, 255, 0.0)];
    }
}
