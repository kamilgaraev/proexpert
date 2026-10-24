<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\ExecuteAssistantChatJob;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantProviderFactory;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;


final class AssistantProjectedDocumentReferencePassTest extends TestCase
{
    protected array $connectionsToTransact = [];

    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private AIAssistantDocument $document;
    private RagChunk $chunk;
    private User $reviewer;
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
    }

    private function prepareMixed(): void
    {
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->fixture->owner->updateQuietly(['name' => self::ACCOUNT]);
        $this->project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        QualityDefect::withoutEvents(fn () => QualityDefect::query()->create([
            'organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id,
            'defect_number' => (string) Str::uuid(), 'title' => 'Учебное критическое замечание',
            'severity' => 'critical', 'status' => 'open', 'due_date' => '2026-10-12']));
        $this->reviewer = User::factory()->create(['current_organization_id' => $this->fixture->organization->id, 'is_active' => true]);
        $this->fixture->organization->users()->attach($this->reviewer->id,
            ['is_owner' => true, 'is_active' => true, 'project_access_mode' => 'all_projects']);
        UserRoleAssignment::query()->create(['user_id' => $this->reviewer->id,
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


    public function test_actual_queued_mixed_document_uses_one_real_acl_traversal_in_each_fresh_reference_pass(): void
    {
        $this->runQueued();
    }

    public static function lateRevocations(): array
    {
        return ['actor_membership' => ['actor_membership'], 'document_checksum' => ['document_checksum']];
    }

    #[DataProvider('lateRevocations')]
    public function test_revocation_after_first_real_reference_pass_blocks_publication_and_charge(string $mutation): void
    {
        $this->runQueued($mutation);
    }

    private function runQueued(?string $mutation = null): void
    {
        $this->prepareMixed();
        [$cohort, $payload, $manifest] = $this->enroll();
        self::assertCount(2, $manifest['sources']);
        $expected = array_column($manifest['sources'], 'projection', 'tool')[OrdinaryAssistantDocumentProjection::TOOL];
        self::assertCount(1, $expected['data']['fragments']);
        self::assertGreaterThan(340, mb_strpos($expected['data']['fragments'][0]['content'], '17 тонн'));
        $wire = [];
        $responses = [];
        foreach ([false, true] as $final) {
            $responses[] = function (RequestInterface $request) use (&$wire, $final): Response {
                self::assertTrue(app()->bound(AssistantRequestExecutionContext::class));
                self::assertStringNotContainsString(self::ACCOUNT, (string) $request->getBody());
                $wire[] = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                return $final ? $this->sdkResponse('resp_reference_2', [['type' => 'message', 'id' => 'msg_reference_2', 'status' => 'completed',
                    'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => self::ANSWER, 'annotations' => []]]]])
                    : $this->sdkResponse('resp_reference_1', [['type' => 'function_call', 'id' => 'fc_reference_1', 'call_id' => 'call_reference_1',
                        'status' => 'completed', 'name' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => json_encode($this->args('арматура'), JSON_THROW_ON_ERROR)]]);
            };
        }
        $context = app(OrdinaryAssistantScopedContext::class);
        $egress = new OrdinaryAssistantEgress($context);
        $provider = (new OrdinaryAssistantProviderFactory($egress, app(LoggingService::class)))->make(new MockHandler($responses));
        $this->app->instance(AIAssistantService::class, app()->makeWith(AIAssistantService::class,
            ['llmProvider' => $provider, 'ordinaryEgress' => $egress, 'scopedContext' => $context]));
        $finishedPasses = [];
        $traversals = [1 => 0, 2 => 0];
        $guardTraversals = [1 => 0, 2 => 0];
        $active = true;
        $mutated = false;
        Log::listen(function (MessageLogged $event) use (&$finishedPasses, &$active, &$mutated, $mutation): void {
            if ($active && $event->message === 'ai.assistant.request_phase_completed'
                && in_array($event->context['phase'] ?? null, ['complete_reference_first', 'complete_reference_second'], true)) {
                $finishedPasses[] = $event->context;
                if ($mutation !== null && $event->context['phase'] === 'complete_reference_first' && $event->context['success']) {
                    if ($mutation === 'actor_membership') {
                        $this->fixture->organization->users()->updateExistingPivot($this->fixture->owner->id, ['is_active' => false]);
                    } else {
                        $this->document->updateQuietly(['checksum' => hash('sha256', 'changed-after-first-pass')]);
                    }
                    $mutated = true;
                }
            }
        });
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$finishedPasses, &$traversals, &$guardTraversals, &$active): void {
            if (! $active || ! str_contains(strtolower($query->sql), 'from "ai_rag_sources"')) { return; }
            $lifecycle = $reference = $mandatoryLookup = $guard = false;
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 64) as $frame) {
                $class = $frame['class'] ?? null;
                $function = $frame['function'] ?? null;
                $lifecycle = $lifecycle || ($class === AssistantRequestLifecycle::class && $function === 'assertResponseReferences');
                $reference = $reference || ($class === AssistantDataAccessPolicy::class && $function === 'canReadReference');
                $mandatoryLookup = $mandatoryLookup || ($function === 'find' && ($frame['line'] ?? null) === 312
                    && str_ends_with(str_replace('\\', '/', $frame['file'] ?? ''), '/AssistantDataAccessPolicy.php'));
                $guard = $guard || ($class === \App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard::class && $function === 'freshCurrent');
            }
            if ($lifecycle && $reference && $mandatoryLookup) {
                $pass = count($finishedPasses) + 1;
                self::assertContains($pass, [1, 2]);
                $traversals[$pass]++;
                if ($guard) { $guardTraversals[$pass]++; }
            }
        });
        $started = app(QueuedAssistantChatService::class)->submit($cohort->organization_id, $this->fixture->owner, null, $payload, 'admin');
        $denied = null;
        try { (new ExecuteAssistantChatJob($started['request']->id))->handle(app(AssistantRequestLifecycle::class), app(AssistantDataAccessPolicy::class)); }
        catch (AuthorizationException $failure) { $denied = $failure; }
        finally { $active = false; }
        self::assertSame($mutation !== null, $mutated);
        self::assertSame($mutation !== null, $denied !== null);
        self::assertFalse(app()->bound(AssistantRequestExecutionContext::class));
        self::assertCount(2, $wire);
        foreach ($wire as $index => $body) {
            self::assertSame('auto', $body['tool_choice']);
            self::assertEqualsCanonicalizing(['get_project_attention', OrdinaryAssistantDocumentProjection::TOOL], array_column($body['tools'], 'name'));
            $outputs = array_values(array_filter($body['input'], static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            self::assertCount($index, $outputs);
            if ($index === 1) {
                self::assertSame('call_reference_1', $outputs[0]['call_id']);
                self::assertSame($expected, json_decode($outputs[0]['output'], true, 512, JSON_THROW_ON_ERROR));
            }
        }
        $current = AssistantRequest::query()->findOrFail($started['request']->id);
        self::assertSame(2, $current->calls_used);
        self::assertSame((int) config('ai-assistant-credits.profiles.normal.max_calls'), $current->max_calls);
        $usage = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $current->reservation_id)->get();
        self::assertCount(2, $usage);
        self::assertTrue($usage->every(static fn ($row): bool => (bool) $row->is_successful));
        self::assertSame(['complete_reference_first', 'complete_reference_second'], array_column($finishedPasses, 'phase'));
        self::assertSame([true, $mutation === null], array_column($finishedPasses, 'success'));
        if ($mutation === null) {
            self::assertSame('completed', $current->status);
            self::assertSame(self::ANSWER, $current->response['message']['content']);
            self::assertCount(1, $current->response['message']['metadata']['source_refs']);
        } else {
            self::assertSame('failed', $current->status);
            self::assertSame(AuthorizationException::class, $finishedPasses[1]['exception_class']);
            self::assertSame(0, \App\BusinessModules\Features\AIAssistant\Models\Message::query()
                ->where('conversation_id', $current->conversation_id)->where('role', 'assistant')->count());
            $reservation = \App\Models\Credits\AICreditReservation::query()->findOrFail($current->reservation_id);
            self::assertSame('cancelled', $reservation->status);
            self::assertSame(0, $reservation->consumed_minor);
            self::assertNull($reservation->finalized_at);
        }
        self::assertSame([1 => 1, 2 => 1], $guardTraversals);
        self::assertSame([1 => 1, 2 => 1], $traversals);
    }

    public function test_real_reference_guard_preserves_acl_checksum_ttl_stored_and_legacy_dispatch(): void
    {
        $reference = $this->referenceFixture();
        $this->verifyReferences([$reference]);
        foreach ([null, $reference + []] as $malformed) {
            if ($malformed !== null) { unset($malformed['fetched_at']); }
            $this->assertReferenceDenied([$malformed]);
        }
        foreach (['organization_id' => $this->fixture->foreignOrganization->id,
            'document_checksum' => hash('sha256', 'foreign-document'),
            'unit_checksum' => hash('sha256', 'foreign-unit')] as $field => $invalid) {
            $changed = $reference;
            $changed[$field] = $invalid;
            $this->assertReferenceDenied([$changed]);
        }
        $partial = $reference;
        unset($partial['chunk_id']);
        $this->assertReferenceDenied([$partial], true);
        $this->assertReferenceDenied([['entity_type' => 'project', 'entity_id' => $this->project->id,
            'organization_id' => $this->fixture->foreignOrganization->id]]);
        Carbon::setTestNow(now()->addMinutes(10));
        $this->assertReferenceDenied([$reference]);
        $this->verifyReferences([$reference], true);
        $future = $reference;
        $future['fetched_at'] = now()->addMinute()->toISOString();
        $this->assertReferenceDenied([$future]);
        $this->assertReferenceDenied([$future], true);
        $legacy = $reference;
        $legacy['egress_projection_version'] = 'unknown-projection/1';
        $legacy['document_checksum'] = hash('sha256', 'legacy-does-not-use-projected-guard');
        $this->verifyReferences([$legacy]);
        $this->verifyReferences([$legacy], true);
        $this->fixture->organization->users()->updateExistingPivot($this->fixture->owner->id, ['is_active' => false]);
        $this->assertReferenceDenied([$reference], true);
    }

    public function test_deadline_during_the_actual_projected_guard_keeps_exception_identity_and_restores_policy(): void
    {
        $reference = $this->referenceFixture();
        $failure = new \App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
        $active = true;
        $injected = false;
        DB::connection()->beforeExecuting(static function () use (&$active, &$injected, $failure): void {
            if (! $active || $injected) { return; }
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 64) as $frame) {
                if (($frame['class'] ?? null) === \App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard::class
                    && ($frame['function'] ?? null) === 'freshCurrent') {
                    $injected = true;
                    throw $failure;
                }
            }
        });
        try {
            $this->verifyReferences([$reference]);
            self::fail('Deadline lost');
        } catch (\App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded $actual) {
            self::assertSame($failure, $actual);
        } finally {
            $active = false;
        }
        self::assertTrue($injected);
        $this->verifyReferences([$reference]);
    }

    private function referenceFixture(): array
    {
        $this->prepareMixed();
        app(AssistantDataAccessPolicy::class)->setTrustedSurface(\App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface::ADMIN);
        $capture = app(OrdinaryAssistantDocumentProjection::class)->capture($this->fixture->owner,
            $this->fixture->organization->id, $this->project->id,
            ['project_id' => $this->project->id, 'document_id' => $this->document->id, 'chunk_ids' => [$this->chunk->id]]);
        return $capture['corpus']->references()[$this->chunk->id]
            + ['egress_projection_version' => OrdinaryAssistantDocumentProjection::VERSION];
    }

    private function verifyReferences(array $references, bool $stored = false): void
    {
        (new \ReflectionMethod(AssistantRequestLifecycle::class, 'assertResponseAccess'))->invoke(
            app(AssistantRequestLifecycle::class), ['message' => ['metadata' => ['source_refs' => $references]]],
            $this->fixture->owner, $this->fixture->organization->id, $stored);
    }

    private function assertReferenceDenied(array $references, bool $stored = false): void
    {
        try {
            $this->verifyReferences($references, $stored);
            self::fail('Reference denial lost');
        } catch (AuthorizationException $denied) {
            self::assertSame(AuthorizationException::class, $denied::class);
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
