<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReuseEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsOperationStore;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsPair;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Psr\Http\Message\RequestInterface;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\InMemoryS3HttpStorage;

final class ReuseEstimateGenerationDocumentsTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_documents_are_copied_as_fresh_sources_and_reuse_is_idempotent(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $user->organizations()->syncWithoutDetaching([$organization->id => ['is_active' => true, 'is_owner' => false, 'project_access_mode' => 'all_projects']]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $source = $this->makeSession($organization, $project, $user);
        $target = $this->makeSession($organization, $project, $user);
        $copies = 0;
        (new InMemoryS3HttpStorage)->install(static function (RequestInterface $request) use (&$copies): ?Response {
            if ($request->getMethod() === 'PUT' && $request->getHeaderLine('x-amz-copy-source') !== '') {
                $copies++;
            }

            return null;
        });
        $jpeg = UploadedFile::fake()->image('first-floor.jpg', 1, 1);
        $sourceDocuments = collect([
            $this->makeDocument($source, 'first-floor.jpg', 'jpg', file_get_contents($jpeg->getPathname())),
            $this->makeDocument($source, 'house.dwg', 'dwg', file_get_contents(base_path('tests/Fixtures/EstimateGeneration/Vision/simple-house.dwg'))),
        ]);
        $this->app->instance(EffectiveSettingsResolver::class, $this->settingsResolver((int) $organization->id));
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($organization, $project, $user): void {
            $mock->shouldReceive('canCurrent')
                ->times(6)
                ->withArgs(static fn (User $actor, string $permission, array $scope): bool => (int) $actor->id === (int) $user->id && $permission === 'estimate_generation.upload_documents'
                    && $scope === [
                        'organization_id' => (int) $organization->id,
                        'project_id' => (int) $project->id,
                    ])
                ->andReturnTrue();
        });
        $result = app(ReuseEstimateGenerationDocuments::class)->handle(
            $target,
            (int) $target->state_version,
            (int) $source->id,
            $user,
        );

        self::assertCount(2, $result->documents);
        self::assertSame(2, $copies);
        self::assertSame(2, $target->documents()->count());
        $cloned = $target->documents()->orderBy('id')->get();
        self::assertSame(['queued', 'queued'], $cloned->pluck('status')->all());
        self::assertSame(['stored', 'stored'], $cloned->pluck('processing_stage')->all());
        self::assertSame($sourceDocuments->pluck('id')->all(), $cloned->pluck('meta')->map(
            static fn (array $meta): int => (int) $meta['reused_from_document_id'],
        )->all());
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 2);

        $freshTarget = EstimateGenerationSession::query()->findOrFail($target->id);
        $second = app(ReuseEstimateGenerationDocuments::class)->handle(
            $freshTarget,
            (int) $freshTarget->state_version,
            (int) $source->id,
            $user,
        );

        self::assertCount(0, $second->documents);
        self::assertSame(2, $copies);
        self::assertSame(2, $target->documents()->count());
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 2);
    }

    private function makeSession(Organization $organization, Project $project, User $user): EstimateGenerationSession
    {
        return EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'state_version' => 0,
            'input_payload' => ['description' => 'Двухэтажный жилой дом'],
            'problem_flags' => [],
        ]);
    }

    private function makeDocument(
        EstimateGenerationSession $session,
        string $filename,
        string $extension,
        string $content,
    ): EstimateGenerationDocument {
        $size = strlen($content);
        $checksum = hash('sha256', $content);
        $path = 'org-'.$session->organization_id.'/estimate-generation/sessions/'.$session->id.'/documents/'.Str::uuid().'.'.$extension;
        app(FileService::class)->putImmutable($path, $content, $extension === 'jpg' ? 'image/jpeg' : 'image/vnd.dwg');

        return EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $session->organization_id,
            'project_id' => $session->project_id,
            'user_id' => $session->user_id,
            'filename' => $filename,
            'mime_type' => $extension === 'jpg' ? 'image/jpeg' : 'image/vnd.dwg',
            'storage_path' => $path,
            'status' => 'ready',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'file_size_bytes' => $size,
            'checksum_sha256' => $checksum,
            'source_version' => 'sha256:'.$checksum,
            'processed_page_count' => 1,
            'ocr_attempts' => 1,
            'structured_payload' => ['stale' => true],
            'meta' => [
                'original_extension' => $extension,
                'original_name' => $filename,
                'storage_sha256' => $checksum,
            ],
        ]);
    }

    private function settingsResolver(int $organizationId): EffectiveSettingsResolver
    {
        $snapshot = [
            'schema_version' => 2,
            'models' => ['vision' => 'provider/vision', 'classification' => 'provider/classification', 'normative_matching' => 'provider/normative'],
            'limits' => ['max_files' => 10, 'max_pages_per_file' => 200, 'max_total_pages' => 1000],
            'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
            'retries' => ['vision' => 1, 'classification' => 1, 'normative_matching' => 1],
            'confidence' => ['classification' => '0.7000', 'geometry' => '0.7000', 'normative_matching' => '0.7000'],
            'enabled_formats' => ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'dwg', 'dxf'],
            'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '100.00', 'monthly' => '1000.00', 'currency' => 'RUB'],
        ];
        $settings = EffectiveEstimateGenerationSettings::fromRecord([
            'snapshot_id' => 1,
            'scope' => 'organization',
            'organization_id' => $organizationId,
            'version' => 1,
            'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot),
            'snapshot' => $snapshot,
        ], $organizationId);
        $store = new class($settings) implements EffectiveSettingsOperationStore
        {
            public function __construct(private readonly EffectiveEstimateGenerationSettings $settings) {}

            public function pin(string $correlationId, int $organizationId, int $sessionId): EffectiveSettingsPair
            {
                return new EffectiveSettingsPair($this->settings, $this->settings);
            }
        };

        return new EffectiveSettingsResolver($store);
    }
}
