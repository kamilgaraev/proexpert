<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationCampaign;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationItem;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantAccessRecertificationReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOneCExchangeSummaryReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectCameraReader;
use App\Models\OneCExchangeOperation;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantDirectorCrossSectionCoverageTest extends TestCase
{
    public function test_access_review_counts_are_native_org_scoped_and_permission_fresh(): void
    {
        $f = $this->fixture(); $org = (int) $f->organization->id; $args = ['scope' => 'organization'];
        $make = static function (int $organizationId, int $userId, string $status, string $risk) {
            $campaign = AccessRecertificationCampaign::query()->create(['organization_id' => $organizationId,
                'name' => 'PRIVATE_CAMPAIGN', 'owner_user_id' => $userId, 'created_by_user_id' => $userId]);
            return AccessRecertificationItem::query()->create(['organization_id' => $organizationId, 'campaign_id' => $campaign->id,
                'reviewer_user_id' => $userId, 'subject_user_id' => $userId, 'role_slug' => 'organization_owner',
                'assignment_snapshot_hash' => hash('sha256', (string) $campaign->id), 'status' => $status, 'risk_level' => $risk, 'due_at' => now()->subDay()]);
        };
        $pending = $make($org, (int) $f->owner->id, 'pending', 'high');
        $make($org, (int) $f->owner->id, 'approved', 'critical');
        $make((int) $f->foreignOrganization->id, (int) $f->owner->id, 'pending', 'critical');
        $reader = app(AssistantAccessRecertificationReader::class);
        $result = $reader->read($f->owner, $org, $args);
        self::assertSame(1, $result['data']['items_pending']); self::assertSame(1, $result['data']['items_overdue']);
        self::assertSame(2, $result['data']['dangerous_access']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result));
        self::assertTrue($reader->matchesReference($f->owner, $org, $result['source_refs'][0]));
        $pending->updateQuietly(['status' => 'approved']);
        self::assertFalse($reader->matchesReference($f->owner, $org, $result['source_refs'][0]));
        $member = $f->addMember(['ai-assistant' => ['ai_assistant.chat']]);
        $this->expectException(AuthorizationException::class); $reader->read($member, $org, $args);
    }

    public function test_exchange_summary_uses_safe_journal_counts_without_mutation_or_payloads(): void
    {
        $f = $this->fixture(); $org = (int) $f->organization->id; $args = ['scope' => 'organization'];
        $make = static fn (int $organizationId, string $status) => OneCExchangeOperation::query()->create([
            'organization_id' => $organizationId, 'operation_key' => uniqid('qa_', true), 'correlation_id' => 'PRIVATE',
            'direction' => 'export', 'scope' => 'materials', 'status' => $status,
            'finished_at' => $status === 'completed' ? '2026-10-10T12:30:00Z' : null,
            'safe_error_message' => 'PRIVATE_ERROR', 'safe_payload_preview' => ['token' => 'PRIVATE']]);
        $success = $make($org, 'completed'); $failure = $make($org, 'failed'); $make($org, 'retry_scheduled');
        $make((int) $f->foreignOrganization->id, 'completed');
        DB::enableQueryLog();
        $reader = app(AssistantOneCExchangeSummaryReader::class); $result = $reader->read($f->owner, $org, $args);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        self::assertSame(3, $result['data']['total']); self::assertSame(1, $result['data']['success']);
        self::assertSame(1, $result['data']['failed']); self::assertSame(1, $result['data']['pending']);
        self::assertSame('2026-10-10T12:30:00+00:00', $result['data']['last_success_at']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result));
        foreach ($queries as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)/i', $query['query']);
            self::assertStringNotContainsString('safe_payload_preview', $query['query']);
            self::assertStringNotContainsString('safe_error_message', $query['query']);
        }
        self::assertTrue($reader->matchesReference($f->owner, $org, $result['source_refs'][0]));
        $failure->updateQuietly(['status' => 'completed']);
        self::assertFalse($reader->matchesReference($f->owner, $org, $result['source_refs'][0]));
        self::assertSame('completed', $success->fresh()->status);
        $member = $f->addMember(['ai-assistant' => ['ai_assistant.chat']]);
        $this->expectException(AuthorizationException::class); $reader->read($member, $org, $args);
    }

    public function test_camera_summary_filters_project_and_never_selects_credentials(): void
    {
        $f = $this->fixture(); $org = (int) $f->organization->id;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org, 'is_archived' => false]));
        $private = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org, 'is_archived' => false]));
        $member = $f->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'], 'video-monitoring' => ['video-monitoring.view']]);
        $member->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $make = static fn (int $organizationId, int $projectId, string $status, bool $enabled) => DB::table('video_cameras')->insertGetId([
            'organization_id' => $organizationId, 'project_id' => $projectId, 'name' => 'Камера',
            'source_type' => 'rtsp', 'source_url' => 'PRIVATE_URL', 'password' => 'PRIVATE_PASSWORD',
            'status' => $status, 'is_enabled' => $enabled, 'last_checked_at' => '2026-10-10 12:30:00', 'created_at' => now(), 'updated_at' => now()]);
        $camera = $make($org, (int) $project->id, 'online', true);
        $make($org, (int) $project->id, 'offline', false); $make($org, (int) $private->id, 'online', true);
        DB::enableQueryLog(); $reader = app(AssistantProjectCameraReader::class);
        $result = $reader->read($member, $org, ['project_id' => (int) $project->id]);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        self::assertSame(2, $result['data']['total']); self::assertSame(1, $result['data']['online']);
        self::assertSame(1, $result['data']['offline']); self::assertSame(1, $result['data']['disabled']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result));
        foreach ($queries as $query) {
            foreach (['"password"', '"source_url"', '"playback_url"', '"settings"'] as $column) { self::assertStringNotContainsString($column, $query['query']); }
        }
        self::assertTrue($reader->matchesReference($member, $org, $result['source_refs'][0]));
        DB::table('video_cameras')->where('id', $camera)->update(['status' => 'offline']);
        self::assertFalse($reader->matchesReference($member, $org, $result['source_refs'][0]));
        $this->expectException(AuthorizationException::class); $reader->read($member, $org, ['project_id' => (int) $private->id]);
    }

    private function fixture(): AssistantRealAuthorizationFixture
    {
        Queue::fake(); config(['cache.default' => 'array']);
        return Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
    }
}
