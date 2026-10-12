<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RecoverStalledEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UploadEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\InMemoryS3HttpStorage;

final class DocumentUploadDispatchPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_queue_failure_after_commit_preserves_accepted_upload_and_recovery_reuses_the_same_attempt(): void
    {
        (new InMemoryS3HttpStorage)->install();
        $this->seedGlobalSettings();
        config(['cache.default' => 'array']);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => []]);
        $originalDispatcher = app(Dispatcher::class);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue-canary signed-private-url'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        Log::spy();
        try {
            $result = app(UploadEstimateGenerationDocuments::class)->handle($session, 0,
                [UploadedFile::fake()->createWithContent('plan.pdf', file_get_contents(base_path('tests/Fixtures/EstimateGeneration/ocr/vector-plan.pdf')))], $actor);
        } finally {
            $this->app->instance(Dispatcher::class, $originalDispatcher);
        }
        self::assertCount(1, $result->documents);
        $document = $result->documents->sole();
        self::assertSame('queued', $document->fresh()->status);
        self::assertSame(1, $session->fresh()->state_version);
        self::assertSame(1, EstimateGenerationDocument::query()->where('session_id', $session->id)->count());
        $attempt = $document->meta['processing_attempt_id'];
        self::assertNotEmpty($attempt);
        Log::shouldHaveReceived('warning')->with('estimate_generation.document_manifest_dispatch_deferred', Mockery::on(
            static fn (array $context): bool => $context['failure_code'] === 'document_queue_unavailable'
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'canary')
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'signed-private-url')))->once();
        Queue::fake();
        DB::table('estimate_generation_documents')->where('id', $document->id)->update(['updated_at' => now()->subMinutes(3)]);
        self::assertSame(1, app(RecoverStalledEstimateGenerationDocuments::class)->handle());
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 1);
        self::assertSame($attempt, $document->fresh()->meta['processing_attempt_id']);
        self::assertSame(1, EstimateGenerationDocument::query()->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    private function seedGlobalSettings(): void
    {
        $snapshot = \App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData::fromArray([
            'scope' => 'global', 'organization_id' => null, 'expected_version' => 0, 'idempotency_key' => (string) \Illuminate\Support\Str::ulid(),
            'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'openai/gpt-6-luna', 'normative_matching' => 'openai/gpt-6-luna'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
            'retries' => ['vision' => 0, 'classification' => 0, 'normative_matching' => 0],
            'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
            'enabled_formats' => ['pdf', 'png'], 'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB']])->snapshot();
        $hash = \App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash::calculate($snapshot);
        $adminId = DB::table('system_admins')->insertGetId(['name' => 'Fixture', 'email' => \Illuminate\Support\Str::uuid().'@example.test',
            'password' => 'not-used', 'created_at' => now(), 'updated_at' => now()]);
        $snapshotId = DB::table('estimate_generation_setting_snapshots')->insertGetId(['scope' => 'global', 'organization_id' => null,
            'version' => 1, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_hash' => $hash,
            'daily_budget' => '250.00', 'monthly_budget' => '4000.00', 'currency' => 'RUB',
            'created_by_system_admin_id' => $adminId, 'created_at' => now()]);
        DB::table('estimate_generation_setting_snapshot_hashes')->insert(['setting_snapshot_id' => $snapshotId,
            'algorithm' => 'jcs-sha256-v1', 'snapshot_hash' => $hash, 'created_at' => now()]);
    }
}
