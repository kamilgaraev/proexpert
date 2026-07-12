<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateImportStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativePinClock;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class NormativeOldClientPinPostgresTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_old_client_post_persists_server_pin_and_rejects_missing_unapproved_or_mismatch(): void
    {
        if (getenv('RUN_POSTGRES_NORMATIVE_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires opt-in migrated PostgreSQL contract database.');
        }

        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $version = 'old-client-'.strtolower((string) str()->ulid());
        $dataset = EstimateDatasetVersion::query()->create([
            'source_type' => EstimateSourceType::FSNB_2022,
            'version_key' => $version, 'bucket' => 'contract', 'prefix' => $version,
            'status' => EstimateImportStatus::PARSED,
        ]);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->app->instance(NormativePinClock::class, new class implements NormativePinClock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-07-12T10:00:00+03:00');
            }
        });
        config()->set('estimate-generation.normative_matching.approved_dataset_version', $version);
        $this->actingAs($user, 'api_admin');
        $url = "/api/v1/admin/projects/{$project->id}/estimate-generation/sessions";

        try {
            $response = $this->postJson($url, ['description' => 'Кладка кирпичных стен', 'area' => 100]);
            $response->assertCreated();
            $session = EstimateGenerationSession::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();
            self::assertSame($version, $session->input_payload['regional_context']['normative_dataset_version']);
            self::assertSame('2026-07-12', $session->input_payload['regional_context']['business_date']);
            $pin = app(NormativeContextPinResolver::class)->resolve($session->input_payload['regional_context']);
            self::assertSame('pinned', $pin['status']);
            self::assertSame($version, $pin['dataset_version']);
            $changedPin = [...$pin, 'dataset_version' => $version.'-changed'];
            self::assertNotSame(
                hash('sha256', CanonicalPipelineJson::encode(['normative_context_pin' => $pin, 'local_estimates' => [['sections' => [['work_items' => [['name' => 'Кладка']]]]]]])),
                hash('sha256', CanonicalPipelineJson::encode(['normative_context_pin' => $changedPin, 'local_estimates' => [['sections' => [['work_items' => [['name' => 'Кладка']]]]]]])),
            );

            $before = EstimateGenerationSession::query()->where('organization_id', $organization->id)->count();
            $this->postJson($url, ['description' => 'test', 'normative_dataset_version' => 'foreign-version'])->assertUnprocessable();
            self::assertSame($before, EstimateGenerationSession::query()->where('organization_id', $organization->id)->count());

            config()->set('estimate-generation.normative_matching.approved_dataset_version', null);
            $this->app->forgetInstance(\App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeDatasetPinPolicy::class);
            $this->postJson($url, ['description' => 'test'])->assertUnprocessable();
            self::assertSame($before, EstimateGenerationSession::query()->where('organization_id', $organization->id)->count());
        } finally {
            EstimateGenerationSession::query()->where('organization_id', $organization->id)->delete();
            $dataset->delete();
            $project->delete();
            $user->delete();
            $organization->delete();
        }
    }
}
