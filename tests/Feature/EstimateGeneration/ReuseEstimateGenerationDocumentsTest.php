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
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

final class ReuseEstimateGenerationDocumentsTest extends TestCase
{
    public function test_documents_are_copied_as_fresh_sources_and_reuse_is_idempotent(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $source = $this->makeSession($organization, $project, $user);
        $target = $this->makeSession($organization, $project, $user);
        $sourceDocuments = collect([
            $this->makeDocument($source, 'first-floor.jpg', 'jpg', 1024, str_repeat('a', 64)),
            $this->makeDocument($source, 'house.dwg', 'dwg', 2048, str_repeat('b', 64)),
        ]);
        $this->app->instance(EffectiveSettingsResolver::class, $this->settingsResolver((int) $organization->id));
        $copyIndex = 0;
        $this->mock(FileService::class, function (MockInterface $mock) use (&$copyIndex, $sourceDocuments): void {
            $mock->shouldReceive('duplicateEstimateGenerationObject')
                ->twice()
                ->andReturnUsing(function (string $sourcePath, string $destinationPath) use (&$copyIndex, $sourceDocuments): array {
                    $document = $sourceDocuments[$copyIndex++];
                    self::assertSame($document->storage_path, $sourcePath);
                    self::assertStringContainsString('/estimate-generation/sessions/', $destinationPath);

                    return [
                        'path' => $destinationPath,
                        'size' => (int) $document->file_size_bytes,
                        'version_id' => 'version-'.$copyIndex,
                    ];
                });
        });

        $result = app(ReuseEstimateGenerationDocuments::class)->handle(
            $target,
            (int) $target->state_version,
            (int) $source->id,
            $user,
        );

        self::assertCount(2, $result->documents);
        self::assertSame(2, $target->documents()->count());
        $cloned = $target->documents()->orderBy('id')->get();
        self::assertSame(['queued', 'queued'], $cloned->pluck('status')->all());
        self::assertSame(['stored', 'stored'], $cloned->pluck('processing_stage')->all());
        self::assertSame($sourceDocuments->pluck('id')->all(), $cloned->pluck('meta')->map(
            static fn (array $meta): int => (int) $meta['reused_from_document_id'],
        )->all());
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 2);

        $freshTarget = $target->freshOrFail();
        $second = app(ReuseEstimateGenerationDocuments::class)->handle(
            $freshTarget,
            (int) $freshTarget->state_version,
            (int) $source->id,
            $user,
        );

        self::assertCount(0, $second->documents);
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
        int $size,
        string $checksum,
    ): EstimateGenerationDocument {
        return EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $session->organization_id,
            'project_id' => $session->project_id,
            'user_id' => $session->user_id,
            'filename' => $filename,
            'mime_type' => $extension === 'jpg' ? 'image/jpeg' : 'image/vnd.dwg',
            'storage_path' => sprintf(
                'org-%d/estimate-generation/sessions/%d/documents/%s.%s',
                $session->organization_id,
                $session->id,
                $checksum,
                $extension,
            ),
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
                'storage_version_id' => 'source-version',
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
