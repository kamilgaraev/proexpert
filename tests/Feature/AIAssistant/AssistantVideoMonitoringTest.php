<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\OneCExchangeRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ReportTemplateRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\VideoMonitoringRagSource;
use App\BusinessModules\Features\VideoMonitoring\Models\VideoCamera;
use App\BusinessModules\Features\VideoMonitoring\Models\VideoCameraEvent;
use App\Models\OneCExchangeRun;
use App\Models\Project;
use App\Models\ReportTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantVideoMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_camera_event_collectors_do_not_read_legacy_credentials_or_error_payloads(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'quality-safety']);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $camera = $this->camera($fixture->organization->id, $project->id, 'Камера склада');
        DB::table('video_camera_events')->insert([
            'camera_id' => $camera, 'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
            'event_type' => 'camera.checked', 'severity' => 'info', 'occurred_at' => now(),
            'message' => 'SECRET rtsp://user:password@host/live', 'payload' => '{"token":"SECRET"}',
        ]);
        DB::enableQueryLog();
        $chunks = iterator_to_array((new VideoMonitoringRagSource)->collectForOrganization($fixture->organization->id));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertCount(2, $chunks);
        foreach ($chunks as $chunk) {
            self::assertStringNotContainsString('SECRET', $chunk->content);
            self::assertStringNotContainsString('rtsp://', $chunk->content);
            self::assertArrayNotHasKey('password', $chunk->metadata);
            self::assertArrayNotHasKey('payload', $chunk->metadata);
        }
        foreach ($queries as $query) {
            foreach (['"password"', '"source_url"', '"playback_url"', '"payload"', '"message"'] as $column) {
                self::assertStringNotContainsString($column, $query['query']);
            }
        }
    }

    public function test_current_camera_project_and_event_permissions_apply_before_limit_and_live_read(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'quality-safety']);
        $organizationId = (int) $fixture->organization->id;
        $visible = Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]);
        $private = Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]);
        $permissions = ['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
            'video-monitoring' => ['video-monitoring.view', 'video-monitoring.events.view']];
        $fixture->memberRole->update(['module_permissions' => $permissions]);
        $fixture->member->assignedProjects()->attach($visible->id, ['role' => 'member', 'is_active' => true]);
        $hiddenCamera = $this->camera($organizationId, $private->id, 'Скрытая камера');
        $visibleCamera = $this->camera($organizationId, $visible->id, 'Открытая камера');
        $event = VideoCameraEvent::query()->create(['camera_id' => $visibleCamera, 'organization_id' => $organizationId,
            'project_id' => $visible->id, 'event_type' => 'camera.checked', 'severity' => 'info', 'message' => 'SECRET',
            'payload' => ['token' => 'SECRET'], 'occurred_at' => now()]);
        foreach ([$hiddenCamera => $private->id, $visibleCamera => $visible->id] as $id => $projectId) {
            RagSource::query()->create(['organization_id' => $organizationId, 'project_id' => $projectId,
                'source_type' => 'video_monitoring', 'entity_type' => 'video_camera', 'entity_id' => (string) $id,
                'title' => 'Камера', 'checksum' => hash('sha256', (string) $id), 'metadata' => [], 'indexed_at' => now()]);
        }
        $policy = app(AssistantDataAccessPolicy::class);
        $visibleSources = $policy->applyToSources(RagSource::query(), $fixture->member, $organizationId)->orderBy('ai_rag_sources.id')->limit(1)->get();
        self::assertCount(1, $visibleSources);
        self::assertSame((string) $visibleCamera, $visibleSources->first()->entity_id);
        self::assertFalse($policy->canReadEntity($fixture->member, $organizationId, 'video_camera', $hiddenCamera));
        self::assertTrue($policy->canReadEntity($fixture->member, $organizationId, 'video_camera_event', $event->id));
        $read = app(AssistantDomainReadService::class)->execute('read', ['domain' => 'video_monitoring',
            'entity_type' => 'video_camera', 'id' => $visibleCamera], $fixture->member, $organizationId);
        self::assertStringNotContainsString('SECRET', json_encode($read, JSON_THROW_ON_ERROR));
        $permissions['video-monitoring'] = ['video-monitoring.view'];
        $fixture->memberRole->update(['module_permissions' => $permissions]);
        self::assertFalse($policy->canReadEntity($fixture->member, $organizationId, 'video_camera_event', $event->id));
        self::assertTrue($policy->canReadEntity($fixture->member, $organizationId, 'video_camera', $visibleCamera));
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view']]]);
        self::assertFalse($policy->canReadEntity($fixture->member, $organizationId, 'video_camera', $visibleCamera));
    }

    public function test_deleted_or_moved_current_camera_closes_old_event_and_revoked_module_closes_sources(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'quality-safety']);
        $organizationId = (int) $fixture->organization->id;
        $project = Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]);
        $camera = $this->camera($organizationId, $project->id, 'Камера');
        $event = VideoCameraEvent::query()->create(['camera_id' => $camera, 'organization_id' => $organizationId,
            'project_id' => $project->id, 'event_type' => 'camera.checked', 'severity' => 'info', 'message' => 'Доступна', 'occurred_at' => now()]);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertTrue($policy->canReadEntity($fixture->owner, $organizationId, 'video_camera_event', $event->id));
        DB::table('video_cameras')->where('id', $camera)->update(['organization_id' => $fixture->foreignOrganization->id]);
        self::assertFalse($policy->canReadEntity($fixture->owner, $organizationId, 'video_camera_event', $event->id));
        self::assertSame([], iterator_to_array((new VideoMonitoringRagSource)->collectEntity($organizationId, 'video_camera_event', $event->id)));
        DB::table('video_cameras')->where('id', $camera)->update(['organization_id' => $organizationId, 'deleted_at' => now()]);
        self::assertFalse($policy->canReadEntity($fixture->owner, $organizationId, 'video_camera_event', $event->id));
        DB::table('video_cameras')->where('id', $camera)->update(['deleted_at' => null]);
        DB::table('organization_package_subscriptions')->where('organization_id', $organizationId)->where('package_slug', 'quality-safety')->update(['current_period_end_at' => now()->subSecond()]);
        self::assertFalse($policy->canReadEntity($fixture->owner, $organizationId, 'video_camera', $camera));
    }

    public function test_operational_sources_exclude_exchange_errors_and_template_configuration(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $run = OneCExchangeRun::query()->create(['organization_id' => $fixture->organization->id,
            'direction' => 'import', 'scope' => 'materials', 'status' => 'completed', 'total_count' => 1,
            'errors' => ['token' => 'SECRET'], 'summary' => ['payload' => 'SECRET']]);
        $template = ReportTemplate::query()->create(['organization_id' => $fixture->organization->id,
            'user_id' => $fixture->owner->id, 'name' => 'Материалы', 'report_type' => 'material_usage',
            'columns_config' => ['SECRET'], 'is_default' => true]);
        foreach ([[new OneCExchangeRagSource, 'one_c_exchange_run', $run->id], [new ReportTemplateRagSource, 'report_template', $template->id]] as [$source, $type, $id]) {
            $chunks = iterator_to_array($source->collectEntity($fixture->organization->id, $type, $id));
            self::assertCount(1, $chunks);
            self::assertStringNotContainsString('SECRET', $chunks[0]->content);
            self::assertSame([], iterator_to_array($source->collectEntity($fixture->foreignOrganization->id, $type, $id)));
        }
    }

    private function camera(int $organizationId, int $projectId, string $name): int
    {
        return DB::table('video_cameras')->insertGetId(['organization_id' => $organizationId, 'project_id' => $projectId,
            'name' => $name, 'source_type' => 'rtsp', 'source_url' => 'rtsp://user:SECRET@internal/live',
            'playback_url' => 'https://internal/live?token=SECRET', 'username' => 'SECRET', 'password' => 'invalid-legacy-SECRET',
            'status' => 'online', 'settings' => '{"token":"SECRET"}', 'created_at' => now(), 'updated_at' => now()]);
    }
}
