<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentSettings;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentBudgetService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentFileResolver;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentOcrClient;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentOcrRenderer;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\DocumentTextExtractor;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Jobs\RegisterAssistantEntityFile;
use App\Jobs\ScanAssistantDocuments;
use App\Models\Credits\AICreditReservation;
use App\Models\File;
use App\Models\Estimate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Credits\AICreditService;
use App\Services\Logging\LoggingService;
use App\Services\Project\UserProjectAccessService;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

final class AssistantDocumentIngestionTest extends TestCase
{
    use RefreshDatabase, MockeryPHPUnitIntegration;

    private bool $allowed = true;
    private bool $modulesEnabled = true;
    private array $deniedPermissions = [];
    private array $deniedProjects = [];
    private AssistantDataAccessPolicy $policy;
    private AssistantDocumentService $documents;
    private AICreditService $credits;
    private Organization $organization;
    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('s3');
        config(['cache.default' => 'array', 'ai-assistant-credits.enforce' => true,
            'ai-assistant.llm.timeweb.api_key' => 'test-key', 'ai-assistant.llm.timeweb.base_uri' => 'https://example.test/v1']);
        $this->organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $this->owner = User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]);
        $this->organization->users()->attach($this->owner->id, ['is_owner' => true, 'is_active' => true]);
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id]));
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(fn (): bool => $this->allowed);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $actor, string $permission): bool => $this->allowed && ! in_array($permission, $this->deniedPermissions, true));
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $projects = Mockery::mock(UserProjectAccessService::class);
        $projects->shouldReceive('queryAccessibleProjects')->andReturnUsing(fn () => Project::query()->where('organization_id', $this->organization->id)->whereNotIn('id', $this->deniedProjects));
        $projects->shouldReceive('canAccessProject')->andReturn(true);
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturnUsing(fn () => $this->modulesEnabled
            ? collect(['ai-assistant', 'project-management', 'payments', 'budget-estimates', 'contract-management'])->map(static fn (string $slug): object => (object) ['slug' => $slug]) : collect());
        $policy = new AssistantDataAccessPolicy($authorization, $projects, $modules);
        $this->policy = $policy;
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $this->credits = new AICreditService;
        $this->credits->grant($this->organization, 1_000_000, 'purchase', null, 'documents-test-grant');
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('readCurrentBounded')->andReturnUsing(static fn (string $path) => Storage::disk('s3')->readStream($path));
        $this->documents = new AssistantDocumentService($files, new DocumentTextExtractor,
            $policy, new AssistantDocumentOcrClient(new AssistantDocumentOcrRenderer, $this->credits), $this->credits, new AssistantDocumentFileResolver($policy));
    }

    public function test_hash_reuse_stays_inside_the_same_parent(): void
    {
        $firstFile = $this->file('one.txt', 'same content');
        $first = $this->documents->process((int) $this->documents->registerFile($firstFile)->id);
        $repeat = $this->documents->registerFile($firstFile);
        $sameParent = $this->documents->registerFile($this->file('two.txt', 'same content'));
        $otherProject = Project::factory()->create(['organization_id' => $this->organization->id]);
        $differentParent = $this->documents->registerFile($this->file('three.txt', 'same content', $otherProject));
        self::assertSame($first->id, $repeat->id);
        self::assertSame('ready', $sameParent->status);
        self::assertSame($first->id, $sameParent->metadata['reused_from_document_id']);
        self::assertSame('queued', $differentParent->status);
        self::assertSame(0, AIAssistantDocumentUnit::query()->where('document_id', $differentParent->id)->count());
    }

    public function test_registration_cannot_attach_an_unrelated_storage_path(): void
    {
        $otherProject = Project::factory()->create(['organization_id' => $this->organization->id]);
        $file = $this->file('private.txt', 'private', $otherProject);
        $this->expectException(RuntimeException::class);
        $this->documents->register($this->owner, $this->organization->id, 'project', (string) $this->project->id, $file->path, 'forged.txt', 'text/plain');
    }

    public function test_document_id_from_another_organization_is_not_readable(): void
    {
        $document = $this->documents->registerFile($this->file('one.txt', 'content'));
        $otherOrganization = Organization::factory()->create();
        $otherOwner = User::factory()->create(['current_organization_id' => $otherOrganization->id, 'is_active' => true]);
        $otherOrganization->users()->attach($otherOwner->id, ['is_owner' => true, 'is_active' => true]);
        $this->expectException(RuntimeException::class);
        $this->documents->status($otherOwner, $otherOrganization->id, $document);
    }

    public function test_estimate_pdf_content_and_ocr_are_denied_without_financial_permission(): void
    {
        $estimate = \App\Models\Estimate::withoutEvents(fn () => \App\Models\Estimate::query()->create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id,
            'number' => 'assistant-financial-'.$this->organization->id, 'name' => 'Смета', 'estimate_date' => today(),
        ]));
        $path = 'org-'.$this->organization->id.'/assistant-test/estimate.pdf';
        $content = $this->pdfContent(1);
        Storage::disk('s3')->put($path, $content);
        $file = File::withoutEvents(fn () => File::query()->create(['organization_id' => $this->organization->id,
            'user_id' => $this->owner->id, 'fileable_type' => $estimate->getMorphClass(), 'fileable_id' => $estimate->id,
            'name' => 'estimate.pdf', 'original_name' => 'estimate.pdf', 'path' => $path,
            'mime_type' => 'application/pdf', 'size' => strlen($content), 'disk' => 's3']));
        $document = $this->documents->process((int) $this->documents->registerFile($file)->id);
        $quote = $this->documents->quoteOcr($this->owner, $this->organization->id, $document);
        $this->documents->confirmOcr($this->owner, $this->organization->id, $document, $quote['quote_id'], $quote['request_id']);
        $this->deniedPermissions = ['budget-estimates.finance.view'];
        self::assertTrue($this->policy->canReadEntity($this->owner, $this->organization->id, 'estimate', $estimate->id));
        $coverage = (new AssistantDocumentCoverageService($this->policy, $this->documents))->coverage($this->organization->id, $this->owner);
        self::assertTrue($coverage['can_manage_document_settings']);
        self::assertSame(0, $coverage['document_coverage']['total']);
        self::assertSame(0, $coverage['archive_scan']['expected_file_count']);
        self::assertSame(0, $coverage['archive_scan']['last_file_id']);
        Http::fake();
        foreach (['register', 'status', 'quoteOcr', 'processOcr'] as $operation) {
            try {
                if ($operation === 'register') $this->documents->register($this->owner, $this->organization->id, 'estimate', $estimate->id, $path, 'estimate.pdf', 'application/pdf');
                elseif ($operation === 'status') $this->documents->status($this->owner, $this->organization->id, $document);
                elseif ($operation === 'processOcr') $this->documents->processOcr((int) $document->id);
                else $this->documents->quoteOcr($this->owner, $this->organization->id, $document);
                self::fail('Financial document content must remain inaccessible.');
            } catch (AccessDeniedHttpException) {
            }
        }
        self::assertSame(1, AICreditReservation::query()->count());
        $this->documents->failOcr((int) $document->id);
        self::assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        Http::assertNothingSent();
    }

    public function test_inactive_module_blocks_document_content_and_ocr_quote(): void
    {
        $document = $this->imageDocument();
        $this->modulesEnabled = false;
        foreach (['status', 'quoteOcr'] as $operation) {
            try {
                $this->documents->{$operation}($this->owner, $this->organization->id, $document);
                self::fail('Inactive source modules must deny attached content.');
            } catch (AccessDeniedHttpException) {
            }
        }
        self::assertSame(0, AICreditReservation::query()->count());
    }

    public function test_read_rechecks_parent_permission_and_deleted_file(): void
    {
        $file = $this->file('one.txt', 'content');
        $document = $this->documents->registerFile($file);
        $this->allowed = false;
        try {
            $this->documents->status($this->owner, $this->organization->id, $document);
            self::fail('Revoked parent permission must deny document access.');
        } catch (AccessDeniedHttpException) {
        }
        $this->allowed = true;
        File::withoutEvents(fn () => $file->delete());
        $this->expectException(RuntimeException::class);
        $this->documents->status($this->owner, $this->organization->id, $document);
    }

    public function test_changed_file_removes_old_index_and_does_not_reuse_old_text(): void
    {
        $file = $this->file('one.txt', 'before');
        $before = $this->documents->process((int) $this->documents->registerFile($file)->id);
        Storage::disk('s3')->put($file->path, 'after');
        $after = $this->documents->registerFile($file);
        self::assertNotSame($before->id, $after->id);
        self::assertFalse(AIAssistantDocument::query()->whereKey($before->id)->exists());
        self::assertSame('after', $this->documents->process((int) $after->id)->extracted_text);
    }

    public function test_ocr_confirmation_and_queue_replay_charge_once(): void
    {
        $document = $this->imageDocument();
        $quote = $this->documents->quoteOcr($this->owner, $this->organization->id, $document);
        $this->documents->confirmOcr($this->owner, $this->organization->id, $document, $quote['quote_id'], $quote['request_id']);
        $this->documents->confirmOcr($this->owner, $this->organization->id, $document, $quote['quote_id'], $quote['request_id']);
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Акт № 1']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]])]);
        $ready = $this->documents->processOcr((int) $document->id);
        $this->documents->processOcr((int) $document->id);
        self::assertSame('ready', $ready->status);
        self::assertSame('Акт № 1', $ready->extracted_text);
        self::assertSame(1, AICreditReservation::query()->where('organization_id', $this->organization->id)->count());
        self::assertSame(50, (int) AICreditReservation::query()->firstOrFail()->consumed_minor);
        Http::assertSentCount(1);
    }

    public function test_ocr_quote_covers_all_server_extracted_pdf_pages(): void
    {
        $document = $this->documents->process((int) $this->documents->registerFile($this->file('archive.pdf', $this->pdfContent(7), null, 'application/pdf'))->id);
        self::assertSame(7, $document->metadata['page_count']);
        $quote = $this->documents->quoteOcr($this->owner, $this->organization->id, $document);
        $approved = $this->documents->confirmOcr($this->owner, $this->organization->id, $document, $quote['quote_id'], $quote['request_id']);
        self::assertSame(7, $this->credits->limits(AICreditReservation::query()->findOrFail($approved->ocr_reservation_id))['max_calls']);
    }

    public function test_pdf_ocr_resumes_per_page_and_finalizes_the_document_once(): void
    {
        $document = $this->documents->process((int) $this->documents->registerFile($this->file('archive.pdf', $this->pdfContent(7), null, 'application/pdf'))->id);
        $quote = $this->documents->quoteOcr($this->owner, $this->organization->id, $document);
        $this->documents->confirmOcr($this->owner, $this->organization->id, $document, $quote['quote_id'], $quote['request_id']);
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Документ']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]])]);
        for ($page = 1; $page <= 7; $page++) {
            $progress = $this->documents->processOcr((int) $document->id);
            self::assertSame($page, $progress->metadata['ocr_completed_pages']);
            self::assertSame($page < 7 ? 'ocr_approved' : 'ready', $progress->status);
        }
        $this->documents->processOcr((int) $document->id);
        self::assertSame(range(1, 7), AIAssistantDocumentUnit::query()->where('document_id', $document->id)->orderBy('unit_index')->pluck('unit_index')->all());
        self::assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        Http::assertSentCount(7);
    }

    public function test_owner_revocation_prevents_ocr_and_releases_reservation(): void
    {
        $document = $this->imageDocument();
        $quote = $this->documents->quoteOcr($this->owner, $this->organization->id, $document);
        $approved = $this->documents->confirmOcr($this->owner, $this->organization->id, $document, $quote['quote_id'], $quote['request_id']);
        $this->organization->users()->updateExistingPivot($this->owner->id, ['is_owner' => false]);
        Http::fake();
        try {
            $this->documents->processOcr((int) $document->id);
            self::fail('Revoked owner approval must prevent OCR.');
        } catch (RuntimeException) {
        }
        $this->documents->failOcr((int) $document->id);
        $this->documents->failOcr((int) $document->id);
        self::assertSame(0, (int) AICreditReservation::query()->findOrFail($approved->ocr_reservation_id)->consumed_minor);
        self::assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        Http::assertNothingSent();
    }

    public function test_background_budget_blocks_excess_and_releases_failed_processing(): void
    {
        $document = $this->imageDocument();
        $budgets = new AssistantDocumentBudgetService($this->documents);
        $budgets->approve($this->owner, $this->organization->id, true, 0, 'archive');
        self::assertFalse($budgets->authorizeBackground($document));
        self::assertSame(0, AICreditReservation::query()->count());
        $budgets->approve($this->owner, $this->organization->id, true, 1_000_000, 'archive');
        self::assertTrue($budgets->authorizeBackground($document->refresh()));
        self::assertFalse($budgets->authorizeBackground($document->refresh()));
        self::assertGreaterThan(0, $budgets->settings($this->owner, $this->organization->id)->reserved_minor);
        $this->documents->failOcr((int) $document->id);
        self::assertSame(0, $budgets->settings($this->owner, $this->organization->id)->reserved_minor);
        self::assertSame(0, $budgets->settings($this->owner, $this->organization->id)->spent_minor);
    }

    public function test_ocr_provider_failure_leaves_no_charge_and_can_get_a_new_quote(): void
    {
        $document = $this->imageDocument();
        $quote = $this->documents->quoteOcr($this->owner, $this->organization->id, $document);
        $this->documents->confirmOcr($this->owner, $this->organization->id, $document, $quote['quote_id'], $quote['request_id']);
        Http::fake(['*' => Http::response(['error' => 'failure'], 503)]);
        try {
            $this->documents->processOcr((int) $document->id);
            self::fail('Provider failure must not create recognized text.');
        } catch (RuntimeException) {
        }
        $this->documents->failOcr((int) $document->id);
        self::assertSame(0, AIAssistantDocumentUnit::query()->where('document_id', $document->id)->count());
        self::assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        $retry = $this->documents->quoteOcr($this->owner, $this->organization->id, $document->refresh());
        self::assertNotSame($quote['request_id'], $retry['request_id']);
    }

    public function test_shadow_mode_still_consumes_the_approved_background_budget(): void
    {
        config(['ai-assistant-credits.enforce' => false]);
        $document = $this->imageDocument();
        $budgets = new AssistantDocumentBudgetService($this->documents);
        $budgets->approve($this->owner, $this->organization->id, true, 1_000_000, 'archive');
        self::assertTrue($budgets->authorizeBackground($document));
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Документ']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]])]);
        $this->documents->processOcr((int) $document->id);
        $settings = $budgets->settings($this->owner, $this->organization->id);
        self::assertSame(50, $settings->spent_minor);
        self::assertSame(0, $settings->reserved_minor);
        self::assertSame(0, (int) AICreditReservation::query()->firstOrFail()->consumed_minor);
        self::assertSame(1_000_000, $this->credits->balance($this->organization)['available_minor']);
    }

    public function test_archive_scan_is_bounded_and_cursor_replay_does_not_repeat_files(): void
    {
        $this->file('one.txt', 'one');
        $this->file('two.txt', 'two');
        (new ScanAssistantDocuments($this->organization->id))->handle();
        (new ScanAssistantDocuments($this->organization->id))->handle();
        Queue::assertPushed(\App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob::class,
            fn ($job): bool => $job->sourceType === 'file_document' && $job->entityType === 'file');
        self::assertSame(2, \App\BusinessModules\Features\AIAssistant\Models\RagIndexRun::query()
            ->where('organization_id', $this->organization->id)->where('entity_type', 'file')->count());
        $settings = AssistantDocumentSettings::query()->where('organization_id', $this->organization->id)->firstOrFail();
        self::assertSame(2, $settings->scanned_count);
        self::assertNotNull($settings->scan_completed_at);
    }

    public function test_owner_coverage_includes_unindexed_and_unsupported_archive_files(): void
    {
        $readyFile = $this->file('ready.txt', 'ready');
        $readyFile->updateQuietly(['additional_info' => ['versions' => [['revision' => 1]], 'labels' => ['ready']]]);
        $this->documents->process((int) $this->documents->registerFile($readyFile)->id);
        $pendingFile = $this->file('pending.txt', 'pending');
        $pendingFile->updateQuietly(['additional_info' => ['versions' => [], 'labels' => ['pending']]]);
        $unsupported = $this->file('audio.wav', 'unsupported audio', null, 'audio/wav');
        $this->documents->process((int) $this->documents->registerFile($unsupported)->id);
        $this->imageDocument();
        $before = (new AssistantDocumentCoverageService($this->policy, $this->documents))->coverage($this->organization->id, $this->owner);
        self::assertTrue($before['can_manage_document_settings']);
        self::assertSame(4, $before['document_coverage']['total']);
        self::assertSame(1, $before['document_coverage']['ready']);
        self::assertSame(1, $before['document_coverage']['pending']);
        self::assertSame(1, $before['document_coverage']['unsupported']);
        self::assertSame(1, $before['document_coverage']['ocr_required']);
        self::assertSame(4, $before['archive_scan']['expected_file_count']);
        self::assertTrue($before['archive_scan']['processing']);
        (new ScanAssistantDocuments($this->organization->id))->handle();
        $after = (new AssistantDocumentCoverageService($this->policy, $this->documents))->coverage($this->organization->id, $this->owner);
        self::assertSame(4, $after['archive_scan']['scanned_file_count']);
        self::assertFalse($after['archive_scan']['processing']);
        self::assertSame(0, AICreditReservation::query()->count());
    }

    public function test_coverage_counts_readable_unsupported_storage_and_formats_without_exposing_unknown_parents(): void
    {
        $local = $this->file('local.txt', 'local');
        File::withoutEvents(fn () => $local->update(['disk' => 'local']));
        $this->file('unindexed.wav', 'audio', null, 'audio/wav');
        $this->file('pending.txt', 'pending');
        $unknown = $this->file('unknown.txt', 'private');
        File::withoutEvents(fn () => $unknown->update(['fileable_type' => 'Unknown\\PrivateParent', 'disk' => 'local']));
        $foreignOrganization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $foreign = $this->file('foreign.txt', 'foreign');
        File::withoutEvents(fn () => $foreign->update(['organization_id' => $foreignOrganization->id, 'disk' => 'local']));

        $coverage = (new AssistantDocumentCoverageService($this->policy, $this->documents))->coverage($this->organization->id, $this->owner);

        self::assertSame(3, $coverage['document_coverage']['total']);
        self::assertSame(2, $coverage['document_coverage']['unsupported']);
        self::assertSame(1, $coverage['document_coverage']['pending']);
        self::assertSame(2, $coverage['archive_scan']['expected_file_count']);
        self::assertFalse($this->policy->accessibleFiles($this->owner, $this->organization->id)->whereKey($local->id)->exists());
        self::assertStringNotContainsString($unknown->path, json_encode($coverage, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($foreign->path, json_encode($coverage, JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ai_assistant_document_file_invalid');
        (new \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentFileResolver($this->policy))
            ->resolve($this->organization->id, 'project', $this->project->id, $local->path);
    }

    public function test_coverage_file_scope_filters_private_and_foreign_projects_before_limit(): void
    {
        $private = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id]));
        $this->deniedProjects = [$private->id];
        for ($index = 0; $index < 51; $index++) {
            $hidden = $this->file('private-local-'.$index.'.txt', 'private', $private);
            File::withoutEvents(fn () => $hidden->update(['disk' => 'local']));
        }
        $foreignOrganization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $foreignOrganization->id]));
        $foreign = $this->file('foreign-project.txt', 'foreign', $foreignProject);
        File::withoutEvents(fn () => $foreign->update(['disk' => 'local']));
        $visible = $this->file('visible-local.txt', 'visible');
        File::withoutEvents(fn () => $visible->update(['disk' => 'local']));

        self::assertSame([$visible->id], $this->policy->accessibleFiles($this->owner, $this->organization->id, false)
            ->orderBy('files.id')->limit(1)->pluck('files.id')->all());
        $coverage = (new AssistantDocumentCoverageService($this->policy, $this->documents))->coverage($this->organization->id, $this->owner);
        self::assertSame(1, $coverage['document_coverage']['total']);
        self::assertSame(1, $coverage['document_coverage']['unsupported']);
        self::assertSame(0, $coverage['archive_scan']['expected_file_count']);
    }

    public function test_attachment_scope_compiles_only_present_parent_types_and_discovers_new_types_next_time(): void
    {
        $attach = static fn (File $file, string $type, int $projectId): AIAssistantDocument => AIAssistantDocument::query()->create([
            'organization_id' => $file->organization_id, 'project_id' => $projectId, 'file_id' => $file->id,
            'parent_entity_type' => $type, 'parent_entity_id' => (string) $file->fileable_id,
            'storage_path' => $file->path, 'filename' => $file->name, 'mime_type' => $file->mime_type,
            'checksum' => hash('sha256', $file->path), 'size_bytes' => $file->size, 'status' => 'ready',
        ]);
        $visibleFile = $this->file('visible-scope.txt', 'visible');
        $visibleDocument = $attach($visibleFile, 'project', (int) $this->project->id);

        $initialFiles = $this->policy->accessibleFiles($this->owner, (int) $this->organization->id);
        $initialDocuments = $this->policy->accessibleDocuments($this->owner, (int) $this->organization->id);
        self::assertStringNotContainsString('from "estimates"', $initialFiles->toSql());
        self::assertStringNotContainsString('from "estimates"', $initialDocuments->toSql());
        self::assertLessThan(200_000, strlen($initialFiles->toSql()));
        self::assertSame([$visibleFile->id], $initialFiles->pluck('files.id')->all());
        self::assertSame([$visibleDocument->id], $initialDocuments->pluck('ai_assistant_documents.id')->all());

        $estimate = Estimate::withoutEvents(fn () => Estimate::query()->create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id,
            'number' => 'assistant-scope-'.$this->organization->id, 'name' => 'Смета', 'estimate_date' => today(),
        ]));
        $estimateFile = File::withoutEvents(fn () => File::query()->create([
            'organization_id' => $this->organization->id, 'user_id' => $this->owner->id,
            'fileable_type' => $estimate->getMorphClass(), 'fileable_id' => $estimate->id,
            'name' => 'estimate-scope.pdf', 'original_name' => 'estimate-scope.pdf',
            'path' => 'org-'.$this->organization->id.'/assistant-test/estimate-scope.pdf',
            'mime_type' => 'application/pdf', 'size' => 1, 'disk' => 's3',
        ]));
        $estimateDocument = $attach($estimateFile, 'estimate', (int) $this->project->id);

        $privateProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id]));
        $privateFile = $this->file('private-scope.txt', 'private', $privateProject);
        $attach($privateFile, 'project', (int) $privateProject->id);
        $this->deniedProjects = [(int) $privateProject->id];

        $unknownFile = $this->file('unknown-scope.txt', 'unknown');
        File::withoutEvents(fn () => $unknownFile->update(['fileable_type' => 'Unknown\\PrivateParent']));
        $attach($unknownFile, 'unknown', (int) $this->project->id);

        $foreignOrganization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $foreignOrganization->id]));
        $foreignFile = File::withoutEvents(fn () => File::query()->create([
            'organization_id' => $foreignOrganization->id, 'user_id' => $this->owner->id,
            'fileable_type' => $foreignProject->getMorphClass(), 'fileable_id' => $foreignProject->id,
            'name' => 'foreign-scope.txt', 'original_name' => 'foreign-scope.txt',
            'path' => 'org-'.$foreignOrganization->id.'/assistant-test/foreign-scope.txt',
            'mime_type' => 'text/plain', 'size' => 1, 'disk' => 's3',
        ]));
        $attach($foreignFile, 'project', (int) $foreignProject->id);

        $currentFiles = $this->policy->accessibleFiles($this->owner, (int) $this->organization->id);
        $currentDocuments = $this->policy->accessibleDocuments($this->owner, (int) $this->organization->id);
        self::assertStringContainsString('from "estimates"', $currentFiles->toSql());
        self::assertStringContainsString('from "estimates"', $currentDocuments->toSql());
        self::assertSame([$visibleFile->id, $estimateFile->id], $currentFiles->orderBy('files.id')->pluck('files.id')->all());
        self::assertSame([$visibleDocument->id, $estimateDocument->id], $currentDocuments->orderBy('ai_assistant_documents.id')->pluck('ai_assistant_documents.id')->all());
    }

    public function test_coverage_filters_private_files_before_count_and_does_not_disclose_global_cursor(): void
    {
        $this->organization->users()->updateExistingPivot($this->owner->id, ['is_owner' => false]);
        $visible = $this->file('visible.txt', 'visible');
        $this->documents->process((int) $this->documents->registerFile($visible)->id);
        $private = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id]));
        $this->deniedProjects = [$private->id];
        for ($index = 0; $index < 51; $index++) $this->file('private-'.$index.'.txt', 'private', $private);
        (new ScanAssistantDocuments($this->organization->id))->handle();
        $coverage = (new AssistantDocumentCoverageService($this->policy, $this->documents))->coverage($this->organization->id, $this->owner);
        self::assertFalse($coverage['can_manage_document_settings']);
        self::assertSame(1, $coverage['document_coverage']['total']);
        self::assertSame(1, $coverage['document_coverage']['ready']);
        self::assertSame(1, $coverage['archive_scan']['expected_file_count']);
        self::assertSame(1, $coverage['archive_scan']['scanned_file_count']);
        self::assertSame($visible->id, $coverage['archive_scan']['last_file_id']);
        self::assertFalse($coverage['archive_scan']['processing']);
    }

    public function test_owner_settings_permission_does_not_reveal_private_archive_state(): void
    {
        $visible = $this->file('visible-owner.txt', 'visible');
        $private = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id]));
        $hidden = $this->file('hidden-owner.txt', 'hidden', $private);
        $this->documents->process((int) $this->documents->registerFile($hidden)->id);
        (new ScanAssistantDocuments($this->organization->id))->handle();
        $this->deniedProjects = [$private->id];
        $coverage = (new AssistantDocumentCoverageService($this->policy, $this->documents))->coverage($this->organization->id, $this->owner);
        self::assertTrue($coverage['can_manage_document_settings']);
        self::assertSame(1, $coverage['document_coverage']['total']);
        self::assertSame(0, $coverage['document_coverage']['ready']);
        self::assertSame(1, $coverage['archive_scan']['expected_file_count']);
        self::assertSame($visible->id, $coverage['archive_scan']['last_file_id']);
        $this->modulesEnabled = false;
        $revoked = (new AssistantDocumentCoverageService($this->policy, $this->documents))->coverage($this->organization->id, $this->owner);
        self::assertTrue($revoked['can_manage_document_settings']);
        self::assertSame(0, $revoked['document_coverage']['total']);
        self::assertSame(0, $revoked['archive_scan']['expected_file_count']);
        self::assertSame(0, $revoked['archive_scan']['last_file_id']);
        self::assertNull($revoked['archive_scan']['completed_at']);
    }

    public function test_coverage_aggregates_units_only_for_actor_accessible_latest_documents(): void
    {
        $visibleFile = $this->file('coverage-visible.txt', 'visible');
        $visible = $this->documents->registerFile($visibleFile);
        $privateProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id]));
        $privateFile = $this->file('coverage-private.txt', 'private', $privateProject);
        $private = $this->documents->registerFile($privateFile);
        foreach ([[$visible, 2], [$private, 7]] as [$document, $count]) {
            for ($index = 1; $index <= $count; $index++) {
                AIAssistantDocumentUnit::query()->create([
                    'document_id' => $document->id, 'unit_type' => $index === 1 ? 'ocr_page' : 'text_chunk',
                    'unit_index' => $index, 'text' => 'unit', 'checksum' => hash('sha256', $document->id.'-'.$index),
                ]);
            }
        }
        $this->deniedProjects = [$privateProject->id];
        $unitAggregateQueries = [];
        DB::listen(static function ($query) use (&$unitAggregateQueries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'ai_assistant_document_units') && str_contains($sql, 'group by')) {
                $unitAggregateQueries[] = $sql;
            }
        });

        $coverage = (new AssistantDocumentCoverageService($this->policy, $this->documents))->coverage($this->organization->id, $this->owner);

        self::assertSame(1, $coverage['document_coverage']['total']);
        self::assertSame(2, $coverage['document_coverage']['processed_units']);
        self::assertSame(1, $coverage['document_coverage']['ocr_completed_pages']);
        self::assertNotEmpty($unitAggregateQueries);
        self::assertStringContainsString('where "document_id" in (select', implode("\n", $unitAggregateQueries));
        self::assertStringContainsString('ai_assistant_documents', implode("\n", $unitAggregateQueries));
    }

    public function test_document_scan_command_advances_organization_batches_and_skips_inactive_assistants(): void
    {
        $second = Organization::withoutEvents(fn () => Organization::factory()->create());
        $third = Organization::withoutEvents(fn () => Organization::factory()->create());
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturnUsing(fn (int $id) => $id === $second->id ? collect() : collect([(object) ['slug' => 'ai-assistant']]));
        $this->app->instance(OrganizationEntitlementService::class, $modules);
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new \App\BusinessModules\Features\AIAssistant\Console\Commands\ScanAssistantDocumentsCommand);
        $this->artisan('ai-assistant:documents-scan', ['--batch' => 1])->assertExitCode(0);
        $this->artisan('ai-assistant:documents-scan', ['--batch' => 1])->assertExitCode(0);
        $this->artisan('ai-assistant:documents-scan', ['--batch' => 1])->assertExitCode(0);
        Queue::assertPushed(ScanAssistantDocuments::class, fn ($job): bool => $job->organizationId === $this->organization->id);
        Queue::assertNotPushed(ScanAssistantDocuments::class, fn ($job): bool => $job->organizationId === $second->id);
        Queue::assertPushed(ScanAssistantDocuments::class, fn ($job): bool => $job->organizationId === $third->id);
    }

    private function file(string $name, string $content, ?Project $project = null, string $mime = 'text/plain'): File
    {
        $project ??= $this->project;
        $path = 'org-'.$this->organization->id.'/assistant-test/'.$name;
        Storage::disk('s3')->put($path, $content);

        return File::withoutEvents(fn () => File::query()->create(['organization_id' => $this->organization->id,
            'fileable_type' => $project->getMorphClass(), 'fileable_id' => $project->id, 'user_id' => $this->owner->id,
            'name' => $name, 'original_name' => $name, 'path' => $path, 'mime_type' => $mime, 'size' => strlen($content), 'disk' => 's3']));
    }

    private function imageDocument(): AIAssistantDocument
    {
        $content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        return $this->documents->process((int) $this->documents->registerFile($this->file('scan.png', $content, null, 'image/png'))->id);
    }

    private function pdfContent(int $pages): string
    {
        $pdf = new \Dompdf\Dompdf;
        $html = '<html><body>';
        for ($page = 1; $page <= $pages; $page++) {
            $html .= '<div style="'.($page > 1 ? 'page-break-before:always;' : '').'height:20px;"><svg width="10" height="10"><rect width="10" height="10" fill="black"/></svg></div>';
        }
        $pdf->loadHtml($html.'</body></html>');
        $pdf->render();

        return $pdf->output();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
