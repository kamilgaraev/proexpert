<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentAdmissionOperation;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDocumentController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\UploadEstimateGenerationDocumentsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\InMemoryS3HttpStorage;

final class DocumentAdmissionHttpPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_file_content_admission_rejection_is_422_with_export_instructions_before_storage(): void
    {
        [$actor, $session] = $this->fixture();
        $puts = 0;
        (new InMemoryS3HttpStorage)->install(static function (RequestInterface $request) use (&$puts): ?Response {
            if ($request->getMethod() === 'PUT') {
                $puts++;
            }

            return null;
        });
        $requestId = (string) Str::uuid();
        $request = UploadEstimateGenerationDocumentsRequest::create('/fixture', 'POST',
            ['state_version' => 0, 'request_id' => $requestId], [], ['files' => [UploadedFile::fake()->createWithContent('drawing.pdf', 'unrecognized file bytes')]]);
        $request->setUserResolver(static fn () => $actor);
        // Exercise server admission after the HTTP validator, as callers outside HTTP must be protected too.
        $request->setValidator(app('validator')->make(['state_version' => 0, 'request_id' => $requestId],
            ['state_version' => ['required', 'integer'], 'request_id' => ['required', 'uuid']]));
        $response = app(EstimateGenerationDocumentController::class)->upload($request, Project::query()->findOrFail($session->project_id), $session);
        self::assertSame(422, $response->getStatusCode());
        $data = $response->getData(true);
        self::assertSame(false, $data['success']);
        self::assertStringContainsString('Проверьте экспорт', $data['errors']['files'][0]);
        self::assertSame(0, $puts);
        self::assertSame(0, $session->documents()->count());
        self::assertSame(0, DB::table(DocumentAdmissionOperation::TABLE)->where('session_id', $session->id)->count());
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

    public function test_file_slot_limit_is_a_clear_rejection_without_an_unknown_request_or_storage_write(): void
    {
        [$actor, $session] = $this->fixture();
        $puts = 0;
        (new InMemoryS3HttpStorage)->install(static function (RequestInterface $request) use (&$puts): ?Response {
            if ($request->getMethod() === 'PUT') {
                $puts++;
            }

            return null;
        });
        $requestId = (string) Str::uuid();
        $bytes = file_get_contents(base_path('tests/Fixtures/EstimateGeneration/ocr/vector-plan.pdf'));
        $files = array_map(static fn (int $index): UploadedFile => UploadedFile::fake()->createWithContent('plan-'.$index.'.pdf', $bytes), range(1, 9));
        $request = UploadEstimateGenerationDocumentsRequest::create('/fixture', 'POST',
            ['state_version' => 0, 'request_id' => $requestId], [], ['files' => $files]);
        $request->setUserResolver(static fn () => $actor);
        $request->setValidator(app('validator')->make(['state_version' => 0, 'request_id' => $requestId],
            ['state_version' => ['required', 'integer'], 'request_id' => ['required', 'uuid']]));
        $response = app(EstimateGenerationDocumentController::class)->upload($request, Project::query()->findOrFail($session->project_id), $session);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('не более 8 файлов', $response->getData(true)['errors']['files'][0]);
        self::assertSame(0, $puts);
        self::assertSame(0, $session->documents()->count());
        self::assertSame(0, DB::table(DocumentAdmissionOperation::TABLE)->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
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
