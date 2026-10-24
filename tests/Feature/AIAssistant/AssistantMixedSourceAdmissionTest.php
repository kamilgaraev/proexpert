<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressEnrollment;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectAttentionReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProductionScheduleReader;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\File;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Models\Credits\AICreditProviderUsage;
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

final class AssistantMixedSourceAdmissionTest extends TestCase
{
    public function test_lower_environment_profiler_measures_both_sources_on_every_freshness_pass(): void
    {
        config(['ordinary-assistant-profiler.enabled' => true]);
        [$cohort, $payload] = $this->enroll();
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->owner, (int) $cohort->organization_id, $payload['message'], $payload, null);
        self::assertSame([], $context->validateResults([]));
        self::assertSame([], $context->canonicalReferencesForPublication([]));
        $context->assertPublication([]);
        $snapshot = $context->profileSnapshot();
        self::assertSame(2, $snapshot['freshness_scope']['count']);
        self::assertSame(6, $snapshot['reviewer_user_membership']['count']);
        self::assertSame(2, $snapshot['approved_sql_source_capture']['count']);
        self::assertSame(2, $snapshot['approved_document_source_capture']['count']);
        self::assertGreaterThan(0, $snapshot['approved_sql_source_capture']['query_count']);
        self::assertGreaterThan(0, $snapshot['approved_document_source_capture']['query_count']);
        self::assertSame(3, $snapshot['projection/reference_guard']['count']);
        $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SENTINEL, $serialized);
        self::assertStringNotContainsString(self::ACCOUNT, $serialized);
        self::assertStringNotContainsString('source_version', $serialized);
        $context->clear();
        self::assertSame([], $context->profileSnapshot());
    }

    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private ScheduleTask $task;
    private User $operator;
    private AIAssistantDocument $document;
    private array $chunks = [];
    private OrdinaryAssistantEnrollmentService $enrollments;
    private const POLICY = 'ordinary-project-sql-document/1';
    private const THREE_POLICY = 'ordinary-project-sql-document/2';
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
        $schedule = ProjectSchedule::withoutEvents(fn () => ProjectSchedule::query()->create(['organization_id' => $this->fixture->organization->id,
            'project_id' => $this->project->id, 'created_by_user_id' => $this->fixture->owner->id, 'name' => 'Учебный график', 'status' => 'active',
            'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-20']));
        $this->task = ScheduleTask::withoutEvents(fn () => ScheduleTask::query()->create(['organization_id' => $this->fixture->organization->id,
            'schedule_id' => $schedule->id, 'created_by_user_id' => $this->fixture->owner->id, 'assigned_user_id' => $this->fixture->owner->id,
            'name' => 'Учебная задача', 'status' => 'not_started', 'task_type' => 'task', 'progress_percent' => 0,
            'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-20', 'planned_duration_days' => 19]));
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
        config(['ai-assistant-credits.profiles.short' => ['input_tokens' => 12288, 'output_tokens' => 1024, 'max_calls' => 3]]);
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

    public function test_platform_can_explicitly_assign_the_closed_mixed_policy_through_the_existing_api(): void
    {
        $this->withoutMiddleware();
        $this->actingAs($this->operator)->postJson('/api/v1/admin/ai-assistant/egress/cohorts', [
            'organization_id' => $this->fixture->organization->id, 'actor_id' => $this->fixture->owner->id,
            'project_id' => $this->project->id, 'tools' => ['get_project_attention', OrdinaryAssistantDocumentProjection::TOOL],
            'policy_version' => self::POLICY,
        ])->assertCreated()->assertJsonPath('data.policy_version', self::POLICY);
    }

    public static function selections(): iterable
    {
        foreach (OrdinaryAssistantSourceProjection::TOOLS as $sql) {
            yield $sql.' then document' => [$sql, ['sql', 'document']];
            yield 'document then '.$sql => [$sql, ['document', 'sql']];
        }
        yield 'none' => ['get_project_attention', []];
        yield 'only SQL' => ['get_project_attention', ['sql']];
        yield 'only document' => ['get_project_attention', ['document']];
        yield 'clarification after one read' => ['get_project_attention', ['sql'], true];
        yield 'clarification after two reads' => ['get_project_attention', ['sql', 'document'], true];
    }

    #[DataProvider('selections')]
    public function test_native_model_can_select_none_one_or_both_approved_sources_in_either_order(string $sql, array $sequence, bool $clarify = false): void
    {
        [$cohort, $payload, $manifest] = $this->enroll($sql);
        $previews = array_column($manifest['sources'], 'projection', 'tool');
        $calls = 0;
        $provider = $this->responses(function (array $input, array $options) use (&$calls, $sql, $sequence, $previews, $clarify): array {
            self::assertSame('auto', $options['tool_choice']);
            self::assertSame(3, $options['budget_limits']['max_calls']);
            self::assertEqualsCanonicalizing([$sql, OrdinaryAssistantDocumentProjection::TOOL], array_column($options['tools'], 'name'));
            foreach ($options['tools'] as $tool) {
                self::assertSame($tool['name'] === OrdinaryAssistantDocumentProjection::TOOL, isset($tool['parameters']['properties']['query']));
            }
            self::assertStringNotContainsString(self::ACCOUNT, json_encode([$input, $options], JSON_THROW_ON_ERROR));
            $outputs = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            self::assertCount($calls, $outputs);
            foreach ($outputs as $index => $output) {
                $name = $sequence[$index] === 'sql' ? $sql : OrdinaryAssistantDocumentProjection::TOOL;
                self::assertSame('call_mixed_'.$index, $output['call_id']);
                self::assertSame($previews[$name], json_decode($output['output'], true, 512, JSON_THROW_ON_ERROR));
            }
            $native = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call'));
            self::assertSame(count($native), count(array_unique(array_column($native, 'id'))));
            self::assertSame(count($native), count(array_unique(array_column($native, 'call_id'))));
            if ($calls < count($sequence)) {
                $name = $sequence[$calls] === 'sql' ? $sql : OrdinaryAssistantDocumentProjection::TOOL;
                $args = $name === $sql ? $this->sqlArgs($sql) : $this->documentArgs();
                return $this->nativeCall($name, $args, $calls++);
            }
            $calls++;
            return $this->answer($clarify || $sequence === [] ? '{"kind":"clarification","missing":"goal"}' : $this->modelText($sequence));
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertSame(count($sequence) + 1, $calls);
        self::assertSame($calls, AssistantRequest::query()->where('request_id', $response['request_id'])->sole()->calls_used);
        if ($clarify || $sequence === []) {
            self::assertSame(trans_message('ai_assistant.clarification_goal'), $response['message']['content']);
            self::assertTrue($response['message']['metadata']['needs_clarification']);
            self::assertSame([], $response['message']['metadata']['source_refs']);
            self::assertSame('unverified', $response['message']['metadata']['validation_status']);
            self::assertSame('insufficient_data', $response['message']['metadata']['outcome']);
        } else {
            self::assertSame($this->modelText($sequence), $response['message']['content']);
            $sqlType = $sql === 'get_project_attention' ? AssistantProjectAttentionReader::TYPE : AssistantProjectProductionScheduleReader::TYPE;
            self::assertSame(in_array('sql', $sequence, true), in_array($sqlType, array_column($response['message']['metadata']['source_refs'], 'entity_type'), true));
            self::assertSame(in_array('document', $sequence, true), in_array('assistant_document', array_column($response['message']['metadata']['source_refs'], 'entity_type'), true));
        }
        $stored = Message::query()->findOrFail($response['message']['id']);
        self::assertStringNotContainsString(self::SENTINEL, json_encode($stored->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public static function staleSources(): iterable
    {
        foreach (['sql', 'document'] as $source) {
            foreach ([0, 1, 2] as $phase) { yield $source.' phase '.$phase => [$source, $phase]; }
        }
    }

    #[DataProvider('staleSources')]
    public function test_every_approved_source_is_fresh_before_initial_or_next_send_and_publication(string $source, int $phase): void
    {
        [$cohort, $payload] = $this->enroll();
        if ($phase === 0) { $this->changeSource($source); }
        $calls = 0;
        $provider = $this->responses(function () use (&$calls, $source, $phase): array {
            $calls++;
            if (($phase === 1 && $calls === 1) || ($phase === 2 && $calls === 3)) { $this->changeSource($source); }
            if ($calls <= 2) {
                $name = $calls === 1 ? ($source === 'sql' ? OrdinaryAssistantDocumentProjection::TOOL : 'get_project_attention')
                    : ($source === 'sql' ? 'get_project_attention' : OrdinaryAssistantDocumentProjection::TOOL);
                return $this->nativeCall($name, $name === 'get_project_attention' ? $this->sqlArgs('get_project_attention') : $this->documentArgs(), $calls);
            }
            return $this->answer('{"kind":"clarification","missing":"focus"}');
        });
        try {
            AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
                app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
            self::fail('Changed approved source passed the whole-set boundary');
        } catch (AssistantEgressDenied) {
            self::assertSame($phase === 0 ? 0 : ($phase === 1 ? 1 : 3), $calls);
            self::assertFalse(Message::query()->where('role', 'assistant')->whereIn('conversation_id',
                \App\BusinessModules\Features\AIAssistant\Models\Conversation::query()->where('organization_id', $cohort->organization_id)->select('id'))->exists());
        }
    }

    public function test_tampered_result_and_unapproved_document_reference_are_not_authority(): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $context = app(OrdinaryAssistantScopedContext::class);
        $calls = 0;
        $provider = $this->responses(function () use (&$calls, $manifest, $context): array {
            if (++$calls === 1) { return $this->nativeCall('get_project_attention', $this->sqlArgs('get_project_attention'), 0); }
            $fake = $manifest['sources'][0]['projection'] + ['_tool_name' => 'get_project_attention'];
            $fake['data']['unapproved'] = 'not an approved output';
            try { $context->validateResults([$fake]); self::fail('Tampered output passed membership'); }
            catch (AssistantEgressDenied) { self::assertTrue(true); }
            $fakeRef = ['entity_type' => 'project', 'entity_id' => $this->project->id, 'project_id' => $this->project->id,
                'organization_id' => $this->fixture->organization->id, 'egress_projection_version' => OrdinaryAssistantDocumentProjection::VERSION];
            try { $context->assertPublication([$fakeRef]); self::fail('Result marker authorized an unapproved reference'); }
            catch (AssistantEgressDenied) { self::assertTrue(true); }
            return $this->answer();
        });
        AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, $context)
            ->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertSame(2, $calls);
    }

    public static function forbiddenCalls(): iterable
    {
        yield 'different SQL tuple' => ['get_project_attention', ['limit' => 2]];
        yield 'foreign SQL project' => ['get_project_attention', ['project_id' => 99999999]];
        yield 'different SQL tool' => ['get_project_production_schedule_summary', [], \DomainException::class];
        yield 'financial source' => ['get_live_project_financial_evidence', [], \DomainException::class];
        yield 'action source' => ['create_schedule_task', [], \DomainException::class];
        yield 'document extra field' => [OrdinaryAssistantDocumentProjection::TOOL, ['document_id' => 99999999]];
        yield 'document foreign project' => [OrdinaryAssistantDocumentProjection::TOOL, ['project_id' => 99999999]];
        yield 'document hybrid' => [OrdinaryAssistantDocumentProjection::TOOL, ['retrieval_mode' => 'hybrid']];
    }

    #[DataProvider('forbiddenCalls')]
    public function test_native_call_cannot_expand_the_joint_manifest(string $name, array $changes, string $expectedException = AssistantEgressDenied::class): void
    {
        [$cohort, $payload] = $this->enroll();
        $calls = 0;
        $provider = $this->responses(function () use (&$calls, $name, $changes): array {
            self::assertSame(0, $calls++);
            return $this->nativeCall($name, array_replace($name === OrdinaryAssistantDocumentProjection::TOOL ? $this->documentArgs() : $this->sqlArgs('get_project_attention'), $changes), 0);
        });
        $this->expectException($expectedException);
        try {
            AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
                app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        } catch (\DomainException|AssistantEgressDenied $exception) {
            self::assertSame($expectedException, $exception::class);
            self::assertSame($expectedException === \DomainException::class ? 'assistant_request_invalid' : 'assistant_egress_denied', $exception->getMessage());
            throw $exception;
        } finally { self::assertSame(1, $calls); }
    }

    public static function invalidSelections(): iterable
    {
        yield 'only SQL' => ['sql'];
        yield 'only document' => ['document'];
        yield 'duplicate SQL' => ['duplicate'];
        yield 'different project' => ['project'];
        yield 'unapproved chunk' => ['chunk'];
    }

    #[DataProvider('invalidSelections')]
    public function test_joint_preview_rejects_incomplete_or_duplicate_or_foreign_entries(string $kind): void
    {
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, ['get_project_attention', OrdinaryAssistantDocumentProjection::TOOL], self::POLICY);
        $payload = $this->payload();
        $sources = $this->sources('get_project_attention');
        $sources = match ($kind) { 'sql' => [$sources[0]], 'document' => [$sources[1]], 'duplicate' => [$sources[0], $sources[0]], default => $sources };
        if ($kind === 'project') { $sources[1]['arguments']['project_id'] = 99999999; }
        if ($kind === 'chunk') { $sources[1]['arguments']['chunk_ids'] = [99999999]; }
        $this->expectException(AssistantEgressDenied::class);
        $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload, $sources);
    }

    public function test_switching_a_cohort_to_mixed_does_not_reuse_an_old_approved_manifest(): void
    {
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, ['get_project_attention']);
        $payload = $this->payload();
        $manifest = $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload, [$this->sources('get_project_attention')[0]]);
        $this->enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        $oldGeneration = $cohort->generation;
        $this->enrollments->assign($this->operator, $cohort->organization_id, $this->fixture->owner,
            $this->project->id, ['get_project_attention', OrdinaryAssistantDocumentProjection::TOOL], self::POLICY);
        self::assertNotSame($oldGeneration, $cohort->refresh()->generation);
        self::assertSame('revoked', AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id'])->status);
        $provider = $this->responses(static function (): never { self::fail('Old enrollment reached transport'); });
        $this->expectException(AssistantEgressDenied::class);
        AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, app(OrdinaryAssistantScopedContext::class))
            ->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
    }

    public static function revokedAuthorities(): iterable
    {
        foreach (['generation', 'enrollment', 'expiry', 'permission'] as $kind) { yield $kind => [$kind]; }
    }

    #[DataProvider('revokedAuthorities')]
    public function test_authority_revocation_during_a_zero_read_answer_never_publishes(string $kind): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $calls = 0;
        $provider = $this->responses(function () use (&$calls, $kind, $cohort, $manifest): array {
            $calls++;
            match ($kind) {
                'generation' => $cohort->updateQuietly(['generation' => (string) Str::uuid()]),
                'enrollment' => $this->enrollments->revoke($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id']),
                'expiry' => AssistantEgressEnrollment::query()->whereKey($manifest['enrollment_id'])->update(['expires_at' => now()->subMinute()]),
                'permission' => UserRoleAssignment::query()->where('user_id', $this->fixture->owner->id)->update(['is_active' => false]),
            };
            return $this->answer('{"kind":"clarification","missing":"goal"}');
        });
        try {
            AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
                app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
            self::fail('Revoked authority published a zero-read answer');
        } catch (AssistantEgressDenied|AuthorizationException) { self::assertSame(1, $calls); }
    }

    public function test_platform_can_assign_the_explicit_three_source_policy_without_expanding_an_old_manifest(): void
    {
        [$old, $payload, $manifest] = $this->enroll();
        $generation = $old->generation;
        $this->withoutMiddleware();
        $this->actingAs($this->operator)->postJson('/api/v1/admin/ai-assistant/egress/cohorts', [
            'organization_id' => $old->organization_id, 'actor_id' => $this->fixture->owner->id,
            'project_id' => $this->project->id, 'tools' => array_column($this->threeSources(), 'tool'),
            'policy_version' => self::THREE_POLICY,
        ])->assertCreated()->assertJsonPath('data.policy_version', self::THREE_POLICY);
        self::assertNotSame($generation, $old->refresh()->generation);
        self::assertSame('revoked', AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id'])->status);
        $calls = 0;
        $provider = $this->responses(function () use (&$calls): never { $calls++; self::fail('An old two-source approval reached transport'); });
        try {
            AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $old->organization_id, app(OrdinaryAssistantScopedContext::class))
                ->ask($payload['message'], $old->organization_id, $this->fixture->owner, null, $payload);
            self::fail('Reassignment reused an old manifest');
        } catch (AssistantEgressDenied) { self::assertSame(0, $calls); }
    }

    public static function threeSourceSequences(): iterable
    {
        yield 'attention schedule document' => [['get_project_attention', 'get_project_production_schedule_summary', OrdinaryAssistantDocumentProjection::TOOL]];
        yield 'document schedule attention' => [[OrdinaryAssistantDocumentProjection::TOOL, 'get_project_production_schedule_summary', 'get_project_attention']];
        yield 'none' => [[]];
        yield 'document subset' => [[OrdinaryAssistantDocumentProjection::TOOL]];
        yield 'two SQL subset' => [OrdinaryAssistantSourceProjection::TOOLS];
    }

    #[DataProvider('threeSourceSequences')]
    public function test_three_source_native_model_controls_order_subset_and_final_with_existing_quote_four(array $sequence): void
    {
        [$cohort, $payload, $manifest] = $this->enrollThree();
        self::assertSame('normal', $payload['profile']);
        $previews = array_column($manifest['sources'], 'projection', 'tool');
        self::assertCount(3, $previews);
        $calls = 0;
        $final = 'Учебные источники позволяют обсудить наблюдения и условия проекта.';
        $provider = $this->responses(function (array $input, array $options) use (&$calls, $sequence, $previews, $final): array {
            self::assertSame('auto', $options['tool_choice']);
            self::assertSame(4, $options['budget_limits']['max_calls']);
            self::assertEqualsCanonicalizing(array_keys($previews), array_column($options['tools'], 'name'));
            self::assertStringNotContainsString(self::ACCOUNT, json_encode([$input, $options], JSON_THROW_ON_ERROR));
            $outputs = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            $native = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call'));
            self::assertCount($calls, $outputs);
            self::assertCount($calls, $native);
            foreach ($outputs as $index => $output) {
                $name = $sequence[$index];
                self::assertSame('fc_mixed_'.$index, $native[$index]['id']);
                self::assertSame('call_mixed_'.$index, $native[$index]['call_id']);
                self::assertSame($name, $native[$index]['name']);
                self::assertSame($this->modelArgs($name), json_decode($native[$index]['arguments'], true, 512, JSON_THROW_ON_ERROR));
                self::assertSame('call_mixed_'.$index, $output['call_id']);
                self::assertSame($previews[$name], json_decode($output['output'], true, 512, JSON_THROW_ON_ERROR));
            }
            self::assertSame(count($native), count(array_unique(array_column($native, 'id'))));
            self::assertSame(count($native), count(array_unique(array_column($native, 'call_id'))));
            if ($calls < count($sequence)) {
                $name = $sequence[$calls];
                return $this->nativeCall($name, $this->modelArgs($name), $calls++);
            }
            $calls++;
            return $this->answer($sequence === [] ? '{"kind":"clarification","missing":"goal"}' : $final);
        });
        $result = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertSame(count($sequence) + 1, $calls);
        $request = AssistantRequest::query()->where('request_id', $result['request_id'])->sole();
        self::assertSame(4, $request->max_calls);
        self::assertSame($calls, $request->calls_used);
        self::assertSame($calls, AICreditProviderUsage::query()->where('organization_id', $cohort->organization_id)->count());
        if ($sequence === []) {
            self::assertSame(trans_message('ai_assistant.clarification_goal'), $result['message']['content']);
            self::assertTrue($result['message']['metadata']['needs_clarification']);
            self::assertSame([], $result['message']['metadata']['source_refs']);
        } else {
            self::assertSame($final, $result['message']['content']);
            $types = array_column($result['message']['metadata']['source_refs'], 'entity_type');
            foreach (['get_project_attention' => AssistantProjectAttentionReader::TYPE,
                'get_project_production_schedule_summary' => AssistantProjectProductionScheduleReader::TYPE] as $sqlTool => $sqlType) {
                self::assertSame(in_array($sqlTool, $sequence, true), in_array($sqlType, $types, true));
            }
            self::assertSame(in_array(OrdinaryAssistantDocumentProjection::TOOL, $sequence, true), in_array('assistant_document', $types, true));
        }
    }

    public function test_three_quoted_calls_reject_terminal_function_call_without_a_fourth_send(): void
    {
        [$cohort, $payload] = $this->enrollThree('short');
        $sequence = [OrdinaryAssistantDocumentProjection::TOOL, ...OrdinaryAssistantSourceProjection::TOOLS];
        $context = app(OrdinaryAssistantScopedContext::class);
        $calls = 0;
        $executedQueries = array_fill_keys($sequence, 0);
        $instances = [];
        foreach ($sequence as $name) {
            $instances[$name] = app(\App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry::class)->getTool($name);
            self::assertNotNull($instances[$name]);
        }
        self::assertCount(3, array_unique(array_map(static fn ($tool): string => $tool::class, $instances)));
        $observing = true;
        DB::listen(static function () use (&$executedQueries, &$observing, $instances): void {
            if (! $observing) { return; }
            $handler = false;
            $capture = false;
            $executions = [];
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT, 64) as $frame) {
                $handler = $handler || (($frame['class'] ?? null) === \App\BusinessModules\Features\AIAssistant\Services\AIAssistantService::class
                    && ($frame['function'] ?? null) === 'handleToolCall');
                $capture = $capture || (($frame['class'] ?? null) === \App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceCatalog::class
                    && in_array($frame['function'] ?? null, ['capture', 'captureForReview'], true));
                if (($frame['function'] ?? null) === 'execute') {
                    $name = array_search($frame['object'] ?? null, $instances, true);
                    if (is_string($name)) { $executions[$name] = true; }
                }
            }
            if ($handler && ! $capture) {
                foreach (array_keys($executions) as $name) { $executedQueries[$name]++; }
            }
        });
        $provider = $this->responses(function (array $input, array $options) use (&$calls, $sequence): array {
            self::assertLessThan(3, $calls);
            self::assertSame(3, $options['budget_limits']['max_calls']);
            self::assertSame($calls === 2 ? 'none' : 'auto', $options['tool_choice']);
            $outputs = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            self::assertCount($calls, $outputs);
            foreach ($outputs as $index => $output) { self::assertSame('call_mixed_'.$index, $output['call_id']); }
            $name = $sequence[$calls];
            return $this->nativeCall($name, $this->modelArgs($name), $calls++);
        });
        $denied = null;
        try {
            AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, $context)
                ->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        } catch (AssistantEgressDenied $failure) {
            $denied = $failure;
        } finally {
            $observing = false;
        }
        self::assertInstanceOf(AssistantEgressDenied::class, $denied);
        self::assertSame('unsupported_purpose', $denied->reason);
        self::assertSame(3, $calls);
        self::assertGreaterThan(0, $executedQueries[$sequence[0]]);
        self::assertGreaterThan(0, $executedQueries[$sequence[1]]);
        self::assertSame(0, $executedQueries[$sequence[2]]);
        $request = AssistantRequest::query()->where('request_id', $payload['request_id'])->sole();
        self::assertSame('failed', $request->status);
        self::assertSame(3, $request->max_calls);
        self::assertSame(3, $request->calls_used);
        $usage = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->orderBy('id')->get();
        self::assertCount(3, $usage);
        self::assertSame([true, true, false], $usage->pluck('is_successful')->all());
        self::assertSame([1, 2, 3], $usage->map(static fn ($row): mixed => $row->metadata['attempt'] ?? null)->all());
        self::assertTrue($usage->every(static fn ($row): bool => ($row->metadata['provider_usage_available'] ?? null) === true
            && ($row->metadata['input_tokens'] ?? null) === 100 && ($row->metadata['output_tokens'] ?? null) === 20
            && $row->cost_micro_rub > 0));
        self::assertSame(3, $usage->pluck('usage_key')->unique()->count());
        self::assertSame(0, Message::query()->where('conversation_id', $request->conversation_id)->where('role', 'assistant')->count());
        $reservation = \App\Models\Credits\AICreditReservation::query()->findOrFail($request->reservation_id);
        self::assertSame('cancelled', $reservation->status);
        self::assertSame(0, $reservation->consumed_minor);
    }
    public static function threeUnusedSourceChanges(): iterable
    {
        yield 'document before first send' => ['document', 0];
        yield 'attention before next send' => ['attention', 1];
        yield 'schedule before publication' => ['schedule', 2];
    }

    #[DataProvider('threeUnusedSourceChanges')]
    public function test_three_source_whole_set_freshness_blocks_stale_unused_sources_and_keeps_usage(string $source, int $phase): void
    {
        [$cohort, $payload] = $this->enrollThree();
        if ($phase === 0) { $this->changeSource($source === 'document' ? 'document' : 'sql'); }
        $calls = 0;
        $provider = $this->responses(function (array $input) use (&$calls, $source, $phase): array {
            $calls++;
            if (($phase === 1 && $calls === 1) || ($phase === 2 && $calls === 2)) { $this->changeSource($source === 'document' ? 'document' : 'sql'); }
            if ($calls === 1) { return $this->nativeCall(OrdinaryAssistantDocumentProjection::TOOL, $this->documentArgs(), 0); }
            self::assertCount(1, array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            return $this->answer('{"kind":"clarification","missing":"goal"}');
        });
        try {
            AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
                app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
            self::fail('A stale unused source passed the three-source boundary');
        } catch (AssistantEgressDenied) {
            self::assertSame($phase, $calls);
            self::assertSame($calls, AICreditProviderUsage::query()->where('organization_id', $cohort->organization_id)->count());
            self::assertFalse(Message::query()->where('role', 'assistant')->whereIn('conversation_id',
                \App\BusinessModules\Features\AIAssistant\Models\Conversation::query()->where('organization_id', $cohort->organization_id)->select('id'))->exists());
        }
    }

    public static function invalidThreeSelections(): iterable
    {
        yield 'missing source' => ['missing'];
        yield 'foreign project' => ['project'];
        yield 'unapproved chunk' => ['chunk'];
    }

    #[DataProvider('invalidThreeSelections')]
    public function test_three_source_joint_preview_never_accepts_missing_or_foreign_entries(string $kind): void
    {
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, array_column($this->threeSources(), 'tool'), self::THREE_POLICY);
        $payload = $this->payload('normal');
        $sources = $this->threeSources();
        if ($kind === 'missing') { array_pop($sources); }
        if ($kind === 'project') { $sources[1]['arguments']['project_id'] = 99999999; }
        if ($kind === 'chunk') { $sources[2]['arguments']['chunk_ids'] = [99999999]; }
        $before = AssistantEgressEnrollment::query()->count();
        try {
            $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload, $sources);
            self::fail('An incomplete or foreign three-source selection passed preview');
        } catch (AssistantEgressDenied) { self::assertSame($before, AssistantEgressEnrollment::query()->count()); }
    }

    private function threeSources(): array
    {
        return [$this->sources('get_project_attention')[0], $this->sources('get_project_production_schedule_summary')[0],
            $this->sources('get_project_attention')[1]];
    }

    private function enrollThree(string $profile = 'normal'): array
    {
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, array_column($this->threeSources(), 'tool'), self::THREE_POLICY);
        $payload = $this->payload($profile);
        $manifest = $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload, $this->threeSources());
        self::assertCount(3, $manifest['sources']);
        self::assertSame(self::THREE_POLICY, $manifest['policy_version']);
        foreach (AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id'])->sources as $source) {
            self::assertSame($source['tool'] === OrdinaryAssistantDocumentProjection::TOOL
                ? OrdinaryAssistantDocumentProjection::VERSION : OrdinaryAssistantSourceProjection::VERSION, $source['projection_version']);
        }
        $this->enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        return [$cohort, $payload, $manifest];
    }

    private function modelArgs(string $tool): array
    {
        return $tool === OrdinaryAssistantDocumentProjection::TOOL ? $this->documentArgs() : $this->sqlArgs($tool);
    }

    private function modelText(array $sequence): string
    {
        return count($sequence) === 2 ? 'Учебный график и текст дают основание обсудить сроки работ.'
            : ($sequence === ['sql'] ? 'Учебный график требует внимания к срокам.' : 'Утверждённый текст описывает учебные условия.');
    }

    private function changeSource(string $source): void
    {
        if ($source === 'sql') { $this->task->updateQuietly(['name' => 'Изменённая учебная задача']); }
        else { AIAssistantDocumentUnit::query()->whereKey($this->chunks[0]->metadata['unit_id'])->update(['text' => 'changed']); }
    }

    private function payload(string $profile = 'short'): array
    {
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'Сопоставь учебное наблюдение по графику и утверждённый текст выбранного проекта.',
            'conversation_id' => null, 'profile' => $profile, 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        return $payload;
    }

    private function sqlArgs(string $sql): array
    {
        return $sql === 'get_project_attention' ? ['project_id' => $this->project->id, 'focus' => 'schedule', 'limit' => 3]
            : ['project_id' => $this->project->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'];
    }

    private function documentArgs(): array
    {
        return ['query' => 'бетон', 'project_id' => $this->project->id, 'source_types' => ['file_document'], 'limit' => 1, 'retrieval_mode' => 'lexical'];
    }

    private function sources(string $sql): array
    {
        return [['tool' => $sql, 'arguments' => $this->sqlArgs($sql)], ['tool' => OrdinaryAssistantDocumentProjection::TOOL,
            'arguments' => ['project_id' => $this->project->id, 'document_id' => $this->document->id, 'chunk_ids' => [$this->chunks[0]->id]]]];
    }

    private function enroll(string $sql = 'get_project_attention'): array
    {
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, [$sql, OrdinaryAssistantDocumentProjection::TOOL], self::POLICY);
        $payload = $this->payload();
        $manifest = $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload, $this->sources($sql));
        $this->enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        return [$cohort, $payload, $manifest];
    }

    private function responses(callable $callback): MixedAdmissionResponsesProvider
    {
        $provider = $this->createMock(MixedAdmissionResponsesProvider::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->method('responses')->willReturnCallback($callback);
        return $provider;
    }

    private function nativeCall(string $name, array $arguments, int $index): array
    {
        $call = ['type' => 'function_call', 'id' => 'fc_mixed_'.$index, 'call_id' => 'call_mixed_'.$index,
            'status' => 'completed', 'name' => $name, 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)];
        return ['content' => '', 'output' => [$call], 'function_calls' => [$call], 'input_tokens' => 100, 'output_tokens' => 20,
            'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
    }

    private function answer(string $text = 'Учебный график требует внимания к срокам.'): array
    {
        return ['content' => $text, 'output' => [], 'function_calls' => [], 'input_tokens' => 100, 'output_tokens' => 20,
            'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
    }
}

interface MixedAdmissionResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
