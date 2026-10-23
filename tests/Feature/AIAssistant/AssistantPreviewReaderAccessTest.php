<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressEnrollment;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\LegalArchive\Access\LegalDocumentAccessService;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\AssistantEgressFixtureService;
use Tests\TestCase;

final class AssistantPreviewReaderAccessTest extends TestCase
{
    private const PRIVATE_TEXT = 'B4_PRIVATE_LEGAL_FRAGMENT учебные обязательства поставки.';

    private AssistantRealAuthorizationFixture $fixture;
    private User $reviewer;
    private User $operator;
    private Project $project;
    private LegalArchiveDocument $legal;
    private AIAssistantDocument $document;
    private RagChunk $chunk;
    private OrdinaryAssistantEnrollmentService $enrollments;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('MOST_VERIFY_NATIVE_SELECT') === '1') {
            self::assertGreaterThanOrEqual(80500, PHP_VERSION_ID);
            self::assertTrue(config('database.connections.pgsql.native_select_parameters'));
            $factory = $this->app->make('db.factory');
            self::assertInstanceOf(\App\Database\NativeParameterConnectionFactory::class, $factory);
            $connection = DB::connection();
            self::assertInstanceOf(\App\Database\NativeParameterPostgresConnection::class, $connection);
            $pdo = $connection->getPdo();
            self::assertContains($pdo::class, [\PDO::class, \Pdo\Pgsql::class]);
            self::assertSame('pgsql', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
            self::assertFalse($pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES));
            $original = $connection->getEventDispatcher();
            $events = new \Illuminate\Events\Dispatcher($this->app);
            $nativeStatements = [];
            $events->listen(\Illuminate\Database\Events\StatementPrepared::class,
                static function (\Illuminate\Database\Events\StatementPrepared $event) use ($connection, &$nativeStatements): void {
                    $nativeStatements[] = $event->connection === $connection && $connection->usesNativeParameterStatement($event->statement);
                });
            $connection->setEventDispatcher($events);
            try {
                self::assertSame(1, (int) $connection->selectOne('select ?::integer as b4_native_probe', [1])->b4_native_probe);
                self::assertSame([true], $nativeStatements);
            } finally {
                if ($original === null) { $connection->unsetEventDispatcher(); }
                else { $connection->setEventDispatcher($original); }
            }
            fwrite(STDERR, 'B4_NATIVE_SELECT_ATTESTED '.json_encode(['php' => PHP_VERSION, 'native_select_parameters' => true,
                'factory' => $factory::class, 'connection' => $connection::class, 'pdo' => $pdo::class,
                'driver' => 'pgsql', 'emulated_prepares' => false, 'native_prepared_statement' => true], JSON_THROW_ON_ERROR).PHP_EOL);
        }
        Carbon::setTestNow('2026-10-23 12:00:00');
        Queue::fake();
        Http::preventStrayRequests();
        config(['cache.default' => 'array', 'logging.default' => 'stderr', 'ai-assistant.enabled' => true,
            'ai-assistant-credits.enforce' => true, 'ai-assistant.llm.provider' => 'timeweb',
            'ai-assistant.llm.timeweb.base_uri' => 'https://fixture.invalid/v1',
            'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->reviewer = $this->fixture->administrator;
        $this->reviewer->organizations()->updateExistingPivot($this->fixture->organization->id, ['is_owner' => true]);
        UserRoleAssignment::query()->create(['user_id' => $this->reviewer->id, 'role_slug' => 'organization_owner',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM, 'context_id' => AuthorizationContext::getOrganizationContext($this->fixture->organization->id)->id,
            'assigned_by' => $this->fixture->owner->id, 'is_active' => true]);
        $this->fixture->memberRole->update(['system_permissions' => ['legal_archive.view', 'legal_archive.files.view']]);
        foreach ([$this->fixture->owner, $this->reviewer] as $principal) {
            UserRoleAssignment::query()->create(['user_id' => $principal->id, 'role_slug' => $this->fixture->memberRole->slug,
                'role_type' => UserRoleAssignment::TYPE_CUSTOM, 'custom_role_id' => $this->fixture->memberRole->id,
                'context_id' => AuthorizationContext::getOrganizationContext($this->fixture->organization->id)->id,
                'assigned_by' => $this->fixture->owner->id, 'is_active' => true]);
        }
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        AuthorizationContext::getProjectContext($this->project->id, $this->fixture->organization->id);
        $this->project->users()->syncWithoutDetaching([$this->reviewer->id => ['role' => 'member', 'is_active' => true,
            'assigned_by_user_id' => $this->fixture->owner->id, 'assigned_at' => now()]]);
        $this->operator = User::factory()->create(['is_active' => true]);
        UserRoleAssignment::query()->create(['user_id' => $this->operator->id, 'role_slug' => 'system_admin',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM, 'context_id' => AuthorizationContext::getSystemContext()->id,
            'assigned_by' => $this->operator->id, 'is_active' => true]);
        $this->legal = LegalArchiveDocument::withoutEvents(fn () => LegalArchiveDocument::query()->create([
            'organization_id' => $this->fixture->organization->id, 'primary_project_id' => $this->project->id,
            'title' => 'Учебный закрытый документ', 'document_type' => 'nda', 'status' => 'draft', 'lifecycle_status' => 'draft',
            'approval_status' => 'not_started', 'signature_status' => 'unsigned', 'confidentiality_level' => 'restricted', 'lock_version' => 0]));
        $legalFile = LegalArchiveDocumentFile::withoutEvents(fn () => LegalArchiveDocumentFile::query()->create([
            'organization_id' => $this->fixture->organization->id, 'document_id' => $this->legal->id,
            'role' => 'primary', 'title' => 'Учебный файл', 'sort_order' => 0, 'is_required' => true]));
        $body = "%PDF-1.4\nB4 synthetic source\n%%EOF";
        $version = LegalArchiveDocumentVersion::withoutEvents(fn () => LegalArchiveDocumentVersion::query()->create([
            'organization_id' => $this->fixture->organization->id, 'document_id' => $this->legal->id, 'document_file_id' => $legalFile->id,
            'uploaded_by_user_id' => $this->fixture->owner->id, 'version_number' => '1', 'is_current' => true,
            'status' => 'uploaded', 'processing_status' => 'ready',
            'file_path' => 'org-'.$this->fixture->organization->id.'/legal-archive/files/'.$legalFile->id.'/versions/'.Str::uuid().'.pdf',
            'original_filename' => 'Учебный.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => strlen($body), 'content_hash' => hash('sha256', $body)]));
        $this->grant($this->fixture->owner);
        self::assertTrue(app(AuthorizationService::class)->forCurrentChecks(true)->can($this->fixture->owner, 'legal_archive.view',
            ['organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id]));
        $embedding = $this->createMock(RagEmbeddingProviderInterface::class);
        $embedding->method('provider')->willReturn('offline');
        $embedding->method('model')->willReturn('offline');
        $embedding->method('dimensions')->willReturn(256);
        $embedding->expects(self::never())->method('embed');
        $this->app->instance(RagEmbeddingProviderInterface::class, $embedding);
        $storage = Mockery::mock(FileService::class)->makePartial();
        $storage->shouldReceive('readCurrentBounded')->andReturnUsing(static function () use ($body): mixed {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $body);
            rewind($stream);
            return $stream;
        });
        $this->app->instance(FileService::class, $storage);
        $file = app(AssistantLegalNativeFileAdapter::class)->map($this->fixture->owner, $this->fixture->organization->id, 'legal_document_version', $version->id);
        $this->document = app(AssistantDocumentService::class)->registerFile($file);
        $this->document->update(['status' => AIAssistantDocument::STATUS_READY, 'coverage_status' => 'full',
            'processed_at' => now(), 'extracted_text' => self::PRIVATE_TEXT, 'metadata' => ['page_count' => 1]]);
        $unit = AIAssistantDocumentUnit::query()->create(['document_id' => $this->document->id, 'unit_type' => 'page', 'unit_index' => 0,
            'text' => self::PRIVATE_TEXT, 'checksum' => hash('sha256', self::PRIVATE_TEXT), 'provenance' => ['page' => 1]]);
        $indexer = new RagIndexer($embedding, new RagSourceRegistry([]));
        $data = FileRagSource::chunkForUnit($this->document, $unit);
        $source = RagSource::query()->create($indexer->coverageIdentity($data) + ['title' => $data->title,
            'source_version' => now()->toAtomString(), 'metadata' => $data->metadata, 'indexed_at' => now()]);
        $this->chunk = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $data->organizationId,
            'project_id' => $data->projectId, 'chunk_index' => 0, 'content' => self::PRIVATE_TEXT,
            'content_hash' => hash('sha256', self::PRIVATE_TEXT), 'metadata' => $data->metadata + ['chunk_index' => 0, 'chunk_count' => 1]]);
        self::assertTrue(app(RagIndexer::class)->matchesLexicalSource($source, $data));
        $this->enrollments = app(OrdinaryAssistantEnrollmentService::class);
        $this->actingAs($this->fixture->owner);
        app(AICreditService::class)->grant($this->fixture->organization, 100000, 'purchase', null, 'preview-reader-'.Str::uuid());
        DB::commit();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (DB::transactionLevel() === 0) { DB::beginTransaction(); }
        parent::tearDown();
    }

    private function grant(User $reader): LegalDocumentAccessGrant
    {
        return LegalDocumentAccessGrant::query()->create(['organization_id' => $this->fixture->organization->id,
            'document_id' => $this->legal->id, 'subject_kind' => 'internal_user', 'subject_organization_id' => $this->fixture->organization->id,
            'subject_user_id' => $reader->id, 'abilities' => ['view'], 'granted_by_user_id' => $this->operator->id]);
    }

    private function selection(): array
    {
        return ['project_id' => $this->project->id, 'document_id' => $this->document->id, 'chunk_ids' => [$this->chunk->id]];
    }

    private function input(): array
    {
        $input = ['request_id' => (string) Str::uuid(), 'message' => 'Объясни учебные обязательства документа.',
            'conversation_id' => null, 'profile' => 'normal', 'allow_actions' => false];
        $input['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $input)['quote_id'];
        return $input;
    }

    public function test_a_reviewer_cannot_preview_actor_readable_restricted_legal_fragments_without_a_grant(): void
    {
        $this->enrollments->assertOrganizationManager((int) $this->reviewer->id, $this->fixture->organization->id);
        self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadEntity($this->reviewer, $this->fixture->organization->id, 'project', $this->project->id));
        $legalAccess = new LegalDocumentAccessService(app(AuthorizationService::class)->forCurrentChecks(true));
        $legalAccess->authorize($this->fixture->owner, $this->legal, 'view');
        try { $legalAccess->authorize($this->reviewer, $this->legal, 'view'); self::fail('Reviewer must not have the document grant'); }
        catch (AuthorizationException) { self::assertTrue(true); }
        self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadEntity($this->fixture->owner, $this->fixture->organization->id, 'assistant_document', $this->document->id));
        self::assertFalse(app(AssistantDataAccessPolicy::class)->canReadEntity($this->reviewer, $this->fixture->organization->id, 'assistant_document', $this->document->id));
        $captured = app(OrdinaryAssistantSourceCatalog::class)->capture($this->fixture->owner, $this->fixture->organization->id,
            $this->project->id, OrdinaryAssistantDocumentProjection::TOOL, $this->selection());
        self::assertSame(self::PRIVATE_TEXT, $captured['preview']['data']['fragments'][0]['content']);
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, [OrdinaryAssistantDocumentProjection::TOOL], OrdinaryAssistantDocumentProjection::VERSION);
        $input = $this->input();
        $count = AssistantEgressEnrollment::query()->count();
        $failure = null;
        try {
            $preview = $this->enrollments->preview($this->reviewer, $cohort->organization_id, $cohort->id, $input['quote_id'], $input,
                [['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => $this->selection()]]);
            self::assertSame(self::PRIVATE_TEXT, $preview['sources'][0]['projection']['data']['fragments'][0]['content']);
            self::assertSame($count + 1, AssistantEgressEnrollment::query()->count());
        } catch (AssistantEgressDenied $denied) { $failure = $denied; }
        self::assertInstanceOf(AssistantEgressDenied::class, $failure);
        self::assertSame($count, AssistantEgressEnrollment::query()->count());
        $this->withoutMiddleware();
        $this->actingAs($this->reviewer);
        \Illuminate\Support\Facades\Exceptions::fake();
        $body = $input;
        unset($body['request_id'], $body['quote_id']);
        $this->postJson('/api/v1/admin/ai-assistant/egress/enrollments/preview', ['cohort_id' => $cohort->id,
            'quote_id' => $input['quote_id'], 'input' => $body,
            'sources' => [['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => $this->selection()]]])->assertForbidden();
        \Illuminate\Support\Facades\Exceptions::assertNothingReported();
        self::assertSame($count, AssistantEgressEnrollment::query()->count());
        Http::assertNothingSent();
    }

    private function preview(): array
    {
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, [OrdinaryAssistantDocumentProjection::TOOL], OrdinaryAssistantDocumentProjection::VERSION);
        $input = $this->input();
        $preview = $this->enrollments->preview($this->reviewer, $cohort->organization_id, $cohort->id, $input['quote_id'], $input,
            [['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => $this->selection()]]);
        return [$cohort, $input, $preview];
    }

    private function confirm(array $preview): void
    {
        $this->enrollments->confirm($this->reviewer, $this->fixture->organization->id, $preview['enrollment_id'],
            $preview['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
    }

    private function denied(callable $operation): AssistantEgressDenied
    {
        try { $operation(); self::fail('Protected source operation must be denied'); }
        catch (AssistantEgressDenied $denied) { return $denied; }
    }

    public function test_both_readers_can_use_the_native_loop_and_actor_corpus_is_preserved(): void
    {
        $this->grant($this->reviewer);
        (new LegalDocumentAccessService(app(AuthorizationService::class)->forCurrentChecks(true)))->authorize($this->reviewer, $this->legal, 'view');
        $catalog = app(OrdinaryAssistantSourceCatalog::class);
        foreach ([$this->fixture->owner->id, $this->reviewer->id] as $readerId) {
            $capture = $catalog->captureForReview($this->fixture->owner, $readerId, $this->fixture->organization->id,
                $this->project->id, OrdinaryAssistantDocumentProjection::TOOL, $this->selection());
            $capture['corpus']->assertActor($this->fixture->owner, $this->fixture->organization->id);
            self::assertSame(self::PRIVATE_TEXT, $capture['preview']['data']['fragments'][0]['content']);
        }
        [$cohort, $input, $preview] = $this->preview();
        $this->confirm($preview);
        $calls = 0;
        $provider = $this->responses(function (array $messages) use (&$calls, $preview): array {
            if (++$calls === 1) { return $this->nativeCall(); }
            $outputs = array_values(array_filter($messages, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            self::assertCount(1, $outputs);
            self::assertSame($preview['sources'][0]['projection'], json_decode($outputs[0]['output'], true, 512, JSON_THROW_ON_ERROR));
            return $this->answer();
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, app(OrdinaryAssistantScopedContext::class))
            ->ask($input['message'], $cohort->organization_id, $this->fixture->owner, null, $input);
        self::assertSame(2, $calls);
        self::assertNotEmpty($response['message']['metadata']['source_refs']);
        Http::assertNothingSent();
    }

    public function test_reviewer_revocation_is_checked_at_confirm_each_wire_and_publication(): void
    {
        foreach (['confirm', 'first_wire', 'next_wire', 'publication'] as $stage) {
            $grant = $this->grant($this->reviewer);
            [$cohort, $input, $preview] = $this->preview();
            if ($stage === 'confirm') {
                $grant->update(['revoked_at' => now(), 'revoked_by_user_id' => $this->operator->id, 'revocation_reason' => 'Учебный отзыв доступа']);
                $this->denied(fn () => $this->confirm($preview));
                self::assertSame('pending_review', AssistantEgressEnrollment::query()->findOrFail($preview['enrollment_id'])->status);
                continue;
            }
            $this->confirm($preview);
            if ($stage === 'first_wire') { $grant->update(['revoked_at' => now(), 'revoked_by_user_id' => $this->operator->id, 'revocation_reason' => 'Учебный отзыв доступа']); }
            $calls = 0;
            $provider = $this->responses(function () use (&$calls, $stage, $grant): array {
                $calls++;
                if (($stage === 'next_wire' && $calls === 1) || ($stage === 'publication' && $calls === 2)) {
                    $grant->update(['revoked_at' => now(), 'revoked_by_user_id' => $this->operator->id, 'revocation_reason' => 'Учебный отзыв доступа']);
                }
                return $calls === 1 ? $this->nativeCall() : $this->answer();
            });
            $failure = $this->denied(fn () => AssistantEgressFixtureService::forScope($provider, $this->fixture->owner,
                $cohort->organization_id, app(OrdinaryAssistantScopedContext::class))
                ->ask($input['message'], $cohort->organization_id, $this->fixture->owner, null, $input));
            self::assertNull($failure->getPrevious());
            self::assertSame(match ($stage) { 'first_wire' => 0, 'next_wire' => 1, default => 2 }, $calls);
            $request = AssistantRequest::query()->where('request_id', $input['request_id'])->sole();
            self::assertSame(0, Message::query()->where('conversation_id', $request->conversation_id)->where('role', 'assistant')->count());
            $usage = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->get();
            self::assertCount($calls, $usage);
            self::assertSame($calls * 100, $usage->sum(static fn (AICreditProviderUsage $row): int => $row->metadata['input_tokens']));
            self::assertSame($calls * 20, $usage->sum(static fn (AICreditProviderUsage $row): int => $row->metadata['output_tokens']));
        }
        Http::assertNothingSent();
    }

    public function test_review_principal_is_fresh_and_sql_literal_projection_is_reader_accessible(): void
    {
        $catalog = app(OrdinaryAssistantSourceCatalog::class);
        foreach ([null, 0, PHP_INT_MAX] as $id) {
            self::assertSame('access_denied', $this->denied(fn () => $catalog->freshReviewer($id, $this->fixture->organization->id))->reason);
        }
        foreach (['inactive', 'foreign', 'membership'] as $change) {
            match ($change) {
                'inactive' => $this->reviewer->updateQuietly(['is_active' => false]),
                'foreign' => $this->reviewer->updateQuietly(['current_organization_id' => null]),
                default => $this->reviewer->organizations()->updateExistingPivot($this->fixture->organization->id, ['is_active' => false]),
            };
            $this->denied(fn () => $catalog->freshReviewer($this->reviewer->id, $this->fixture->organization->id));
            $this->reviewer->updateQuietly(['is_active' => true, 'current_organization_id' => $this->fixture->organization->id]);
            $this->reviewer->organizations()->updateExistingPivot($this->fixture->organization->id, ['is_active' => true]);
        }
        foreach (['get_project_attention' => ['project_id' => $this->project->id, 'focus' => 'quality', 'limit' => 2],
            'get_project_production_schedule_summary' => ['project_id' => $this->project->id]] as $tool => $arguments) {
            $actor = $catalog->capture($this->fixture->owner, $this->fixture->organization->id, $this->project->id, $tool, $arguments);
            foreach ([$this->fixture->owner->id, $this->reviewer->id] as $readerId) {
                $review = $catalog->captureForReview($this->fixture->owner, $readerId, $this->fixture->organization->id,
                    $this->project->id, $tool, $arguments);
                self::assertSame($actor['preview'], $review['preview']);
                self::assertSame($actor['proof'], $review['proof']);
            }
        }
    }

    public function test_unexpected_review_dependency_failure_escapes_the_actual_controller_sanitized_as_500(): void
    {
        $this->grant($this->reviewer);
        $this->withoutMiddleware();
        $this->actingAs($this->reviewer);
        foreach (['users', 'ai_rag_chunks'] as $table) {
            $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
                $this->project->id, [OrdinaryAssistantDocumentProjection::TOOL], OrdinaryAssistantDocumentProjection::VERSION);
            $input = $this->input();
            $quoteId = $input['quote_id'];
            unset($input['request_id'], $input['quote_id']);
            \Illuminate\Support\Facades\Exceptions::fake();
            $connection = DB::connection();
            $original = $connection->getEventDispatcher();
            $events = new \Illuminate\Events\Dispatcher($this->app);
            $faults = 0;
            $events->listen(\Illuminate\Database\Events\QueryExecuted::class, static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$faults, $table): void {
                if (($table === 'users' && str_starts_with($query->sql, 'select * from "users"')
                        && str_contains($query->sql, '"is_active"') && str_contains($query->sql, '"current_organization_id"'))
                    || ($table === 'ai_rag_chunks' && str_contains($query->sql, 'from "ai_rag_chunks"'))) {
                    $faults++;
                    self::privateFailure(self::PRIVATE_TEXT);
                }
            });
            $connection->setEventDispatcher($events);
            $previous = ini_set('zend.exception_ignore_args', '0');
            $count = AssistantEgressEnrollment::query()->count();
            try {
                $response = $this->postJson('/api/v1/admin/ai-assistant/egress/enrollments/preview', ['cohort_id' => $cohort->id,
                    'quote_id' => $quoteId, 'input' => $input,
                    'sources' => [['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => $this->selection()]]]);
            } finally {
                ini_set('zend.exception_ignore_args', $previous);
                if ($original === null) { $connection->unsetEventDispatcher(); }
                else { $connection->setEventDispatcher($original); }
            }
            self::assertSame(1, $faults);
            $response->assertStatus(500);
            self::assertStringNotContainsString(self::PRIVATE_TEXT, $response->getContent());
            \Illuminate\Support\Facades\Exceptions::assertReportedCount(1);
            $failure = \Illuminate\Support\Facades\Exceptions::reported()[0];
            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertSame('assistant_provider_failed', $failure->getMessage());
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString(self::PRIVATE_TEXT, (string) $failure);
            self::assertStringNotContainsString(self::PRIVATE_TEXT, $failure->getTraceAsString());
            self::assertStringNotContainsString(self::PRIVATE_TEXT, json_encode($failure->getTrace(), JSON_THROW_ON_ERROR));
            self::assertSame([], array_filter($failure->getTrace(), static fn (array $frame): bool => array_key_exists('args', $frame)));
            self::assertSame($count, AssistantEgressEnrollment::query()->count());
        }
        Http::assertNothingSent();
    }

    private static function privateFailure(string $private): never
    {
        throw new \RuntimeException($private, 0, new \RuntimeException($private));
    }

    private function responses(callable $callback): PreviewReaderResponsesProvider
    {
        $provider = $this->createMock(PreviewReaderResponsesProvider::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->method('responses')->willReturnCallback($callback);
        return $provider;
    }

    private function nativeCall(): array
    {
        $call = ['type' => 'function_call', 'id' => 'fc_document', 'call_id' => 'call_document', 'status' => 'completed',
            'name' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => json_encode(['query' => 'обязательства',
                'project_id' => $this->project->id, 'source_types' => ['file_document'], 'limit' => 1, 'retrieval_mode' => 'lexical'], JSON_THROW_ON_ERROR)];
        return ['content' => '', 'output' => [$call], 'function_calls' => [$call], 'input_tokens' => 100, 'output_tokens' => 20,
            'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
    }

    private function answer(): array
    {
        return ['content' => 'В учебном документе описаны обязательства поставки.', 'output' => [], 'function_calls' => [],
            'input_tokens' => 100, 'output_tokens' => 20, 'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
    }
}

interface PreviewReaderResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
