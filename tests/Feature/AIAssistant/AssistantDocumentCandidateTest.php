<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantDocumentCandidateRequest;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressCohort;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressEnrollment;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileMetadata;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Http\Middleware\InterfaceMiddleware;
use App\Domain\Authorization\Http\Middleware\InterfaceRequestProvenance;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use App\Http\Middleware\ResetJwtRequestState;
use App\Services\FileService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;
use Throwable;

final class AssistantDocumentCandidateTest extends TestCase
{
    private const PATH = '/api/v1/admin/ai-assistant/egress/document-candidates';
    private const PRIVATE_TEXT = 'C7_PRIVATE_BODY_NOT_METADATA';
    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private AssistantEgressCohort $cohort;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 12:00:00');
        Queue::fake();
        Http::preventStrayRequests();
        config(['cache.default' => 'array', 'logging.default' => 'stderr', 'ai-assistant.enabled' => true]);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        $this->cohort = AssistantEgressCohort::query()->create(['id' => (string) Str::uuid(), 'generation' => (string) Str::uuid(),
            'organization_id' => $this->fixture->organization->id, 'actor_id' => $this->fixture->owner->id,
            'project_id' => $this->project->id, 'assigned_by' => $this->fixture->owner->id, 'status' => 'active',
            'policy_version' => OrdinaryAssistantDocumentProjection::VERSION, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna',
            'endpoint' => 'https://fixture.invalid/v1/responses', 'allowed_tools' => [OrdinaryAssistantDocumentProjection::TOOL], 'expires_at' => now()->addHour()]);
        $provider = $this->createMock(RagEmbeddingProviderInterface::class);
        $provider->expects(self::never())->method('embed');
        $this->app->instance(RagEmbeddingProviderInterface::class, $provider);
        $storage = Mockery::mock(FileService::class);
        $storage->shouldNotReceive('readCurrentBounded');
        $this->app->instance(FileService::class, $storage);
        $this->actingAs($this->fixture->owner);
        $this->withoutMiddleware();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_document_and_chunk_pages_select_only_metadata_without_side_effects(): void
    {
        $document = $this->document();
        $chunks = $this->chunks($document, 4);
        $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        $this->document($foreignProject);
        $foreignOrganizationProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id, 'is_archived' => false]));
        $this->document($foreignOrganizationProject);
        $hydrated = [];
        Event::listen('eloquent.retrieved: '.AIAssistantDocument::class, static function (AIAssistantDocument $row) use (&$hydrated): void {
            $hydrated[] = array_keys($row->getAttributes());
        });
        $projections = [];
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$projections, &$statements): void {
            $statements[] = $query->sql;
            if (preg_match('/\Aselect (.*?) from /is', $query->sql, $match) && ! str_contains(strtolower($match[1]), 'count(')
                && preg_match('/from "(?:ai_assistant_documents|ai_rag_chunks)"/i', $query->sql)
                && preg_match('/\blimit (?:1|3|10)\z/i', $query->sql)) {
                $projections[] = $match[1];
            }
        });
        $before = $this->rowCounts();
        $response = $this->getJson(self::PATH)->assertOk();
        $this->assertUncacheable($response);
        $page = $response->json('data');
        self::assertSame(['kind', 'coverage_scope', 'preview_required', 'items', 'next_after_id', 'scan_limited', 'continuation_unknown'], array_keys($page));
        self::assertSame('documents', $page['kind']);
        self::assertSame('candidate_page', $page['coverage_scope']);
        self::assertTrue($page['preview_required']);
        self::assertSame([$document->id], array_column($page['items'], 'document_id'));
        self::assertSame(['document_id', 'filename', 'status', 'coverage_status', 'processed_at'], array_keys($page['items'][0]));
        self::assertNull($page['next_after_id']);
        self::assertFalse($page['scan_limited']);
        self::assertFalse($page['continuation_unknown']);
        $page = $this->getJson(self::PATH.'?document_id='.$document->id)->assertOk()->json('data');
        self::assertSame(['kind', 'coverage_scope', 'preview_required', 'items', 'next_after_id', 'scan_limited', 'continuation_unknown', 'document_id'], array_keys($page));
        self::assertSame(array_column(array_slice($chunks, 0, 3), 'id'), array_column($page['items'], 'chunk_id'));
        self::assertSame(['chunk_id', 'chunk_index', 'unit_type', 'unit_index'], array_keys($page['items'][0]));
        self::assertSame($chunks[2]->id, $page['next_after_id']);
        self::assertTrue($page['scan_limited']);
        self::assertFalse($page['continuation_unknown']);
        $last = $this->getJson(self::PATH.'?document_id='.$document->id.'&after_id='.$page['next_after_id'])->assertOk()->json('data');
        self::assertSame([$chunks[3]->id], array_column($last['items'], 'chunk_id'));
        self::assertNull($last['next_after_id']);
        self::assertNotEmpty($hydrated);
        foreach ($hydrated as $attributes) {
            self::assertSame([], array_diff($attributes, ['id', 'parent_entity_type', 'parent_entity_id', 'filename', 'status', 'coverage_status', 'processed_at']));
        }
        self::assertCount(5, $projections);
        foreach ($projections as $projection) {
            self::assertDoesNotMatchRegularExpression('/\*|extracted_text|content|embedding|metadata|storage_path|last_error/i', $projection);
        }
        self::assertStringNotContainsString(self::PRIVATE_TEXT, json_encode([$page, $last], JSON_THROW_ON_ERROR));
        self::assertSame($before, $this->rowCounts());
        self::assertNotEmpty($statements);
        foreach ($statements as $statement) {
            self::assertDoesNotMatchRegularExpression('/\A\s*(?:insert|update|delete|merge|create|alter|drop|truncate|grant|revoke|refresh)\b|\A\s*with\b[\s\S]*\b(?:insert\s+into|update|delete\s+from|merge\s+into)\b/i', $statement);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_document_keyset_is_bounded_at_ten_without_a_hidden_extra_row(): void
    {
        $documents = [];
        for ($i = 0; $i < 11; $i++) { $documents[] = $this->document(); }
        $hydrated = [];
        Event::listen('eloquent.retrieved: '.AIAssistantDocument::class, static function (AIAssistantDocument $row) use (&$hydrated): void { $hydrated[] = (int) $row->id; });
        $page = $this->getJson(self::PATH)->assertOk()->json('data');
        self::assertSame(array_column(array_slice($documents, 0, 10), 'id'), array_column($page['items'], 'document_id'));
        self::assertCount(10, $hydrated);
        self::assertSame($documents[9]->id, $page['next_after_id']);
        self::assertTrue($page['scan_limited']);
        $page = $this->getJson(self::PATH.'?after_id='.$page['next_after_id'])->assertOk()->json('data');
        self::assertSame([$documents[10]->id], array_column($page['items'], 'document_id'));
        self::assertNull($page['next_after_id']);
    }

    public function test_real_admin_interface_preserves_query_only_candidate_contract(): void
    {
        $document = $this->document();
        $chunks = $this->chunks($document, 2);
        $router = app('router');
        $route = $router->getRoutes()->getByName('admin.ai-assistant.egress.document-candidates');
        self::assertNotNull($route);
        $middleware = $router->gatherRouteMiddleware($route);
        self::assertContains(InterfaceMiddleware::class.':admin', $middleware);
        $disabled = array_values(array_unique(array_filter(array_map(static fn (string $entry): string => explode(':', $entry, 2)[0], $middleware),
            static fn (string $class): bool => $class !== InterfaceMiddleware::class)));
        $disabled[] = ResetJwtRequestState::class;
        $this->withMiddleware()->withoutMiddleware($disabled);
        $this->actingAs($this->fixture->owner, 'api_admin');
        $seenActors = [];
        $this->app->resolving(AssistantDocumentCandidateRequest::class, static function (AssistantDocumentCandidateRequest $request) use (&$seenActors): void {
            $seenActors[] = (int) ($request->user()?->getAuthIdentifier() ?? 0);
        });

        $response = $this->getJson(self::PATH);
        self::assertSame([(int) $this->fixture->owner->id], $seenActors);
        $response->assertOk();
        $this->assertUncacheable($response);
        self::assertSame([$document->id], array_column($response->json('data.items'), 'document_id'));
        self::assertFalse(InterfaceRequestProvenance::isTrustedServerDerived(app('request')));
        $this->getJson(self::PATH.'?document_id='.$document->id.'&after_id='.$chunks[0]->id)->assertOk()
            ->assertJsonPath('data.items.0.chunk_id', $chunks[1]->id);

        foreach (['current_interface=admin', 'current_interface=mobile', 'actor_id='.$this->fixture->owner->id, 'project_id='.$this->project->id] as $query) {
            $this->getJson(self::PATH.'?'.$query)->assertUnprocessable();
        }
        foreach (['[]', '{}', " \n{}\t"] as $body) {
            $this->call('GET', self::PATH, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertOk();
        }
        foreach (['{"current_interface":"admin"}', '{"document_id":'.$document->id.'}', '{"unknown":"value"}', 'null', str_repeat(' ', 65)] as $body) {
            $this->call('GET', self::PATH, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertUnprocessable();
        }
        $this->call('GET', self::PATH, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'],
            'current_interface=admin')->assertUnprocessable();
        $this->call('GET', self::PATH.'?document_id='.$document->id, [], [], ['document_id' => UploadedFile::fake()->create('selector.txt', 1, 'text/plain')],
            ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_ACCEPT' => 'application/json'])->assertUnprocessable();
        self::assertFalse(InterfaceRequestProvenance::isTrustedServerDerived(app('request')));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_restricted_legal_document_without_a_grant_is_already_excluded_by_sql(): void
    {
        [$document] = $this->legalDocument();
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertFalse($policy->accessibleDocuments($this->fixture->owner, $this->fixture->organization->id,
            AIAssistantDocument::query()->whereKey($document->id))->exists());
        self::assertFalse($policy->canReadEntityContent($this->fixture->owner, $this->fixture->organization->id, 'legal_document_version', $document->parent_entity_id));
        $page = $this->getJson(self::PATH)->assertOk()->json('data');
        self::assertSame([], $page['items']);
        self::assertNull($page['next_after_id']);
        self::assertFalse($page['scan_limited']);
        self::assertFalse($page['continuation_unknown']);
        $this->getJson(self::PATH.'?document_id='.$document->id)->assertForbidden();
    }

    public function test_real_legal_grant_revocation_after_sql_is_denied_before_serialization_and_cursor(): void
    {
        [$document, $legal] = $this->legalDocument();
        $grant = $this->legalGrant($legal);
        $page = $this->getJson(self::PATH)->assertOk()->json('data');
        self::assertSame([$document->id], array_column($page['items'], 'document_id'));
        self::assertFalse($page['continuation_unknown']);
        $revocations = 0;
        $revokedBy = (int) $this->fixture->owner->id;
        Event::listen('eloquent.retrieved: '.AIAssistantDocument::class, static function (AIAssistantDocument $row) use ($document, $grant, $revokedBy, &$revocations): void {
            if ($row->id === $document->id && $revocations === 0) {
                $grant->update(['revoked_at' => now(), 'revoked_by_user_id' => $revokedBy, 'revocation_reason' => 'Synthetic selector ACL race']);
                $revocations++;
            }
        });
        $page = $this->getJson(self::PATH)->assertOk()->json('data');
        self::assertSame(1, $revocations);
        self::assertSame([], $page['items']);
        self::assertNull($page['next_after_id']);
        self::assertTrue($page['scan_limited']);
        self::assertTrue($page['continuation_unknown']);
        $this->getJson(self::PATH.'?document_id='.$document->id)->assertForbidden();
        $replacementGrant = $this->legalGrant($legal);
        self::assertNotSame($grant->id, $replacementGrant->id);
        self::assertNotNull($grant->fresh()->revoked_at);
        $page = $this->getJson(self::PATH)->assertOk()->json('data');
        self::assertSame([$document->id], array_column($page['items'], 'document_id'));
        self::assertFalse($page['continuation_unknown']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_denied_legal_tail_cannot_become_a_cursor_or_trigger_replenishment(): void
    {
        $visible = $this->document();
        $grants = [];
        for ($i = 0; $i < 10; $i++) {
            [$document, $legal] = $this->legalDocument();
            $grants[$document->id] = $this->legalGrant($legal);
        }
        $hydrated = 0;
        $revokedBy = (int) $this->fixture->owner->id;
        Event::listen('eloquent.retrieved: '.AIAssistantDocument::class, static function (AIAssistantDocument $row) use (&$hydrated, $grants, $revokedBy): void {
            $hydrated++;
            if (isset($grants[$row->id])) {
                $grants[$row->id]->update(['revoked_at' => now(), 'revoked_by_user_id' => $revokedBy, 'revocation_reason' => 'Synthetic selector ACL race']);
            }
        });
        $page = $this->getJson(self::PATH)->assertOk()->json('data');
        self::assertSame([$visible->id], array_column($page['items'], 'document_id'));
        self::assertSame($visible->id, $page['next_after_id']);
        self::assertTrue($page['continuation_unknown']);
        self::assertSame(10, $hydrated);
        $page = $this->getJson(self::PATH.'?after_id='.$page['next_after_id'])->assertOk()->json('data');
        self::assertSame([], $page['items']);
        self::assertNull($page['next_after_id']);
        self::assertTrue($page['scan_limited']);
        self::assertTrue($page['continuation_unknown']);
    }

    #[DataProvider('invalidParameters')]
    public function test_only_positive_metadata_selectors_are_accepted(array $parameters): void
    {
        $this->getJson(self::PATH.'?'.http_build_query($parameters))->assertUnprocessable();
    }

    public static function invalidParameters(): array
    {
        return [[['project_id' => 1]], [['organization_id' => 1]], [['actor_id' => 1]], [['cohort_id' => 'forged']],
            [['query' => 'text']], [['limit' => 100]], [['document_id' => 0]], [['document_id' => -1]],
            [['document_id' => '9223372036854775808']], [['after_id' => 0]], [['after_id' => ['1']]], [['document_id' => '1.5']]];
    }

    public function test_missing_foreign_project_and_deleted_file_documents_are_denied(): void
    {
        $this->assertUncacheable($this->getJson(self::PATH.'?document_id=9223372036854775807')->assertForbidden());
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        $document = $this->document($other);
        $this->getJson(self::PATH.'?document_id='.$document->id)->assertForbidden();
        $document = $this->document();
        File::query()->findOrFail($document->file_id)->deleteQuietly();
        $this->getJson(self::PATH.'?document_id='.$document->id)->assertForbidden();
    }

    public function test_revoked_expired_and_sql_only_cohorts_never_fall_back(): void
    {
        $this->document();
        $this->cohort->update(['status' => 'revoked']);
        $this->getJson(self::PATH)->assertForbidden();
        $this->cohort->update(['status' => 'active', 'expires_at' => now()->subSecond()]);
        $this->getJson(self::PATH)->assertForbidden();
        $this->cohort->update(['expires_at' => now()->addHour(), 'policy_version' => OrdinaryAssistantSourceProjection::VERSION,
            'allowed_tools' => OrdinaryAssistantSourceProjection::TOOLS]);
        $this->getJson(self::PATH)->assertForbidden();
        $this->cohort->update(['policy_version' => OrdinaryAssistantDocumentProjection::VERSION,
            'allowed_tools' => [OrdinaryAssistantDocumentProjection::TOOL], 'actor_id' => $this->fixture->member->id]);
        $this->getJson(self::PATH)->assertForbidden();
    }

    public function test_current_membership_and_egress_permission_are_required_even_with_a_matching_cohort(): void
    {
        $this->cohort->update(['actor_id' => $this->fixture->member->id]);
        $this->actingAs($this->fixture->member);
        $this->getJson(self::PATH)->assertForbidden();
        $this->actingAs($this->fixture->owner);
        $this->cohort->update(['actor_id' => $this->fixture->owner->id]);
        $this->fixture->owner->organizations()->updateExistingPivot($this->fixture->organization->id, ['is_active' => false]);
        $this->getJson(self::PATH)->assertForbidden();
    }

    public function test_delegated_manager_reads_authorized_document_metadata_without_settings_or_finance(): void
    {
        [$actor, , $document] = $this->delegatedDocumentReader();
        $chunks = $this->chunks($document, 2);
        $this->assertDelegatedSourceAccess($actor, $document, true);

        $response = $this->getJson(self::PATH)->assertOk();
        self::assertSame([$document->id], array_column($response->json('data.items'), 'document_id'));
        $this->assertUncacheable($response);
        $response = $this->getJson(self::PATH.'?document_id='.$document->id)->assertOk();
        self::assertSame(array_map(static fn (RagChunk $chunk): int => (int) $chunk->id, $chunks), array_column($response->json('data.items'), 'chunk_id'));
        self::assertStringNotContainsString(self::PRIVATE_TEXT, $response->getContent());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_current_source_access_without_egress_permission_does_not_allow_candidates(): void
    {
        [$actor, , $document] = $this->delegatedDocumentReader(false);
        $this->assertDelegatedSourceAccess($actor, $document, false);
        $this->getJson(self::PATH)->assertForbidden();
        $this->getJson(self::PATH.'?document_id='.$document->id)->assertForbidden();
    }

    public function test_delegated_manager_permission_revocation_is_current_despite_unchanged_source_access(): void
    {
        [$actor, $role, $document] = $this->delegatedDocumentReader();
        $this->assertDelegatedSourceAccess($actor, $document, true);
        $this->getJson(self::PATH)->assertOk()->assertJsonPath('data.items.0.document_id', $document->id);
        $permissions = $role->module_permissions;
        $permissions['ai-assistant'] = ['ai_assistant.chat'];
        $role->update(['module_permissions' => $permissions]);

        $this->assertDelegatedSourceAccess($actor, $document, false);
        $this->getJson(self::PATH)->assertForbidden();
        $this->getJson(self::PATH.'?document_id='.$document->id)->assertForbidden();
    }

    public function test_chunk_metadata_requires_matching_unit_source_organization_and_project(): void
    {
        $document = $this->document();
        $chunks = $this->chunks($document, 4);
        $chunks[1]->update(['project_id' => null]);
        $chunks[2]->update(['metadata' => ['unit_id' => 9223372036854775807, 'unit_checksum' => hash('sha256', self::PRIVATE_TEXT)]]);
        RagSource::query()->whereKey($chunks[3]->source_id)->update(['entity_id' => '9223372036854775807']);
        $page = $this->getJson(self::PATH.'?document_id='.$document->id)->assertOk()->json('data');
        self::assertSame([$chunks[0]->id], array_column($page['items'], 'chunk_id'));
        self::assertNull($page['next_after_id']);
    }

    public function test_unexpected_failure_reports_only_a_sanitized_exception_and_returns_generic_500(): void
    {
        $this->document();
        Exceptions::fake();
        Event::listen('eloquent.retrieved: '.AIAssistantDocument::class, static function (): never {
            throw new RuntimeException(self::PRIVATE_TEXT, 0, new RuntimeException(self::PRIVATE_TEXT));
        });
        $previous = ini_get('zend.exception_ignore_args');
        self::assertNotFalse(ini_set('zend.exception_ignore_args', '0'));
        try {
            $response = $this->getJson(self::PATH)->assertStatus(500);
        } finally {
            if ($previous !== false) { ini_set('zend.exception_ignore_args', $previous); }
        }
        $this->assertUncacheable($response);
        self::assertStringNotContainsString(self::PRIVATE_TEXT, $response->getContent());
        $reported = Exceptions::reported();
        self::assertCount(1, $reported);
        self::assertInstanceOf(Throwable::class, $reported[0]);
        self::assertSame('assistant_provider_failed', $reported[0]->getMessage());
        self::assertNull($reported[0]->getPrevious());
        self::assertStringNotContainsString(self::PRIVATE_TEXT, (string) $reported[0]);
        foreach ($reported[0]->getTrace() as $frame) { self::assertArrayNotHasKey('args', $frame); }
    }

    private function document(?Project $project = null, ?File $file = null, string $parentType = 'project', ?int $parentId = null): AIAssistantDocument
    {
        $project ??= $this->project;
        $file ??= Model::withoutEvents(fn () => File::query()->create(['organization_id' => $project->organization_id,
            'user_id' => $this->fixture->owner->id, 'fileable_type' => $project->getMorphClass(), 'fileable_id' => $project->id,
            'name' => 'Документ.txt', 'original_name' => 'Документ.txt', 'path' => 'private/'.Str::uuid().'.txt', 'disk' => 's3', 'mime_type' => 'text/plain', 'size' => 100]));

        return Model::withoutEvents(fn () => AIAssistantDocument::query()->create(['organization_id' => $project->organization_id,
            'project_id' => $project->id, 'file_id' => $file->id, 'parent_entity_type' => $parentType, 'parent_entity_id' => (string) ($parentId ?? $project->id),
            'storage_path' => $file->path, 'filename' => $file->name, 'mime_type' => $file->mime_type, 'checksum' => hash('sha256', self::PRIVATE_TEXT),
            'size_bytes' => 100, 'status' => 'ready', 'coverage_status' => 'full', 'processed_at' => now(), 'extracted_text' => self::PRIVATE_TEXT,
            'metadata' => ['private' => self::PRIVATE_TEXT], 'last_error' => self::PRIVATE_TEXT]));
    }

    private function chunks(AIAssistantDocument $document, int $count): array
    {
        $chunks = [];
        for ($i = 0; $i < $count; $i++) {
            $unit = AIAssistantDocumentUnit::query()->create(['document_id' => $document->id, 'unit_type' => 'page', 'unit_index' => $i,
                'text' => self::PRIVATE_TEXT, 'checksum' => hash('sha256', self::PRIVATE_TEXT), 'provenance' => ['private' => self::PRIVATE_TEXT]]);
            $metadata = ['unit_id' => $unit->id, 'unit_checksum' => $unit->checksum, 'private' => self::PRIVATE_TEXT];
            $source = RagSource::query()->create(['organization_id' => $document->organization_id, 'project_id' => $document->project_id,
                'identity_part_key' => (string) $unit->id, 'source_type' => 'file_document', 'entity_type' => 'assistant_document', 'entity_id' => (string) $document->id,
                'title' => self::PRIVATE_TEXT, 'checksum' => hash('sha256', (string) $unit->id), 'source_version' => now()->toISOString(), 'metadata' => $metadata, 'indexed_at' => now()]);
            $chunks[] = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $document->organization_id, 'project_id' => $document->project_id,
                'chunk_index' => 0, 'content' => self::PRIVATE_TEXT, 'content_hash' => hash('sha256', self::PRIVATE_TEXT), 'metadata' => $metadata]);
        }
        return $chunks;
    }

    private function legalDocument(): array
    {
        AuthorizationContext::getProjectContext((int) $this->project->id, (int) $this->fixture->organization->id);
        self::assertTrue(app(AuthorizationService::class)->canCurrent($this->fixture->owner, 'legal_archive.view',
            ['organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id]));
        $legal = Model::withoutEvents(fn () => LegalArchiveDocument::query()->create(['organization_id' => $this->fixture->organization->id,
            'primary_project_id' => $this->project->id, 'title' => 'Закрытый договор', 'document_type' => 'nda', 'status' => 'draft', 'lifecycle_status' => 'draft',
            'approval_status' => 'not_started', 'signature_status' => 'unsigned', 'confidentiality_level' => 'restricted', 'lock_version' => 0]));
        $legalFile = Model::withoutEvents(fn () => LegalArchiveDocumentFile::query()->create(['organization_id' => $this->fixture->organization->id,
            'document_id' => $legal->id, 'role' => 'primary', 'title' => 'Основной документ', 'sort_order' => 0, 'is_required' => true]));
        $version = Model::withoutEvents(fn () => LegalArchiveDocumentVersion::query()->create(['organization_id' => $this->fixture->organization->id,
            'document_id' => $legal->id, 'document_file_id' => $legalFile->id, 'uploaded_by_user_id' => $this->fixture->owner->id,
            'version_number' => '1', 'is_current' => true, 'status' => 'uploaded', 'processing_status' => 'ready',
            'file_path' => 'org-'.$this->fixture->organization->id.'/legal-archive/files/'.$legalFile->id.'/versions/'.Str::uuid().'.pdf',
            'original_filename' => 'Договор.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 100, 'content_hash' => hash('sha256', self::PRIVATE_TEXT)]));
        $source = (array) AssistantLegalNativeFileMetadata::sourceQuery('legal_document_version', $this->fixture->organization->id)
            ->where('native_source.id', $version->id)->first(AssistantLegalNativeFileMetadata::sourceColumns('legal_document_version'));
        $fields = AssistantLegalNativeFileMetadata::versionData('legal_document_version', $source);
        $name = basename($version->file_path);
        $file = Model::withoutEvents(fn () => File::query()->create(['organization_id' => $this->fixture->organization->id,
            'user_id' => $this->fixture->owner->id, 'fileable_type' => $version->getMorphClass(), 'fileable_id' => $version->id,
            'name' => $name, 'original_name' => $name, 'path' => $version->file_path, 'disk' => 's3', 'mime_type' => 'application/pdf', 'size' => 100,
            'additional_info' => ['assistant_native_source' => AssistantLegalNativeFileMetadata::SOURCE, 'native_entity_type' => 'legal_document_version',
                'native_source_sha256' => $version->content_hash, 'native_source_version' => AssistantLegalNativeFileMetadata::fingerprint($fields), 'native_source_fields' => $fields]]));

        return [$this->document(file: $file, parentType: 'legal_document_version', parentId: (int) $version->id), $legal];
    }

    private function rowCounts(): array
    {
        return [AIAssistantDocument::query()->count(), AIAssistantDocumentUnit::query()->count(), RagSource::query()->count(), RagChunk::query()->count(),
            AssistantEgressCohort::query()->count(), AssistantEgressEnrollment::query()->count()];
    }

    private function delegatedDocumentReader(bool $egressPermission = true): array
    {
        [$document, $legal] = $this->legalDocument();
        $assistant = ['ai_assistant.chat'];
        if ($egressPermission) {
            $assistant[] = 'ai_assistant.egress.manage';
        }
        $actor = Model::withoutEvents(fn () => $this->fixture->addMember([
            'ai-assistant' => $assistant, 'project-management' => ['projects.view'],
        ], ['legal_archive.view', 'legal_archive.files.view']));
        $this->project->users()->attach($actor->id, ['role' => 'member', 'assigned_by_user_id' => $this->fixture->owner->id,
            'is_active' => true, 'assigned_at' => now()]);
        $assignment = UserRoleAssignment::query()->where('user_id', $actor->id)->firstOrFail();
        $role = OrganizationCustomRole::query()->findOrFail($assignment->custom_role_id);
        $this->legalGrant($legal, $actor);
        $this->cohort->update(['actor_id' => $actor->id]);
        $this->actingAs($actor);

        return [$actor, $role, $document];
    }

    private function assertDelegatedSourceAccess(User $actor, AIAssistantDocument $document, bool $egressPermission): void
    {
        $organizationId = (int) $this->fixture->organization->id;
        $authorization = app(AuthorizationService::class);
        self::assertSame($egressPermission, $authorization->canCurrent($actor, 'ai_assistant.egress.manage', ['organization_id' => $organizationId]));
        self::assertFalse($authorization->canCurrent($actor, 'ai_assistant.settings.manage', ['organization_id' => $organizationId]));
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertFalse($policy->canReadDomain($actor, $organizationId, 'finance'));
        self::assertTrue($policy->canReadEntity($actor, $organizationId, 'project', $this->project->id));
        self::assertTrue($authorization->canCurrent($actor, 'legal_archive.view', ['organization_id' => $organizationId]));
        self::assertTrue($authorization->canCurrent($actor, 'legal_archive.files.view', ['organization_id' => $organizationId]));
        self::assertTrue($policy->canReadEntityContent($actor, $organizationId, $document->parent_entity_type, $document->parent_entity_id));
    }

    private function legalGrant(LegalArchiveDocument $document, ?User $subject = null): LegalDocumentAccessGrant
    {
        return LegalDocumentAccessGrant::query()->create(['organization_id' => $this->fixture->organization->id, 'document_id' => $document->id,
            'subject_kind' => 'internal_user', 'subject_organization_id' => $this->fixture->organization->id,
            'subject_user_id' => ($subject ?? $this->fixture->owner)->id, 'abilities' => ['view'], 'granted_by_user_id' => $this->fixture->owner->id]);
    }

    private function assertUncacheable(TestResponse $response): void
    {
        $header = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $header);
        self::assertStringContainsString('no-store', $header);
    }
}
