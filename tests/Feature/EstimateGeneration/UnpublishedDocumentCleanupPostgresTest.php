<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\OriginalDocumentArtifactLocator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RecoverUnpublishedDocumentObjects;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UnpublishedDocumentCleanupJournal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UnpublishedDocumentObjectBatch;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UploadEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrDocumentStorageService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\InMemoryS3HttpStorage;

final class UnpublishedDocumentCleanupPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    use \Tests\Support\EnablesImmutableAuditWriter;

    public function test_operator_retry_is_audited_idempotent_scoped_and_rechecks_storage_identity(): void
    {
        [, $session] = $this->fixture();
        config(['legal_archive.audit_writer_secret' => 'fixture-only-cleanup-writer-ABC123xyz789']);
        $this->enableImmutableAuditWriter();
        $operator = \App\Models\SystemAdmin::query()->create(['name' => 'Cleanup operator', 'email' => Str::uuid().'@example.test',
            'password' => 'fixture-only-password', 'role' => 'super_admin', 'is_active' => true]);
        (new InMemoryS3HttpStorage)->install();
        $body = 'temporary private document';
        $path = 'org-'.$session->organization_id.'/estimate-generation/sessions/'.$session->id.'/documents/'.Str::uuid().'.pdf';
        app(FileService::class)->putImmutable($path, $body, 'application/pdf');
        $batch = new UnpublishedDocumentObjectBatch((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $batch->capture($path, hash('sha256', $body), strlen($body));
        app(UnpublishedDocumentCleanupJournal::class)->recordBatch($batch);
        $debt = DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('storage_path', $path)->first();
        DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $debt->id)->update(['status' => 'exhausted', 'attempts' => 10]);
        $retry = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RetryExhaustedDocumentCleanup::class);
        $request = (string) Str::uuid();
        $receipt = $retry->handle($session->organization_id, $session->project_id, $session->id, $debt->id,
            $debt->identity_hash, $request, $operator->id, 'storage_access_restored');
        $pending = DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $debt->id)->first();
        self::assertSame('pending', $pending->status);
        self::assertSame(0, $pending->attempts);
        self::assertSame($debt->identity_hash, $pending->identity_hash);
        self::assertSame($receipt, $retry->handle($session->organization_id, $session->project_id, $session->id, $debt->id,
            $debt->identity_hash, $request, $operator->id, 'storage_access_restored'));
        $audit = DB::table('immutable_audit_events')->where('id', $receipt)->first();
        self::assertSame('system_admin', $audit->actor_type);
        self::assertNull($audit->actor_user_id);
        self::assertStringNotContainsString($path, json_encode($audit, JSON_THROW_ON_ERROR));
        self::assertSame(10, json_decode($audit->before_state, true)['attempts']);
        self::assertSame(1, json_decode($audit->after_state, true)['retry_epoch']);
        foreach (['scope', 'inactive', 'changed_request'] as $failure) {
            if ($failure === 'inactive') {
                $operator->forceFill(['is_active' => false])->save();
            }
            try {
                $retry->handle($failure === 'scope' ? $session->organization_id + 999 : $session->organization_id,
                    $session->project_id, $session->id, $debt->id, $debt->identity_hash, $request, $operator->id,
                    $failure === 'changed_request' ? 'storage_transport_restored' : 'storage_access_restored');
                self::fail('Unauthorized or conflicting retry was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $debt->id)->value('attempts'));
            }
            $operator->forceFill(['is_active' => true])->save();
        }
        // Retry changes no storage identity. A replacement under the same key must survive the worker.
        self::assertTrue(Storage::disk('s3')->put($path, 'replacement private object'));
        self::assertSame(0, app(RecoverUnpublishedDocumentObjects::class)->handle());
        self::assertSame('blocked_identity', DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $debt->id)->value('status'));
        self::assertTrue(app(FileService::class)->existsCurrent($path));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_operator_retry_has_three_audited_epochs_and_cannot_resume_published_objects(): void
    {
        [$owner, $session] = $this->fixture();
        config(['legal_archive.audit_writer_secret' => 'fixture-only-cleanup-writer-ABC123xyz789']);
        $this->enableImmutableAuditWriter();
        $operator = \App\Models\SystemAdmin::query()->create(['name' => 'Cleanup operator', 'email' => Str::uuid().'@example.test',
            'password' => 'fixture-only-password', 'role' => 'super_admin', 'is_active' => true]);
        $path = 'org-'.$session->organization_id.'/estimate-generation/sessions/'.$session->id.'/documents/'.Str::uuid().'.pdf';
        $batch = new UnpublishedDocumentObjectBatch((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $batch->capture($path, hash('sha256', 'fixture'), 7);
        app(UnpublishedDocumentCleanupJournal::class)->recordBatch($batch);
        $debt = DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('storage_path', $path)->first();
        $retry = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RetryExhaustedDocumentCleanup::class);
        for ($epoch = 1; $epoch <= 4; $epoch++) {
            DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $debt->id)->update(['status' => 'exhausted', 'attempts' => 10]);
            try {
                $receipt = $retry->handle($session->organization_id, $session->project_id, $session->id, $debt->id,
                    $debt->identity_hash, (string) Str::uuid(), $operator->id, 'storage_transport_restored');
                self::assertLessThanOrEqual(3, $epoch);
                self::assertSame($epoch, json_decode(DB::table('immutable_audit_events')->where('id', $receipt)->value('after_state'), true)['retry_epoch']);
            } catch (\InvalidArgumentException $error) {
                self::assertSame(4, $epoch);
                self::assertSame('document_cleanup_retry_limit_reached', $error->getMessage());
            }
        }
        EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $session->organization_id,
            'project_id' => $session->project_id, 'user_id' => $owner->id, 'filename' => 'saved.pdf', 'mime_type' => 'application/pdf',
            'storage_path' => $path, 'file_size_bytes' => 7, 'checksum_sha256' => hash('sha256', 'fixture'), 'status' => 'ignored']);
        try {
            $retry->handle($session->organization_id, $session->project_id, $session->id, $debt->id,
                $debt->identity_hash, (string) Str::uuid(), $operator->id, 'storage_access_restored');
            self::fail('A saved document must not be requeued for deletion.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('document_cleanup_retry_object_blocked', $error->getMessage());
        }
        self::assertSame('exhausted', DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $debt->id)->value('status'));
    }

    public function test_failed_compensation_debt_survives_the_whole_batch_rollback_and_recovers_without_user_documents(): void
    {
        [$actor, $session] = $this->fixture();
        $puts = 0;
        $failedPath = null;
        $denyDelete = true;
        (new InMemoryS3HttpStorage)->install(static function (RequestInterface $request) use (&$puts, &$failedPath, &$denyDelete): ?Response {
            if ($request->getMethod() === 'DELETE' && $denyDelete) {
                return new Response(400, ['Content-Type' => 'application/xml'], '<Error><Code>AccessDenied</Code></Error>');
            }
            if ($request->getMethod() === 'PUT' && ! str_contains($request->getUri()->getQuery(), 'tagging')
                && str_contains($request->getUri()->getPath(), '/documents/')) {
                if ($failedPath === null && ++$puts === 2) {
                    $failedPath = $request->getUri()->getPath();
                }
                if ($request->getUri()->getPath() === $failedPath) {
                    return new Response(400, ['Content-Type' => 'application/xml'], '<Error><Code>InvalidRequest</Code></Error>');
                }
            }

            return null;
        });
        $bytes = file_get_contents(base_path('tests/Fixtures/EstimateGeneration/ocr/vector-plan.pdf'));
        try {
            app(UploadEstimateGenerationDocuments::class)->handle($session, 0,
                [UploadedFile::fake()->createWithContent('first.pdf', $bytes), UploadedFile::fake()->createWithContent('second.pdf', $bytes)], $actor);
            self::fail('The failed second upload committed a partial batch.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException) {
            self::assertSame(0, DB::transactionLevel());
        }
        self::assertSame(0, EstimateGenerationDocument::query()->where('session_id', $session->id)->count());
        self::assertSame(0, $session->fresh()->state_version);
        $debts = DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('session_id', $session->id)->orderBy('id')->get();
        self::assertCount(2, $debts);
        self::assertTrue(app(FileService::class)->existsCurrent($debts[0]->storage_path));
        $denyDelete = false;
        self::assertFalse(Storage::disk('s3')->fileExists($debts[1]->storage_path));
        self::assertSame(2, app(RecoverUnpublishedDocumentObjects::class)->handle());
        self::assertFalse(Storage::disk('s3')->fileExists($debts[0]->storage_path));
        self::assertSame(0, app(RecoverUnpublishedDocumentObjects::class)->handle());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_a_saved_document_reference_and_a_changed_object_identity_are_never_deleted(): void
    {
        [$actor, $session] = $this->fixture();
        (new InMemoryS3HttpStorage)->install();
        $body = 'original fixture document';
        $path = 'org-'.$session->organization_id.'/estimate-generation/sessions/'.$session->id.'/documents/'.Str::uuid().'.pdf';
        app(FileService::class)->putImmutable($path, $body, 'application/pdf');
        $batch = new UnpublishedDocumentObjectBatch((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $batch->capture($path, hash('sha256', $body), strlen($body));
        app(UnpublishedDocumentCleanupJournal::class)->recordBatch($batch);
        EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $session->organization_id,
            'project_id' => $session->project_id, 'user_id' => $actor->id, 'filename' => 'preserved.pdf', 'mime_type' => 'application/pdf',
            'storage_path' => $path, 'file_size_bytes' => strlen($body), 'checksum_sha256' => hash('sha256', $body), 'status' => 'ignored']);
        DB::table('estimate_generation_sessions')->where('id', $session->id)->update(['status' => 'archived']);
        self::assertSame(0, app(RecoverUnpublishedDocumentObjects::class)->handle());
        self::assertTrue(app(FileService::class)->existsCurrent($path));
        self::assertSame('blocked_reference', DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('storage_path', $path)->value('status'));
        $changedPath = 'org-'.$session->organization_id.'/estimate-generation/sessions/'.$session->id.'/documents/'.Str::uuid().'.pdf';
        app(FileService::class)->putImmutable($changedPath, 'different object content', 'application/pdf');
        $changed = new UnpublishedDocumentObjectBatch((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $changed->capture($changedPath, hash('sha256', $body), strlen($body));
        app(UnpublishedDocumentCleanupJournal::class)->recordBatch($changed);
        self::assertSame(0, app(RecoverUnpublishedDocumentObjects::class)->handle());
        self::assertTrue(app(FileService::class)->existsCurrent($changedPath));
        self::assertSame('blocked_identity', DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('storage_path', $changedPath)->value('status'));
    }

    public function test_immediate_compensation_also_preserves_unit_and_page_references_in_archived_sessions(): void
    {
        [$actor, $session] = $this->fixture();
        (new InMemoryS3HttpStorage)->install();
        $body = 'private original document';
        $prefix = 'org-'.$session->organization_id.'/estimate-generation/sessions/'.$session->id.'/documents/';
        $document = EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $session->organization_id,
            'project_id' => $session->project_id, 'user_id' => $actor->id, 'filename' => 'saved.pdf', 'mime_type' => 'application/pdf',
            'storage_path' => $prefix.Str::uuid().'.pdf', 'file_size_bytes' => strlen($body), 'checksum_sha256' => hash('sha256', $body),
            'source_version' => 'sha256:'.hash('sha256', $body), 'status' => 'ignored']);
        $scope = ['session_id' => $session->id, 'organization_id' => $session->organization_id,
            'project_id' => $session->project_id, 'document_id' => $document->id, 'source_version' => $document->source_version];
        $baseLocator = OriginalDocumentArtifactLocator::forUnit($document, DocumentUnitType::PdfPage, 1, $document->source_version, ['page' => 1]);
        $unit = EstimateGenerationProcessingUnit::query()->create([...$scope, 'unit_type' => 'pdf_page', 'unit_index' => 1,
            'status' => 'superseded', 'locator' => $baseLocator, 'metadata' => ['processing_attempt_id' => (string) Str::uuid()]]);
        $page = EstimateGenerationDocumentPage::query()->create([...$scope, 'processing_unit_id' => $unit->id,
            'page_number' => 1, 'status' => 'ready', 'normalized_payload' => ['schema_version' => 1]]);
        $session->forceFill(['status' => 'archived'])->saveQuietly();
        foreach (['unit', 'page'] as $reference) {
            $path = $prefix.Str::uuid().'.pdf';
            app(FileService::class)->putImmutable($path, $body, 'application/pdf');
            if ($reference === 'unit') {
                $unit->forceFill(['locator' => [...$baseLocator, 'document_representation' => ['visual_artifact_path' => $path]]])->saveQuietly();
            } else {
                $unit->forceFill(['locator' => $baseLocator])->saveQuietly();
                $page->forceFill(['normalized_payload' => ['source' => ['artifact_path' => $path]]])->saveQuietly();
            }
            $unpublished = $document->replicate();
            $unpublished->storage_path = $path;
            self::assertFalse(app(OcrDocumentStorageService::class)->removeUnpublishedDocumentObject($session, $unpublished));
            self::assertTrue(Storage::disk('s3')->fileExists($path));
            $batch = new UnpublishedDocumentObjectBatch((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
            $batch->capture($path, hash('sha256', $body), strlen($body));
            app(UnpublishedDocumentCleanupJournal::class)->recordBatch($batch);
            app(UnpublishedDocumentCleanupJournal::class)->recordBatch($batch);
            self::assertSame(0, app(RecoverUnpublishedDocumentObjects::class)->handle());
            self::assertSame('blocked_reference', DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('storage_path', $path)->value('status'));
            self::assertSame(1, DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('storage_path', $path)->count());
        }
    }

    public function test_transport_failures_back_off_and_stop_after_ten_attempts_without_charging_ai(): void
    {
        [, $session] = $this->fixture();
        (new InMemoryS3HttpStorage)->install(static fn (RequestInterface $request): ?Response => $request->getMethod() === 'DELETE'
            ? new Response(400, ['Content-Type' => 'application/xml'], '<Error><Code>AccessDenied</Code></Error>') : null);
        $body = 'temporary private document';
        $path = 'org-'.$session->organization_id.'/estimate-generation/sessions/'.$session->id.'/documents/'.Str::uuid().'.pdf';
        app(FileService::class)->putImmutable($path, $body, 'application/pdf');
        $batch = new UnpublishedDocumentObjectBatch((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $batch->capture($path, hash('sha256', $body), strlen($body));
        app(UnpublishedDocumentCleanupJournal::class)->recordBatch($batch);
        self::assertSame(0, app(RecoverUnpublishedDocumentObjects::class)->handle());
        $debt = DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('storage_path', $path)->first();
        self::assertSame(1, $debt->attempts);
        self::assertSame('pending', $debt->status);
        self::assertGreaterThan(now()->timestamp, strtotime($debt->next_attempt_at));
        self::assertSame(0, app(RecoverUnpublishedDocumentObjects::class)->handle());
        self::assertSame(1, DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $debt->id)->value('attempts'));
        DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $debt->id)->update(['attempts' => 9, 'next_attempt_at' => now()->subSecond()]);
        self::assertSame(0, app(RecoverUnpublishedDocumentObjects::class)->handle());
        $last = DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('id', $debt->id)->first();
        self::assertSame(10, $last->attempts);
        self::assertSame('exhausted', $last->status);
        self::assertTrue(Storage::disk('s3')->fileExists($path));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    private function fixture(): array
    {
        config(['cache.default' => 'array']);
        $this->seedGlobalSettings();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => []]);

        return [$actor, $session];
    }

    private function seedGlobalSettings(): void
    {
        if (DB::table('estimate_generation_setting_snapshots')->where('scope', 'global')->whereNull('organization_id')->exists()) {
            return;
        }
        $snapshot = \App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData::fromArray([
            'scope' => 'global', 'organization_id' => null, 'expected_version' => 0, 'idempotency_key' => (string) Str::ulid(),
            'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'openai/gpt-6-luna', 'normative_matching' => 'openai/gpt-6-luna'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
            'retries' => ['vision' => 0, 'classification' => 0, 'normative_matching' => 0],
            'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
            'enabled_formats' => ['pdf', 'png'], 'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB']])->snapshot();
        $hash = \App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash::calculate($snapshot);
        $adminId = DB::table('system_admins')->insertGetId(['name' => 'Fixture', 'email' => Str::uuid().'@example.test',
            'password' => 'not-used', 'created_at' => now(), 'updated_at' => now()]);
        $snapshotId = DB::table('estimate_generation_setting_snapshots')->insertGetId(['scope' => 'global', 'organization_id' => null,
            'version' => 1, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_hash' => $hash,
            'daily_budget' => '250.00', 'monthly_budget' => '4000.00', 'currency' => 'RUB', 'created_by_system_admin_id' => $adminId, 'created_at' => now()]);
        DB::table('estimate_generation_setting_snapshot_hashes')->insert(['setting_snapshot_id' => $snapshotId,
            'algorithm' => 'jcs-sha256-v1', 'snapshot_hash' => $hash, 'created_at' => now()]);
    }
}
