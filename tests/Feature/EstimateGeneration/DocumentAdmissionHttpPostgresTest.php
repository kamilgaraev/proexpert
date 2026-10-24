<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentAdmissionOperation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\PreparedDocumentAdmission;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionOperationalSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDocumentController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\UploadEstimateGenerationDocumentsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionListResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\AuthSessionStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\InMemoryS3HttpStorage;

final class DocumentAdmissionHttpPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_owner_snapshot_offers_review_document_upload_through_real_authorization_without_explicit_grants(): void
    {
        config(['cache.default' => 'array', 'web_auth.origins.admin' => ['https://admin.fixture.test']]);
        Http::fake();
        Http::preventStrayRequests();
        Queue::fake();
        $this->seedGlobalSettings();
        $rights = AssistantRealAuthorizationFixture::create(['working-entry']);
        $owner = $rights->owner;
        $project = Project::factory()->for($rights->organization)->create(['is_archived' => false, 'address' => null]);
        AuthorizationContext::getProjectContext((int) $project->id, (int) $rights->organization->id);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $rights->organization->id, 'project_id' => $project->id,
            'user_id' => $owner->id, 'status' => 'estimate_review_required', 'state_version' => 8,
            'input_payload' => ['object_name' => 'Owner snapshot context fixture', 'generation_requested' => false],
            'draft_payload' => ['local_estimates' => []]]);
        self::assertSame('organization_owner', $rights->ownerAssignment->role_slug);
        self::assertSame('system', $rights->ownerAssignment->role_type);
        self::assertSame('active', $rights->subscription->status->value);
        self::assertSame('paid_package', $rights->subscription->access_source->value);
        $membership = $owner->organizations()->whereKey($rights->organization->id)->firstOrFail()->pivot;
        self::assertTrue((bool) $membership->is_owner);
        self::assertSame('all_projects', $membership->project_access_mode);
        $authorization = app(AuthorizationService::class);
        self::assertFalse($authorization instanceof \Mockery\MockInterface);
        $context = ['organization_id' => (int) $rights->organization->id, 'project_id' => (int) $project->id];
        $diagnostics = [];
        foreach (['estimate_generation.view', 'estimate_generation.upload_documents'] as $permission) {
            foreach (['can', 'canCurrent'] as $method) {
                try {
                    $allowed = $authorization->{$method}($owner, $permission, $context);
                    $diagnostics[$permission][$method] = ['state' => $allowed ? 'allowed' : 'denied', 'exception' => null];
                } catch (\Throwable $exception) {
                    $diagnostics[$permission][$method] = ['state' => 'exception', 'exception' => $exception::class];
                }
            }
        }
        $base = '/api/v1/admin/projects/'.$project->id.'/estimate-generation/sessions/'.$session->id;
        $request = Request::create($base.'/snapshot', 'GET');
        $request->setUserResolver(static fn (): User => $owner);
        $permissions = EstimateGenerationSessionListResource::permissions($request, $session);
        $diagnostics['resource_permissions'] = $permissions;
        $snapshot = null;
        try {
            $snapshot = app(BuildSessionOperationalSnapshot::class)->handle($session, $permissions);
            $diagnostics['operational_snapshot'] = ['state' => 'returned', 'exception' => null];
        } catch (\Throwable $exception) {
            $diagnostics['operational_snapshot'] = ['state' => 'exception', 'exception' => $exception::class];
        }
        $headers = $this->headers($owner);
        self::assertArrayNotHasKey('If-None-Match', $headers);
        $response = $this->withHeaders($headers)->getJson($base.'/snapshot');
        $diagnostics['http_status'] = $response->status();
        $diagnostics['http_error_code'] = $response->json('error_code');
        $message = json_encode($diagnostics, JSON_THROW_ON_ERROR);
        self::assertSame(200, $response->status(), $message);
        self::assertNull($this->app['request']->header('If-None-Match'));
        foreach (['estimate_generation.view', 'estimate_generation.upload_documents'] as $permission) {
            self::assertSame(['state' => 'allowed', 'exception' => null], $diagnostics[$permission]['can'], $message);
            self::assertSame(['state' => 'allowed', 'exception' => null], $diagnostics[$permission]['canCurrent'], $message);
            self::assertContains($permission, $permissions, $message);
        }
        self::assertNotNull($snapshot, $message);
        $expected = ['action' => 'upload_documents', 'method' => 'POST', 'endpoint' => $base.'/documents', 'requires_confirmation' => true];
        $direct = array_values(array_filter($snapshot->availableActions, static fn (array $action): bool => $action['action'] === 'upload_documents'));
        $raw = array_values(array_filter($response->json('data.available_actions') ?? [], static fn (array $action): bool => $action['action'] === 'upload_documents'));
        self::assertCount(1, $direct, $message);
        self::assertCount(1, $raw, $message);
        self::assertEquals($expected, array_intersect_key($direct[0], $expected), $message);
        self::assertEquals($expected, array_intersect_key($raw[0], $expected), $message);
        self::assertSame('estimate_review_required', $session->fresh()->status->value);
        self::assertSame(8, $session->fresh()->state_version);
        foreach (['estimate_generation_ai_role_runs', 'estimate_generation_ai_usage', 'estimate_generation_credit_operations', DocumentAdmissionOperation::TABLE] as $table) {
            self::assertSame(0, DB::table($table)->where('session_id', $session->id)->count());
        }
        self::assertSame(0, $session->documents()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_review_upload_preserves_saved_context_and_replays_without_storage_or_automatic_generation(): void
    {
        [$rights, $session] = $this->httpFixture();
        Queue::fake();
        $puts = 0;
        (new InMemoryS3HttpStorage)->install(static function (RequestInterface $request) use (&$puts): ?Response {
            if ($request->getMethod() === 'PUT' && ! str_contains($request->getUri()->getQuery(), 'tagging')) $puts++;

            return null;
        });
        $base = '/api/v1/admin/projects/'.$session->project_id.'/estimate-generation/sessions/'.$session->id;
        $input = ['state_version' => 8, 'request_id' => (string) Str::uuid(), 'files' => [$this->pdf()]];
        $this->withHeader('Accept', 'application/json');
        $this->post($base.'/documents', $input)->assertUnauthorized();
        $denied = $rights->addMember(['ai-estimates' => ['estimate_generation.view']], ['admin.access']);
        $denied->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        $this->withHeaders($this->headers($denied))->post($base.'/documents', $input)->assertForbidden();
        $foreign = $this->withHeaders($this->headers($rights->foreignOwner))->post($base.'/documents', $input);
        self::assertContains($foreign->status(), [403, 404]);
        self::assertSame(0, $puts);
        self::assertSame(0, DB::table(DocumentAdmissionOperation::TABLE)->where('session_id', $session->id)->count());
        $draft = CanonicalPipelineJson::encode($session->draft_payload);
        $accepted = $this->withHeaders($this->headers($rights->member))->post($base.'/documents', $input)->assertOk();
        self::assertSame('processing_documents', $session->fresh()->status->value);
        self::assertSame($draft, CanonicalPipelineJson::encode($session->fresh()->draft_payload));
        self::assertSame('Сохранённое описание проекта', $session->fresh()->input_payload['object_name']);
        self::assertFalse($session->fresh()->input_payload['generation_requested']);
        $version = $session->fresh()->state_version;
        $replayed = $this->post($base.'/documents', $input)->assertOk();
        self::assertSame($accepted->json('data.documents.0.id'), $replayed->json('data.documents.0.id'));
        self::assertSame($version, $session->fresh()->state_version);
        self::assertSame(1, $puts);
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 1);
        Queue::assertNotPushed(GenerateEstimateDraftJob::class);
        $this->post($base.'/documents', [...$input, 'request_id' => (string) Str::uuid()])->assertConflict();
        $this->post($base.'/documents', [...$input, 'files' => [$this->pdf('changed.pdf')]])->assertConflict();
        $this->getJson($base.'/document-admissions/'.$input['request_id'])->assertOk()->assertJsonPath('data.status', 'accepted');
        self::assertSame(1, $puts);
        self::assertSame(1, $session->documents()->count());
        foreach (['estimate_generation_ai_usage', 'estimate_generation_credit_operations'] as $table) {
            self::assertSame(0, DB::table($table)->where('session_id', $session->id)->count());
        }
    }

    public function test_review_pending_admission_blocks_new_storage_and_preserves_its_receipt(): void
    {
        [$rights, $session] = $this->httpFixture();
        Queue::fake();
        $puts = 0;
        (new InMemoryS3HttpStorage)->install(static function (RequestInterface $request) use (&$puts): ?Response {
            if ($request->getMethod() === 'PUT') $puts++;

            return null;
        });
        $prepared = PreparedDocumentAdmission::upload($session, [$this->pdf()]);
        $requestId = (string) Str::uuid();
        $id = DB::table(DocumentAdmissionOperation::TABLE)->insertGetId(['organization_id' => $session->organization_id,
            'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $rights->member->id,
            'request_id' => $requestId, 'kind' => 'upload', 'expected_version' => 8, 'request_hash' => hash('sha256', 'pending fixture'),
            'reserved_slots' => count($prepared->objects), 'objects' => json_encode($prepared->snapshot(), JSON_THROW_ON_ERROR),
            'document_ids' => '[]', 'status' => 'pending', 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
        $base = '/api/v1/admin/projects/'.$session->project_id.'/estimate-generation/sessions/'.$session->id;
        $this->withHeaders($this->headers($rights->member))->post($base.'/documents', [
            'state_version' => 8, 'request_id' => (string) Str::uuid(), 'files' => [$this->pdf()],
        ])->assertConflict();
        self::assertSame('pending', DB::table(DocumentAdmissionOperation::TABLE)->where('id', $id)->value('status'));
        self::assertSame(8, $session->fresh()->state_version);
        self::assertSame(0, $puts);
        self::assertSame(0, $session->documents()->count());
        Queue::assertNothingPushed();
    }

    private function httpFixture(): array
    {
        config(['cache.default' => 'array', 'web_auth.origins.admin' => ['https://admin.fixture.test']]);
        $this->seedGlobalSettings();
        $rights = AssistantRealAuthorizationFixture::create(['working-entry']);
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => ['estimate_generation.view', 'estimate_generation.upload_documents']], 'system_permissions' => ['admin.access']]);
        $rights->member->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->for($rights->organization)->create(['is_archived' => false]);
        AuthorizationContext::getProjectContext((int) $project->id, (int) $rights->organization->id);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $rights->organization->id, 'project_id' => $project->id,
            'user_id' => $rights->member->id, 'status' => 'estimate_review_required', 'state_version' => 8,
            'input_payload' => ['object_name' => 'Сохранённое описание проекта', 'generation_requested' => false],
            'draft_payload' => ['local_estimates' => []]]);

        return [$rights, $session];
    }

    private function headers(User $actor): array
    {
        $this->app['auth']->forgetGuards();
        $uuid = (string) Str::uuid();
        $session = UserAuthSession::query()->create(['user_id' => $actor->id, 'organization_id' => $actor->current_organization_id,
            'session_uuid' => $uuid, 'device_fingerprint' => hash('sha256', $uuid), 'device_name' => 'MOST document admission test',
            'ip_address' => '127.0.0.1', 'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now()]);
        $tokens = app(WebAuthTokenService::class)->issue($actor, 'admin', $session->session_uuid, (int) $actor->current_organization_id, false);

        return ['Accept' => 'application/json', 'Origin' => 'https://admin.fixture.test', 'Authorization' => 'Bearer '.$tokens->accessToken];
    }

    private function pdf(string $name = 'plan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/Fixtures/EstimateGeneration/ocr/vector-plan.pdf')));
    }

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
