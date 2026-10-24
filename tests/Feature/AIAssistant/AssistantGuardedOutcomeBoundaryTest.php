<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantProviderFailure;
use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
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
use App\Models\Credits\AICreditProviderUsage;
use App\Models\Credits\AICreditReservation;
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

final class AssistantGuardedOutcomeBoundaryTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private User $operator;
    private AIAssistantDocument $document;
    private RagChunk $chunk;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 10:00:00');
        Queue::fake();
        config(['cache.default' => 'array', 'logging.default' => 'null', 'ai-assistant.enabled' => true,
            'ai-assistant-credits.enforce' => true, 'ai-assistant.llm.provider' => 'timeweb',
            'ai-assistant.llm.timeweb.base_uri' => 'https://fixture.invalid/v1',
            'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key',
            'ai-assistant-credits.profiles.short' => ['input_tokens' => 12288, 'output_tokens' => 1024, 'max_calls' => 3]]);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        $this->operator = User::factory()->create(['is_active' => true]);
        UserRoleAssignment::query()->create(['user_id' => $this->operator->id, 'role_slug' => 'system_admin',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM, 'context_id' => AuthorizationContext::getSystemContext()->id,
            'assigned_by' => $this->operator->id, 'is_active' => true]);
        $embedding = $this->createMock(RagEmbeddingProviderInterface::class);
        $embedding->method('provider')->willReturn('offline');
        $embedding->method('model')->willReturn('offline');
        $embedding->method('dimensions')->willReturn(256);
        $embedding->expects(self::never())->method('embed');
        $this->app->instance(RagEmbeddingProviderInterface::class, $embedding);
        $file = Model::withoutEvents(fn () => File::query()->create(['organization_id' => $this->fixture->organization->id,
            'user_id' => $this->fixture->owner->id, 'fileable_type' => $this->project->getMorphClass(), 'fileable_id' => $this->project->id,
            'name' => 'Учебные условия.txt', 'original_name' => 'Учебные условия.txt', 'path' => 'private/sample.txt',
            'disk' => 's3', 'mime_type' => 'text/plain', 'size' => 100]));
        $text = 'Учебные условия приёмки бетона требуют сверки документов.';
        $this->document = Model::withoutEvents(fn () => AIAssistantDocument::query()->create(['organization_id' => $this->fixture->organization->id,
            'project_id' => $this->project->id, 'file_id' => $file->id, 'parent_entity_type' => 'project', 'parent_entity_id' => (string) $this->project->id,
            'storage_path' => $file->path, 'filename' => $file->name, 'mime_type' => 'text/plain', 'checksum' => hash('sha256', $text),
            'size_bytes' => 100, 'status' => 'ready', 'coverage_status' => 'full', 'processed_at' => now(), 'extracted_text' => $text, 'metadata' => []]));
        $unit = AIAssistantDocumentUnit::query()->create(['document_id' => $this->document->id, 'unit_type' => 'page', 'unit_index' => 0,
            'text' => $text, 'checksum' => hash('sha256', $text), 'provenance' => ['page' => 1]]);
        $data = FileRagSource::chunkForUnit($this->document, $unit);
        $indexer = new RagIndexer($embedding, new RagSourceRegistry([]));
        $source = RagSource::query()->create($indexer->coverageIdentity($data) + ['title' => $data->title, 'source_version' => now()->toAtomString(),
            'metadata' => $data->metadata, 'indexed_at' => now()]);
        $this->chunk = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $data->organizationId, 'project_id' => $data->projectId,
            'chunk_index' => 0, 'content' => $text, 'content_hash' => hash('sha256', $text),
            'metadata' => $data->metadata + ['chunk_index' => 0, 'chunk_count' => 1], 'embedding_provider' => 'offline', 'embedding_model' => 'offline']);
        $this->actingAs($this->fixture->owner);
        app(AICreditService::class)->grant($this->fixture->organization, 100000, 'purchase', null, 'outcome-boundary-'.Str::uuid());
        DB::commit();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (DB::transactionLevel() === 0) { DB::beginTransaction(); }
        parent::tearDown();
    }

    public static function misleadingLegacyPlans(): iterable
    {
        yield 'document is an evidence source' => ['Собери короткую проверку по графику проекта и условиям приёмки в утверждённом документе.'];
        yield 'file is an evidence source' => ['Подготовь сводку по графику проекта и требованиям в утверждённом файле.'];
    }

    #[DataProvider('misleadingLegacyPlans')]
    public function test_successful_guarded_reads_do_not_inherit_legacy_report_availability_failure(string $query): void
    {
        $plan = app(AssistantTaskOrchestrator::class)->plan($query, [], []);
        self::assertSame('generate_report', $plan['request_understanding']['primary_intent']);
        self::assertNotEmpty($plan['capability']['domain']);
        [$cohort, $payload, $manifest] = $this->enroll($query);
        $calls = 0;
        $final = 'График и утверждённый фрагмент позволяют обсудить сроки и условия приёмки. Проверены только выбранные источники.';
        $provider = $this->provider(function (array $input, array $options) use (&$calls, $manifest, $final): array {
            self::assertSame($calls < 2 ? 'auto' : 'none', $options['tool_choice']);
            self::assertEqualsCanonicalizing(['get_project_attention', OrdinaryAssistantDocumentProjection::TOOL], array_column($options['tools'], 'name'));
            $outputs = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'));
            self::assertCount($calls, $outputs);
            foreach ($outputs as $index => $output) {
                self::assertSame($manifest['sources'][$index]['projection'], json_decode($output['output'], true, 512, JSON_THROW_ON_ERROR));
            }
            if ($calls < 2) {
                return $this->nativeCall($calls++);
            }
            $calls++;
            return $this->answer($final);
        });

        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($query, $cohort->organization_id, $this->fixture->owner, null, $payload);

        self::assertSame(3, $calls);
        self::assertSame($final, $response['message']['content']);
        self::assertNotContains($response['message']['metadata']['outcome'] ?? null, ['service_error', 'access_denied', 'request_blocked', 'insufficient_data']);
        self::assertFalse($response['message']['metadata']['service_error'] ?? false);
        self::assertCount(2, $response['message']['metadata']['source_refs']);
        self::assertSame([], $response['message']['metadata']['artifacts']);
        $request = AssistantRequest::query()->where('request_id', $response['request_id'])->sole();
        self::assertSame('completed', $request->status);
        self::assertSame(3, $request->calls_used);
        self::assertGreaterThan(0, $response['credit_usage']['charged_minor']);
        self::assertSame(3, AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->count());
    }

    public function test_guarded_scope_revocation_after_native_read_blocks_publication_and_charge(): void
    {
        $query = 'Собери короткую проверку по графику проекта и условиям приёмки в утверждённом документе.';
        [$cohort, $payload] = $this->enroll($query);
        $calls = 0;
        $provider = $this->provider(function () use (&$calls, $cohort): array {
            if ($calls++ === 0) { return $this->nativeCall(0); }
            $cohort->updateQuietly(['generation' => (string) Str::uuid()]);
            return $this->answer('Учебные условия проверены.');
        });
        try {
            AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
                app(OrdinaryAssistantScopedContext::class))->ask($query, $cohort->organization_id, $this->fixture->owner, null, $payload);
            self::fail('Revoked scope was published');
        } catch (AssistantEgressDenied) {
            $request = AssistantRequest::query()->where('request_id', $payload['request_id'])->sole();
            self::assertSame('failed', $request->status);
            self::assertSame(0, (int) AICreditReservation::query()->findOrFail($request->reservation_id)->consumed_minor);
            self::assertSame(0, app(AICreditService::class)->balance($this->fixture->organization)['reserved_minor']);
            self::assertSame(2, $calls);
        }
    }

    #[DataProvider('misleadingLegacyPlans')]
    public function test_unconfirmed_report_completion_is_still_rejected_without_real_artifact(string $query): void
    {
        [$cohort, $payload] = $this->enroll($query);
        $calls = 0;
        $provider = $this->provider(function () use (&$calls): array {
            if ($calls < 2) { return $this->nativeCall($calls++); }
            $calls++;
            return $this->answer('Отчёт готов, скачать: https://fixture.invalid/fake.pdf');
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($query, $cohort->organization_id, $this->fixture->owner, null, $payload);

        self::assertSame(trans_message('ai_assistant.report_download_missing'), $response['message']['content']);
        self::assertSame(3, $calls);
        self::assertSame([], $response['message']['metadata']['artifacts']);
        self::assertCount(2, $response['message']['metadata']['source_refs']);
        self::assertSame('insufficient_data', $response['message']['metadata']['outcome']);
        self::assertSame(0, $response['credit_usage']['charged_minor']);
    }

    public static function actualToolFailures(): iterable
    {
        yield 'operational exception' => [new \RuntimeException('offline tool unavailable')];
        yield 'access denied' => [new \Illuminate\Auth\Access\AuthorizationException('offline tool forbidden')];
    }

    #[DataProvider('actualToolFailures')]
    public function test_actual_guarded_tool_failure_cannot_become_a_charged_success(\Throwable $failure): void
    {
        $query = 'Собери короткую проверку по графику проекта и условиям приёмки в утверждённом документе.';
        [$cohort, $payload] = $this->enroll($query);
        $registry = app(AIToolRegistry::class);
        $original = $registry->getTool('get_project_attention');
        self::assertNotNull($original);
        $failedReads = 0;
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn($original->getName());
        $tool->method('getDescription')->willReturn($original->getDescription());
        $tool->method('getParametersSchema')->willReturn($original->getParametersSchema());
        $tool->method('execute')->willReturnCallback(static function (array $arguments, User $actor, \App\Models\Organization $organization) use ($original, $failure, &$failedReads): array|string {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (($frame['function'] ?? null) === 'handleToolCall') {
                    $failedReads++;
                    throw $failure;
                }
            }
            return $original->execute($arguments, $actor, $organization);
        });
        $registry->registerTool($tool);
        $calls = 0;
        $provider = $this->provider(function () use (&$calls): array {
            self::assertSame(0, $calls++);
            return $this->nativeCall(0);
        });
        try {
            AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
                app(OrdinaryAssistantScopedContext::class))->ask($query, $cohort->organization_id, $this->fixture->owner, null, $payload);
            self::fail('Failed read was published');
        } catch (AssistantEgressDenied|AssistantProviderFailure) {
            $request = AssistantRequest::query()->where('request_id', $payload['request_id'])->sole();
            self::assertSame('failed', $request->status);
            self::assertSame(1, $failedReads);
            self::assertSame(1, $calls);
            self::assertSame(0, (int) AICreditReservation::query()->findOrFail($request->reservation_id)->consumed_minor);
            self::assertSame(0, app(AICreditService::class)->balance($this->fixture->organization)['reserved_minor']);
            self::assertSame(1, AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->count());
        }
    }

    private function enroll(string $query): array
    {
        $enrollments = app(OrdinaryAssistantEnrollmentService::class);
        $cohort = $enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, ['get_project_attention', OrdinaryAssistantDocumentProjection::TOOL], 'ordinary-project-sql-document/1');
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $query, 'conversation_id' => null, 'profile' => 'short', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        $manifest = $enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
            [['tool' => 'get_project_attention', 'arguments' => $this->sqlArguments()], ['tool' => OrdinaryAssistantDocumentProjection::TOOL,
                'arguments' => ['project_id' => $this->project->id, 'document_id' => $this->document->id, 'chunk_ids' => [$this->chunk->id]]]]);
        $enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        return [$cohort, $payload, $manifest];
    }

    private function sqlArguments(): array
    {
        return ['project_id' => $this->project->id, 'focus' => 'schedule', 'limit' => 3];
    }

    private function provider(callable $callback): GuardedOutcomeResponsesProvider
    {
        $provider = $this->createMock(GuardedOutcomeResponsesProvider::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->method('responses')->willReturnCallback($callback);
        return $provider;
    }

    private function nativeCall(int $index): array
    {
        $name = $index === 0 ? 'get_project_attention' : OrdinaryAssistantDocumentProjection::TOOL;
        $arguments = $index === 0 ? $this->sqlArguments()
            : ['query' => 'бетона', 'project_id' => $this->project->id, 'source_types' => ['file_document'], 'limit' => 1, 'retrieval_mode' => 'lexical'];
        $call = ['type' => 'function_call', 'id' => 'fc_boundary_'.$index, 'call_id' => 'call_boundary_'.$index,
            'status' => 'completed', 'name' => $name, 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)];
        return ['content' => '', 'output' => [$call], 'function_calls' => [$call]] + $this->answer('');
    }

    private function answer(string $text): array
    {
        return ['content' => $text, 'output' => [], 'function_calls' => [], 'input_tokens' => 100, 'output_tokens' => 20,
            'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
    }
}

interface GuardedOutcomeResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
