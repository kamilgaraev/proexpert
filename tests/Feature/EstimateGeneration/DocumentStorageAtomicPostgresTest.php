<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReuseEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UploadEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrDocumentStorageService;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsOperationStore;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsPair;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Support\EstimateGeneration\EstimateGenerationApplicationTestCase;

final class DocumentStorageAtomicPostgresTest extends EstimateGenerationApplicationTestCase
{
    private string $schema;

    private array $objects = [];

    private array $deleted = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('pgsql', DB::getDriverName());
        self::assertStringEndsWith('_testing', DB::connection()->getDatabaseName());
        $this->schema = 'most_storage_atomic_'.bin2hex(random_bytes(8));
        DB::unprepared('CREATE SCHEMA "'.$this->schema.'"');
        DB::unprepared('SET search_path TO "'.$this->schema.'"');
        DB::unprepared(<<<'SQL'
            CREATE TABLE organizations (id bigint PRIMARY KEY, deleted_at timestamp NULL);
            CREATE TABLE estimate_generation_sessions (
                id bigint PRIMARY KEY, organization_id bigint NOT NULL, project_id bigint NOT NULL,
                user_id bigint NOT NULL, status varchar(32) NOT NULL,
                processing_stage varchar(80), processing_progress integer NOT NULL DEFAULT 0,
                state_version integer NOT NULL DEFAULT 0, resume_status varchar(32),
                input_payload jsonb NOT NULL DEFAULT '{}', analysis_payload jsonb NOT NULL DEFAULT '{}',
                last_error text, failure_code text, created_at timestamp, updated_at timestamp
            );
            CREATE TABLE estimate_generation_documents (
                id bigserial PRIMARY KEY, organization_id bigint NOT NULL, project_id bigint NOT NULL,
                session_id bigint NOT NULL, user_id bigint NOT NULL,
                filename text NOT NULL, mime_type text, storage_path text NOT NULL,
                status varchar(32), processing_stage varchar(80), progress_percent integer,
                processing_control_status varchar(32) NOT NULL DEFAULT 'active',
                file_size_bytes bigint, checksum_sha256 varchar(64), source_version varchar(80),
                processed_page_count integer, ocr_attempts integer,
                structured_payload jsonb NOT NULL DEFAULT '{}', meta jsonb NOT NULL DEFAULT '{}',
                created_at timestamp, updated_at timestamp
            );
            CREATE TABLE estimate_generation_processing_units (id bigint PRIMARY KEY, document_id bigint NOT NULL);
            INSERT INTO organizations (id) VALUES (10);
            INSERT INTO estimate_generation_sessions
                (id, organization_id, project_id, user_id, status)
                VALUES (30, 10, 20, 40, 'processing_documents'), (31, 10, 20, 40, 'draft');
            SQL);
        Queue::fake();
        Event::fake([static fn (string $event): bool => str_ends_with($event, ': '.EstimateGenerationDocument::class)]);
        $authorization = $this->createMock(EstimateGenerationActionAuthorization::class);
        $authorization->method('authorize')->willReturnCallback(static function (): void {});
        $this->app->instance(EstimateGenerationActionAuthorization::class, $authorization);
        $readiness = Mockery::mock(DocumentGenerationReadinessService::class);
        $readiness->shouldReceive('evaluate')->andReturn(['summary' => ['pending_count' => 1]]);
        $this->app->instance(DocumentGenerationReadinessService::class, $readiness);
        $this->app->instance(EffectiveSettingsResolver::class, $this->settings());
    }

    protected function tearDown(): void
    {
        if (isset($this->schema) && preg_match('/^most_storage_atomic_[a-f0-9]{16}$/D', $this->schema) === 1) {
            DB::unprepared('SET search_path TO public');
            DB::unprepared('DROP SCHEMA "'.$this->schema.'" CASCADE');
        }
        parent::tearDown();
    }

    public function test_failure_of_second_upload_rolls_back_rows_version_jobs_and_new_objects(): void
    {
        $this->storage(failHeadAt: 2);
        try {
            app(UploadEstimateGenerationDocuments::class)->handle($this->generationSession(), 0, $this->files(), $this->actor());
            self::fail('Expected the second object verification to fail.');
        } catch (TypedFailureException) {
            self::assertSame(0, EstimateGenerationDocument::query()->count());
            self::assertSame(0, $this->generationSession()->state_version);
            self::assertCount(2, $this->deleted);
            self::assertSame([], $this->objects);
            Queue::assertNothingPushed();
        }
    }

    public function test_revoked_permission_after_storage_rolls_back_the_entire_batch(): void
    {
        $this->storage();
        $checks = 0;
        $authorization = $this->createMock(EstimateGenerationActionAuthorization::class);
        $authorization->expects(self::exactly(2))->method('authorize')->willReturnCallback(static function () use (&$checks): void {
            if (++$checks === 2) {
                throw new AuthorizationException('revoked');
            }
        });
        $this->app->instance(EstimateGenerationActionAuthorization::class, $authorization);
        try {
            app(UploadEstimateGenerationDocuments::class)->handle($this->generationSession(), 0, $this->files(), $this->actor());
            self::fail('Expected fresh authorization to reject publication.');
        } catch (AuthorizationException) {
            self::assertSame(0, EstimateGenerationDocument::query()->count());
            self::assertSame(0, $this->generationSession()->state_version);
            self::assertCount(2, $this->deleted);
            self::assertSame([], $this->objects);
            Queue::assertNothingPushed();
        }
    }

    public function test_stale_upload_is_rejected_before_storage_and_nonempty_batch_advances_processing_version(): void
    {
        $this->storage();
        $stale = $this->generationSession();
        $result = app(UploadEstimateGenerationDocuments::class)->handle($stale, 0, [$this->files()[0]], $this->actor());
        self::assertCount(1, $result->documents);
        self::assertSame(1, $this->generationSession()->state_version);
        try {
            app(UploadEstimateGenerationDocuments::class)->handle($stale, 0, [$this->files()[1]], $this->actor());
            self::fail('Expected stale state rejection.');
        } catch (StaleEstimateGenerationState) {
            self::assertSame(1, EstimateGenerationDocument::query()->count());
            self::assertCount(1, $this->objects);
            self::assertSame([], $this->deleted);
        }
    }

    public function test_reuse_failure_cleans_only_new_destinations_and_preserves_original_sources(): void
    {
        $this->storage(failCopyAt: 2);
        foreach (['one', 'two'] as $name) {
            $path = 'org-10/estimate-generation/sessions/31/documents/'.$name.'.pdf';
            $this->objects[$path] = 10;
            EstimateGenerationDocument::query()->create([
                'organization_id' => 10, 'project_id' => 20, 'session_id' => 31, 'user_id' => 40,
                'filename' => $name.'.pdf', 'mime_type' => 'application/pdf', 'storage_path' => $path,
                'status' => 'ready', 'file_size_bytes' => 10, 'checksum_sha256' => hash('sha256', $name),
                'source_version' => 'sha256:'.hash('sha256', $name), 'meta' => ['original_extension' => 'pdf'],
            ]);
        }
        try {
            app(ReuseEstimateGenerationDocuments::class)->handle($this->generationSession(), 0, 31, $this->actor());
            self::fail('Expected copy verification failure.');
        } catch (TypedFailureException) {
            self::assertSame(0, $this->generationSession()->documents()->count());
            self::assertSame(2, EstimateGenerationDocument::query()->count());
            self::assertCount(2, $this->objects);
            self::assertCount(2, $this->deleted);
            foreach ($this->deleted as $path) {
                self::assertStringStartsWith('org-10/estimate-generation/sessions/30/documents/', $path);
            }
            self::assertSame(0, $this->generationSession()->state_version);
            Queue::assertNothingPushed();
        }
    }

    public function test_second_upload_refreshes_unfinished_manifest_jobs_without_rotating_the_first_lineage(): void
    {
        $this->storage();
        $first = app(UploadEstimateGenerationDocuments::class)->handle($this->generationSession(), 0, [$this->files()[0]], $this->actor());
        $firstDocument = $first->documents->sole();
        $lineage = $firstDocument->meta['processing_attempt_id'];
        app(UploadEstimateGenerationDocuments::class)->handle($this->generationSession(), 1, [$this->files()[1]], $this->actor());
        self::assertSame(2, $this->generationSession()->state_version);
        self::assertSame($lineage, $firstDocument->fresh()->meta['processing_attempt_id']);
        $jobs = Queue::pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob::class);
        self::assertCount(3, $jobs);
        self::assertSame([1, 2, 2], $jobs->map(static fn ($job): int => (new \ReflectionProperty($job, 'failureSnapshot'))->getValue($job)->stateVersion)->all());
        self::assertCount(2, $this->objects);
        self::assertSame([], $this->deleted);
    }

    public function test_new_user_upload_cannot_borrow_an_earlier_platform_administrator_identity(): void
    {
        $this->storage();
        $session = $this->generationSession();
        $session->forceFill(['input_payload' => ['generation_actor_type' => 'system_admin', 'generation_actor_id' => 99,
            'generation_requested' => false, 'generation_attempt_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']])->saveQuietly();
        app(UploadEstimateGenerationDocuments::class)->handle($session, 0, [$this->files()[0]], $this->actor());
        $input = $this->generationSession()->input_payload;
        self::assertSame('user', $input['generation_actor_type']);
        self::assertSame(40, $input['generation_actor_id']);
        self::assertNull($input['generation_attempt_id']);
        self::assertSame(1, $this->generationSession()->state_version);
    }

    public function test_compensation_never_deletes_a_referenced_or_foreign_object(): void
    {
        $service = $this->storage();
        $document = EstimateGenerationDocument::query()->create([
            'organization_id' => 10, 'project_id' => 20, 'session_id' => 30, 'user_id' => 40,
            'filename' => 'saved.pdf', 'storage_path' => 'org-10/estimate-generation/sessions/30/documents/saved.pdf',
        ]);
        self::assertFalse($service->removeUnpublishedDocumentObject($this->generationSession(), $document));
        foreach (['org-11/estimate-generation/sessions/30/documents/foreign.pdf',
            'org-10/estimate-generation/sessions/31/documents/source.pdf',
            'org-10/estimate-generation/sessions/30/documents/../source.pdf'] as $path) {
            $document->storage_path = $path;
            self::assertFalse($service->removeUnpublishedDocumentObject($this->generationSession(), $document));
        }
        self::assertSame([], $this->deleted);
    }

    private function generationSession(): EstimateGenerationSession
    {
        return EstimateGenerationSession::query()->findOrFail(30);
    }

    private function actor(): User
    {
        $actor = new User;
        $actor->id = 40;

        return $actor;
    }

    private function files(): array
    {
        return [UploadedFile::fake()->createWithContent('one.pdf', 'first'),
            UploadedFile::fake()->createWithContent('two.pdf', 'second')];
    }

    private function storage(int $failHeadAt = 0, int $failCopyAt = 0): OcrDocumentStorageService
    {
        $uploads = 0;
        $heads = 0;
        $copies = 0;
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('upload')->andReturnUsing(function (UploadedFile $file) use (&$uploads): string {
            $path = 'org-10/estimate-generation/sessions/30/documents/upload-'.++$uploads.'.pdf';
            $this->objects[$path] = (int) $file->getSize();

            return $path;
        });
        $files->shouldReceive('describeHead')->andReturnUsing(function (string $path) use (&$heads, $failHeadAt): array {
            if (++$heads === $failHeadAt) {
                throw new RuntimeException('storage unavailable');
            }

            return ['size' => $this->objects[$path]];
        });
        $files->shouldReceive('duplicateEstimateGenerationObject')->andReturnUsing(function (string $source, string $destination) use (&$copies, $failCopyAt): array {
            $this->objects[$destination] = $this->objects[$source];

            return ['path' => $destination, 'size' => ++$copies === $failCopyAt ? 0 : $this->objects[$source]];
        });
        $files->shouldReceive('removeImmutable')->andReturnUsing(function (string $path): void {
            $this->deleted[] = $path;
            unset($this->objects[$path]);
        });
        $service = new OcrDocumentStorageService($files);
        $this->app->instance(OcrDocumentStorageService::class, $service);

        return $service;
    }

    private function settings(): EffectiveSettingsResolver
    {
        $snapshot = [
            'schema_version' => 2,
            'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'openai/gpt-6-luna', 'normative_matching' => 'openai/gpt-6-luna'],
            'limits' => ['max_files' => 10, 'max_pages_per_file' => 200, 'max_total_pages' => 1000],
            'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
            'retries' => ['vision' => 1, 'classification' => 1, 'normative_matching' => 1],
            'confidence' => ['classification' => '0.7000', 'geometry' => '0.7000', 'normative_matching' => '0.7000'],
            'enabled_formats' => ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'dwg', 'dxf'],
            'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '100.00', 'monthly' => '1000.00', 'currency' => 'RUB'],
        ];
        $settings = EffectiveEstimateGenerationSettings::fromRecord([
            'snapshot_id' => 1, 'scope' => 'organization', 'organization_id' => 10, 'version' => 1,
            'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot), 'snapshot' => $snapshot,
        ], 10);
        $store = new class($settings) implements EffectiveSettingsOperationStore
        {
            public function __construct(private EffectiveEstimateGenerationSettings $settings) {}

            public function pin(string $correlationId, int $organizationId, int $sessionId): EffectiveSettingsPair
            {
                return new EffectiveSettingsPair($this->settings, $this->settings);
            }
        };

        return new EffectiveSettingsResolver($store);
    }
}
