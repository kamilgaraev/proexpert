<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentAdmissionConflict;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentAdmissionOperation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\PreparedDocumentAdmission;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RecoverUnpublishedDocumentObjects;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReuseEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UnpublishedDocumentCleanupJournal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UploadEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\InMemoryS3HttpStorage;

final class DocumentAdmissionPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_intent_is_committed_before_storage_and_lost_response_replays_without_another_put_or_queue(): void
    {
        [$actor, $session] = $this->fixture();
        $puts = 0;
        $this->app['config']->set('database.connections.admission_observer', config('database.connections.'.config('database.default')));
        (new InMemoryS3HttpStorage)->install(function (RequestInterface $request) use (&$puts, $session): ?Response {
            if ($request->getMethod() === 'PUT' && ! str_contains($request->getUri()->getQuery(), 'tagging')) {
                $puts++;
                $row = DB::connection('admission_observer')->table(DocumentAdmissionOperation::TABLE)->where('session_id', $session->id)->first();
                self::assertNotNull($row);
                self::assertSame('pending', $row->status);
                self::assertSame(1, $row->reserved_slots);
                self::assertSame(0, DB::connection('admission_observer')->table('estimate_generation_documents')->where('session_id', $session->id)->count());
            }

            return null;
        });
        Queue::fake();
        $request = (string) Str::uuid();
        $files = [$this->pdf()];
        $first = app(UploadEstimateGenerationDocuments::class)->handle($session, 0, $files, $actor, $request);
        $version = $session->fresh()->state_version;
        $second = app(UploadEstimateGenerationDocuments::class)->handle($session->fresh(), 0, $files, $actor, $request);
        self::assertSame($first->documents->pluck('id')->all(), $second->documents->pluck('id')->all());
        self::assertSame(1, $puts);
        self::assertSame($version, $session->fresh()->state_version);
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 1);
        self::assertSame('accepted', DB::table(DocumentAdmissionOperation::TABLE)->where('request_id', $request)->value('status'));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        DB::disconnect('admission_observer');
    }

    public function test_request_id_cannot_be_reused_for_another_file_actor_or_session(): void
    {
        [$actor, $session] = $this->fixture();
        (new InMemoryS3HttpStorage)->install();
        Queue::fake();
        $request = (string) Str::uuid();
        app(UploadEstimateGenerationDocuments::class)->handle($session, 0, [$this->pdf()], $actor, $request);
        $this->expectException(DocumentAdmissionConflict::class);
        app(UploadEstimateGenerationDocuments::class)->handle($session->fresh(), 0, [$this->pdf('other.pdf')], $actor, $request);
    }

    public function test_failed_batch_has_a_durable_failed_receipt_and_all_destinations_are_cleanup_debts(): void
    {
        [$actor, $session] = $this->fixture();
        $puts = 0;
        (new InMemoryS3HttpStorage)->install(static function (RequestInterface $request) use (&$puts): ?Response {
            if ($request->getMethod() === 'PUT' && ! str_contains($request->getUri()->getQuery(), 'tagging') && ++$puts >= 2) {
                return new Response(400, ['Content-Type' => 'application/xml'], '<Error><Code>InvalidRequest</Code></Error>');
            }

            return null;
        });
        $request = (string) Str::uuid();
        try {
            app(UploadEstimateGenerationDocuments::class)->handle($session, 0, [$this->pdf(), $this->pdf('second.pdf')], $actor, $request);
            self::fail('Partial documents committed.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException) {
            self::assertSame(0, DB::transactionLevel());
        }
        self::assertSame('failed', DB::table(DocumentAdmissionOperation::TABLE)->where('request_id', $request)->value('status'));
        self::assertSame(0, $session->documents()->count());
        self::assertSame(0, $session->fresh()->state_version);
        self::assertSame(2, DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('session_id', $session->id)->count());
        $this->expectException(DocumentAdmissionConflict::class);
        app(UploadEstimateGenerationDocuments::class)->handle($session, 0, [$this->pdf(), $this->pdf('second.pdf')], $actor, $request);
    }

    public function test_abandoned_intent_reserves_slots_until_recovery_and_cleans_only_its_destination(): void
    {
        [$actor, $session] = $this->fixture();
        (new InMemoryS3HttpStorage)->install();
        $file = $this->pdf();
        $prepared = PreparedDocumentAdmission::upload($session, [$file]);
        $id = $this->pending($session, $actor, $prepared);
        app(FileService::class)->putImmutable($prepared->objects[0]->path, file_get_contents($file->getPathname()), 'application/pdf');
        self::assertSame(1, app(DocumentAdmissionOperation::class)->recover());
        self::assertSame('failed', DB::table(DocumentAdmissionOperation::TABLE)->where('id', $id)->value('status'));
        self::assertGreaterThanOrEqual(1, app(RecoverUnpublishedDocumentObjects::class)->handle());
        self::assertSame('completed', DB::table(UnpublishedDocumentCleanupJournal::TABLE)
            ->where('storage_path', $prepared->objects[0]->path)->value('status'));
        self::assertFalse(Storage::disk('s3')->fileExists($prepared->objects[0]->path));
        self::assertSame(0, app(DocumentAdmissionOperation::class)->recover());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_a_pending_intent_blocks_another_upload_before_any_storage_write(): void
    {
        [$actor, $session] = $this->fixture();
        $prepared = PreparedDocumentAdmission::upload($session, [$this->pdf()]);
        $this->pending($session, $actor, $prepared);
        $this->expectException(DocumentAdmissionConflict::class);
        app(UploadEstimateGenerationDocuments::class)->handle($session, 0, [$this->pdf()], $actor, (string) Str::uuid());
    }

    public function test_cancel_between_intent_commit_and_storage_prevents_publication_and_returns_slots(): void
    {
        [$actor, $session] = $this->fixture();
        $puts = 0;
        (new InMemoryS3HttpStorage)->install(static function (RequestInterface $request) use (&$puts): ?Response {
            if ($request->getMethod() === 'PUT') {
                $puts++;
            }

            return null;
        });
        try {
            app(DocumentAdmissionOperation::class)->run($session, 0, $actor, (string) Str::uuid(), 'upload', hash('sha256', 'cancel fixture'),
                function (EstimateGenerationSession $current): PreparedDocumentAdmission {
                    DB::afterCommit(static fn () => DB::table('estimate_generation_sessions')->where('id', $current->id)->update(['status' => 'cancelled']));

                    return PreparedDocumentAdmission::upload($current, [$this->pdf()]);
                }, static function (): never {
                    self::fail('Cancelled intent reached publication.');
                });
            self::fail('Cancelled intent accepted.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationState) {
            self::assertSame(0, $puts);
        }
        self::assertSame('failed', DB::table(DocumentAdmissionOperation::TABLE)->where('session_id', $session->id)->value('status'));
        self::assertSame(0, $session->documents()->count());
    }

    public function test_reuse_receipt_replays_after_version_changed_and_preserves_original_object(): void
    {
        [$actor, $source] = $this->fixture();
        (new InMemoryS3HttpStorage)->install();
        Queue::fake();
        $original = app(UploadEstimateGenerationDocuments::class)->handle($source, 0, [$this->pdf()], $actor, (string) Str::uuid())->documents->first();
        $target = EstimateGenerationSession::query()->create(['organization_id' => $source->organization_id, 'project_id' => $source->project_id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => []]);
        $request = (string) Str::uuid();
        $first = app(ReuseEstimateGenerationDocuments::class)->handle($target, 0, (int) $source->id, $actor, $request);
        $second = app(ReuseEstimateGenerationDocuments::class)->handle($target->fresh(), 0, (int) $source->id, $actor, $request);
        self::assertSame($first->documents->pluck('id')->all(), $second->documents->pluck('id')->all());
        self::assertSame(1, $target->documents()->count());
        self::assertTrue(Storage::disk('s3')->fileExists($original->storage_path));
        self::assertNotSame($original->storage_path, $first->documents->first()->storage_path);
        app(RecoverUnpublishedDocumentObjects::class)->handle();
        self::assertSame(0, DB::table(UnpublishedDocumentCleanupJournal::TABLE)->where('session_id', $target->id)->count());
        self::assertTrue(Storage::disk('s3')->fileExists($original->storage_path));
    }

    private function pending(EstimateGenerationSession $session, User $actor, PreparedDocumentAdmission $prepared): int
    {
        return DB::table(DocumentAdmissionOperation::TABLE)->insertGetId(['organization_id' => $session->organization_id,
            'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $actor->id,
            'request_id' => (string) Str::uuid(), 'kind' => 'upload', 'expected_version' => 0, 'request_hash' => hash('sha256', 'abandoned fixture'),
            'reserved_slots' => count($prepared->objects), 'objects' => json_encode($prepared->snapshot(), JSON_THROW_ON_ERROR),
            'document_ids' => '[]', 'status' => 'pending', 'expires_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_reuse_checks_content_checksum_even_if_the_copied_size_matches(): void
    {
        [$actor, $source] = $this->fixture();
        $targetPrefix = null;
        $damaged = substr(file_get_contents(base_path('tests/Fixtures/EstimateGeneration/ocr/vector-plan.pdf')), 0, -1).'!';
        (new InMemoryS3HttpStorage)->install(static function (RequestInterface $request) use (&$targetPrefix, $damaged): ?Response {
            if ($targetPrefix !== null && $request->getMethod() === 'GET'
                && str_contains(rawurldecode($request->getUri()->getPath()), $targetPrefix)
                && ! str_contains($request->getUri()->getQuery(), 'acl')) {
                return new Response(200, ['Content-Type' => 'application/pdf', 'Content-Length' => (string) strlen($damaged)], $damaged);
            }

            return null;
        });
        Queue::fake();
        $original = app(UploadEstimateGenerationDocuments::class)->handle($source, 0, [$this->pdf()], $actor, (string) Str::uuid())->documents->first();
        $target = EstimateGenerationSession::query()->create(['organization_id' => $source->organization_id, 'project_id' => $source->project_id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => []]);
        $targetPrefix = 'org-'.$target->organization_id.'/estimate-generation/sessions/'.$target->id.'/documents/';
        $requestId = (string) Str::uuid();
        try {
            app(ReuseEstimateGenerationDocuments::class)->handle($target, 0, (int) $source->id, $actor, $requestId);
            self::fail('A same-size object with a different checksum was published.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException $error) {
            self::assertSame('document_storage_integrity_failed', $error->getMessage());
        }
        self::assertSame(0, $target->documents()->count());
        self::assertSame('failed', DB::table(DocumentAdmissionOperation::TABLE)->where('request_id', $requestId)->value('status'));
        self::assertTrue(Storage::disk('s3')->fileExists($original->storage_path));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $target->id)->count());
    }

    private function pdf(string $name = 'plan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/Fixtures/EstimateGeneration/ocr/vector-plan.pdf')));
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
