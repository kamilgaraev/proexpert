<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReadDocumentAdmission;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UploadEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDocumentAdmissionController;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\InMemoryS3HttpStorage;

final class DocumentAdmissionReaderPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_reading_the_accepted_request_returns_scoped_documents_without_uploading_processing_or_spending(): void
    {
        [$actor, $session] = $this->fixture();
        (new InMemoryS3HttpStorage)->install();
        Queue::fake();
        $requestId = (string) Str::uuid();
        $bytes = file_get_contents(base_path('tests/Fixtures/EstimateGeneration/ocr/vector-plan.pdf'));
        $result = app(UploadEstimateGenerationDocuments::class)->handle($session, 0,
            [UploadedFile::fake()->createWithContent('plan.pdf', $bytes)], $actor, $requestId);
        $before = $session->fresh()->state_version;
        $status = app(ReadDocumentAdmission::class)->handle($session, (int) $session->project_id, $actor, $requestId);
        self::assertSame('accepted', $status->status);
        self::assertSame($result->documents->pluck('id')->all(), $status->result->documents->pluck('id')->all());
        self::assertSame($before, $session->fresh()->state_version);
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 1);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        $project = Project::query()->findOrFail($session->project_id);
        $http = \Illuminate\Http\Request::create('/fixture', 'GET');
        $http->setUserResolver(static fn () => $actor);
        $response = app(EstimateGenerationDocumentAdmissionController::class)->show($http, $project, $session, $requestId);
        $data = $response->getData(true);
        self::assertSame(true, $data['success']);
        self::assertSame($requestId, $data['data']['request_id']);
        self::assertSame('accepted', $data['data']['status']);
        self::assertCount(1, $data['data']['documents']);
        self::assertArrayNotHasKey('objects', $data['data']);
        self::assertArrayNotHasKey('storage_path', $data['data']['documents'][0]);
    }

    public function test_a_different_actor_session_or_project_cannot_read_the_receipt_and_revoked_view_blocks_reading(): void
    {
        [$actor, $session] = $this->fixture();
        (new InMemoryS3HttpStorage)->install();
        Queue::fake();
        $requestId = (string) Str::uuid();
        $bytes = file_get_contents(base_path('tests/Fixtures/EstimateGeneration/ocr/vector-plan.pdf'));
        app(UploadEstimateGenerationDocuments::class)->handle($session, 0,
            [UploadedFile::fake()->createWithContent('plan.pdf', $bytes)], $actor, $requestId);
        $other = User::factory()->create(['is_active' => true, 'current_organization_id' => $session->organization_id]);
        $other->organizations()->attach($session->organization_id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        self::assertSame('not_found', app(ReadDocumentAdmission::class)->handle($session, (int) $session->project_id, $other, $requestId)->status);
        $target = EstimateGenerationSession::query()->create(['organization_id' => $session->organization_id, 'project_id' => $session->project_id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => []]);
        self::assertSame('not_found', app(ReadDocumentAdmission::class)->handle($target, (int) $target->project_id, $actor, $requestId)->status);
        try {
            app(ReadDocumentAdmission::class)->handle($session, (int) $session->project_id + 100000, $actor, $requestId);
            self::fail('Foreign project accepted.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            self::assertTrue(true);
        }
        $denied = Mockery::mock(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization::class);
        $denied->shouldReceive('authorize')->with($actor, Mockery::type(EstimateGenerationSession::class), 'estimate_generation.view')
            ->once()->andThrow(new \Illuminate\Auth\Access\AuthorizationException);
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization::class, $denied);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(ReadDocumentAdmission::class)->handle($session, (int) $session->project_id, $actor, $requestId);
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
