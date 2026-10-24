<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantAclQueryCompiler;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use App\Services\Monitoring\ApiQueryMetrics;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use LogicException;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantSourceCatalogPermissionPairTest extends TestCase
{
    protected array $connectionsToTransact = [];

    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private AIAssistantDocument $document;
    private array $chunks = [];
    private mixed $originalRedis = null;
    private const SENTINEL = 'APPROVED_FRAGMENT_SENTINEL';
    private const ACCOUNT = 'PRIVATE_ACCOUNT_NOT_APPROVED';

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(0, DB::transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->originalRedis = Redis::getFacadeRoot();
        Carbon::setTestNow('2026-10-23 10:00:00');
        Queue::fake();
        Redis::swap(new class
        {
            public function connection(?string $name = null): object
            {
                return new class
                {
                    public function eval(mixed ...$arguments): int
                    {
                        return 1;
                    }
                };
            }
        });
        config(['cache.default' => 'array', 'logging.default' => 'stderr', 'ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true,
            'ai-assistant.llm.provider' => 'timeweb', 'ai-assistant.llm.timeweb.base_uri' => 'https://fixture.invalid/v1',
            'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->fixture->owner->updateQuietly(['name' => self::ACCOUNT]);
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
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
        foreach (['арматура: '.str_repeat('учебные сведения ', 32).'Утверждённый объём: 17 тонн '.self::SENTINEL] as $number => $text) {
            $unit = AIAssistantDocumentUnit::query()->create(['document_id' => $this->document->id, 'unit_type' => 'page', 'unit_index' => $number,
                'text' => $text, 'checksum' => hash('sha256', $text), 'provenance' => ['page' => $number + 1]]);
            $data = FileRagSource::chunkForUnit($this->document, $unit);
            $source = RagSource::query()->create($indexer->coverageIdentity($data) + ['title' => $data->title, 'source_version' => now()->toAtomString(),
                'metadata' => $data->metadata, 'indexed_at' => now()]);
            $this->chunks[] = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $data->organizationId, 'project_id' => $data->projectId,
                'chunk_index' => 0, 'content' => $text, 'content_hash' => hash('sha256', $text),
                'metadata' => $data->metadata + ['chunk_index' => 0, 'chunk_count' => 1], 'embedding_provider' => 'offline', 'embedding_model' => 'offline']);
        }
        $this->actingAs($this->fixture->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->originalRedis !== null) { Redis::swap($this->originalRedis); }
        parent::tearDown();
    }

    public function test_catalog_permission_pair_uses_one_fresh_scope_and_closes_it_before_projection(): void
    {
        $policy = app(AssistantDataAccessPolicy::class);
        $metrics = new ApiQueryMetrics(true);
        $request = app('request');
        $previous = $request->attributes->get(ApiQueryMetrics::REQUEST_ATTRIBUTE);
        $observed = null;
        $catalog = $this->observedCatalog(OrdinaryAssistantDocumentProjection::TOOL, function () use ($metrics, $policy, &$observed): void {
            $observed = ['reads' => $metrics->summary()['access_reads'], 'identity' => $this->privateValue($policy, 'batchIdentity')];
        });
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
        try {
            $capture = $catalog->capture($this->fixture->owner, $this->fixture->organization->id, $this->project->id,
                OrdinaryAssistantDocumentProjection::TOOL, $this->selection());
        } finally {
            if ($previous === null) { $request->attributes->remove(ApiQueryMetrics::REQUEST_ATTRIBUTE); }
            else { $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $previous); }
        }
        self::assertNotNull($observed);
        self::assertSame(1, $observed['reads']['policy_outer_fresh']['count'] ?? 0);
        self::assertSame(0, $observed['reads']['policy_nested_fresh_same']['count'] ?? 0);
        self::assertGreaterThanOrEqual(2, $observed['reads']['policy_reuse']['count'] ?? 0);
        self::assertNull($observed['identity']);
        self::assertSame($this->chunks[0]->content, $capture['preview']['data']['fragments'][0]['content']);
        $again = $catalog->capture($this->fixture->owner, $this->fixture->organization->id, $this->project->id,
            OrdinaryAssistantDocumentProjection::TOOL, $this->selection());
        self::assertSame($capture['proof'], $again['proof']);
        self::assertSame($capture['preview'], $again['preview']);
        self::assertSame($capture['corpus']->references(), $again['corpus']->references());
    }

    public function test_catalog_denies_real_unexposed_actor_without_reaching_the_reader(): void
    {
        $actor = Model::withoutEvents(fn () => $this->fixture->addMember());
        self::assertFalse(app(AIPermissionChecker::class)->canExposeTool($actor, OrdinaryAssistantDocumentProjection::TOOL));
        $reached = false;
        $catalog = $this->observedCatalog(OrdinaryAssistantDocumentProjection::TOOL, static function () use (&$reached): void { $reached = true; });
        $this->assertCatalogDenied(fn () => $catalog->capture($actor, $this->fixture->organization->id, $this->project->id,
            OrdinaryAssistantDocumentProjection::TOOL, $this->selection()));
        self::assertFalse($reached);
    }

    public function test_catalog_preserves_real_execute_denial_for_an_exposed_tool_with_foreign_project(): void
    {
        $foreign = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id, 'is_archived' => false]));
        $arguments = ['project_id' => $foreign->id, 'focus' => 'schedule', 'limit' => 1];
        $checker = app(AIPermissionChecker::class);
        self::assertTrue($checker->canExposeTool($this->fixture->owner, 'get_project_attention'));
        self::assertFalse($checker->canExecuteTool($this->fixture->owner, 'get_project_attention', $arguments));
        $reached = false;
        $catalog = $this->observedCatalog('get_project_attention', static function () use (&$reached): void { $reached = true; });
        $this->assertCatalogDenied(fn () => $catalog->capture($this->fixture->owner, $this->fixture->organization->id,
            $foreign->id, 'get_project_attention', $arguments));
        self::assertFalse($reached);
    }

    public function test_catalog_observes_current_role_revocation_after_a_warmed_capture(): void
    {
        $reached = 0;
        $catalog = $this->observedCatalog(OrdinaryAssistantDocumentProjection::TOOL, static function () use (&$reached): void { $reached++; });
        $catalog->capture($this->fixture->owner, $this->fixture->organization->id, $this->project->id,
            OrdinaryAssistantDocumentProjection::TOOL, $this->selection());
        UserRoleAssignment::query()->whereKey($this->fixture->ownerAssignment->id)->update(['is_active' => false]);
        $this->assertCatalogDenied(fn () => $catalog->capture($this->fixture->owner, $this->fixture->organization->id, $this->project->id,
            OrdinaryAssistantDocumentProjection::TOOL, $this->selection()));
        self::assertSame(1, $reached);
    }

    public function test_catalog_isolates_foreign_actor_organization_and_current_inactive_actor(): void
    {
        $reached = false;
        $catalog = $this->observedCatalog(OrdinaryAssistantDocumentProjection::TOOL, static function () use (&$reached): void { $reached = true; });
        $this->assertCatalogDenied(fn () => $catalog->capture($this->fixture->foreignOwner, $this->fixture->organization->id,
            $this->project->id, OrdinaryAssistantDocumentProjection::TOOL, $this->selection()));
        $this->assertCatalogDenied(fn () => $catalog->capture($this->fixture->owner, $this->fixture->foreignOrganization->id,
            $this->project->id, OrdinaryAssistantDocumentProjection::TOOL, $this->selection()));
        User::query()->whereKey($this->fixture->owner->id)->update(['is_active' => false]);
        $this->assertCatalogDenied(fn () => $catalog->capture($this->fixture->owner, $this->fixture->organization->id,
            $this->project->id, OrdinaryAssistantDocumentProjection::TOOL, $this->selection()));
        self::assertFalse($reached);
    }

    public function test_catalog_restores_policy_and_checker_scope_after_a_real_execute_query_throws(): void
    {
        $policy = app(AssistantDataAccessPolicy::class);
        $checker = app(AIPermissionChecker::class);
        $listening = false;
        $fired = false;
        $reached = false;
        DB::listen(static function (QueryExecuted $query) use (&$listening, &$fired): void {
            if (! $listening) { return; }
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96) as $frame) {
                if (($frame['class'] ?? '') === AIPermissionChecker::class && ($frame['function'] ?? '') === 'canExecuteTool') {
                    $fired = true;
                    $listening = false;
                    throw new RuntimeException('PRIVATE_PAIR_QUERY_FAILURE');
                }
            }
        });
        $catalog = $this->observedCatalog('get_project_attention', static function () use (&$reached): void { $reached = true; });
        $policy->withCurrentChecks($this->fixture->foreignOwner, $this->fixture->foreignOrganization->id, function () use ($policy, $checker, $catalog, &$listening, &$fired, &$reached): void {
            $expected = $this->privateValue($policy, 'batchIdentity');
            $failure = null;
            $listening = true;
            try {
                $catalog->captureForReview($this->fixture->owner, $this->fixture->owner->id, $this->fixture->organization->id,
                    $this->project->id, 'get_project_attention', ['project_id' => $this->project->id, 'focus' => 'schedule', 'limit' => 1]);
            } catch (RuntimeException $exception) { $failure = $exception; }
            finally { $listening = false; }
            self::assertTrue($fired);
            self::assertFalse($reached);
            self::assertInstanceOf(RuntimeException::class, $failure);
            self::assertSame('assistant_provider_failed', $failure->getMessage());
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString('PRIVATE_PAIR_QUERY_FAILURE', (string) $failure);
            self::assertSame([], array_filter($failure->getTrace(), static fn (array $frame): bool => array_key_exists('args', $frame)));
            self::assertSame($expected, $this->privateValue($policy, 'batchIdentity'));
            self::assertNull($this->privateValue($checker, 'batchAuthorization'));
        }, fresh: true);
        self::assertNull($this->privateValue($policy, 'batchIdentity'));
    }

    public function test_catalog_keeps_the_active_compiler_guard_even_inside_a_same_actor_batch(): void
    {
        $policy = app(AssistantDataAccessPolicy::class);
        $compiler = new ReflectionProperty($policy, 'aclCompiler');
        $reached = false;
        $catalog = $this->observedCatalog(OrdinaryAssistantDocumentProjection::TOOL, static function () use (&$reached): void { $reached = true; });
        $failure = null;
        $policy->withCurrentChecks($this->fixture->owner, $this->fixture->organization->id, function () use ($policy, $compiler, $catalog, &$failure): void {
            $expected = $this->privateValue($policy, 'batchIdentity');
            $compiler->setValue($policy, new AssistantAclQueryCompiler($this->fixture->owner->id, $this->fixture->organization->id));
            try {
                $catalog->capture($this->fixture->owner, $this->fixture->organization->id, $this->project->id,
                    OrdinaryAssistantDocumentProjection::TOOL, $this->selection());
            } catch (LogicException $exception) { $failure = $exception; }
            finally { $compiler->setValue($policy, null); }
            self::assertSame($expected, $this->privateValue($policy, 'batchIdentity'));
        }, fresh: true);
        self::assertInstanceOf(LogicException::class, $failure);
        self::assertSame('assistant_authorization_batch_during_query_compilation', $failure->getMessage());
        self::assertFalse($reached);
        self::assertNull($this->privateValue($policy, 'batchIdentity'));
    }

    private function observedCatalog(string $name, Closure $observer): OrdinaryAssistantSourceCatalog
    {
        $tool = app(AIToolRegistry::class)->getTool($name);
        self::assertNotNull($tool);
        $registry = new AIToolRegistry;
        $registry->registerFactory($name, static function () use ($tool, $observer) {
            $observer();
            return $tool;
        });
        return new OrdinaryAssistantSourceCatalog($registry, app(AIPermissionChecker::class), app(OrdinaryAssistantSourceProjection::class));
    }

    private function selection(): array
    {
        return ['project_id' => $this->project->id, 'document_id' => $this->document->id, 'chunk_ids' => [$this->chunks[0]->id]];
    }

    private function privateValue(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }

    private function assertCatalogDenied(callable $operation): void
    {
        $failure = null;
        try { $operation(); }
        catch (AssistantEgressDenied $exception) { $failure = $exception; }
        self::assertInstanceOf(AssistantEgressDenied::class, $failure);
        self::assertSame('access_denied', $failure->reason);
    }

}
