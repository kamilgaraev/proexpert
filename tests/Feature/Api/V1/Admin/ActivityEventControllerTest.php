<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Activity\ActivityEvent;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ActivityEventControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_events_by_current_organization_and_search_text(): void
    {
        $this->allowAdminActivityPermissions();
        $context = AdminApiTestContext::create();

        $this->createEvent($context->organization, [
            'title' => 'Foundation concrete approved',
            'description' => 'Inspector confirmed strength class',
            'actor_name' => 'Alice Admin',
            'occurred_at' => Carbon::parse('2026-05-10 10:00:00'),
        ]);
        $this->createEvent($context->organization, [
            'title' => 'Warehouse stock updated',
            'description' => 'Material balance changed',
            'actor_name' => 'Alice Admin',
            'occurred_at' => Carbon::parse('2026-05-10 11:00:00'),
        ]);
        $this->createEvent(Organization::factory()->verified()->create(), [
            'title' => 'Foundation foreign event',
            'description' => 'Must stay invisible',
            'occurred_at' => Carbon::parse('2026-05-10 12:00:00'),
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/activity/events?search=Foundation&per_page=10');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.title', 'Foundation concrete approved');

        $titles = collect($response->json('data'))->pluck('title')->all();

        $this->assertNotContains('Foundation foreign event', $titles);
        $this->assertNotContains('Warehouse stock updated', $titles);
    }

    public function test_show_returns_detail_for_own_event_and_not_found_for_foreign_event(): void
    {
        $this->allowAdminActivityPermissions();
        $context = AdminApiTestContext::create();
        $ownEvent = $this->createEvent($context->organization, [
            'correlation_id' => 'corr-activity-own',
            'interface' => 'admin',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'FeatureTest',
        ]);
        $foreignEvent = $this->createEvent(Organization::factory()->verified()->create(), [
            'title' => 'Foreign detail event',
            'correlation_id' => 'corr-foreign',
        ]);

        $ownResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/activity/events/{$ownEvent->id}");

        $ownResponse->assertOk();
        $ownResponse->assertJsonPath('success', true);
        $ownResponse->assertJsonPath('data.id', $ownEvent->id);
        $ownResponse->assertJsonPath('data.technical.correlation_id', 'corr-activity-own');
        $ownResponse->assertJsonPath('data.technical.interface', 'admin');

        $foreignResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/activity/events/{$foreignEvent->id}");

        $foreignResponse->assertNotFound();
        $foreignResponse->assertJsonPath('success', false);
    }

    public function test_summary_and_actors_are_scoped_to_current_organization(): void
    {
        $this->allowAdminActivityPermissions();
        $context = AdminApiTestContext::create();
        $actor = User::factory()->create([
            'name' => 'Scoped Actor',
            'email' => 'scoped@example.test',
            'current_organization_id' => $context->organization->id,
        ]);

        $this->createEvent($context->organization, [
            'actor_user_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'module' => 'construction-journal',
            'result' => 'failed',
            'severity' => 'critical',
        ]);
        $this->createEvent($context->organization, [
            'actor_user_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'module' => 'construction-journal',
            'result' => 'success',
            'severity' => 'info',
        ]);
        $this->createEvent(Organization::factory()->verified()->create(), [
            'actor_name' => 'Foreign Actor',
            'module' => 'procurement',
            'result' => 'blocked',
            'severity' => 'critical',
        ]);

        $summaryResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/activity/summary');

        $summaryResponse->assertOk();
        $summaryResponse->assertJsonPath('success', true);
        $summaryResponse->assertJsonPath('data.total', 2);
        $summaryResponse->assertJsonPath('data.failed', 1);
        $summaryResponse->assertJsonPath('data.warnings', 1);
        $summaryResponse->assertJsonPath('data.by_module.0.key', 'construction-journal');

        $actorsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/activity/actors');

        $actorsResponse->assertOk();
        $actorsResponse->assertJsonPath('success', true);
        $actorsResponse->assertJsonCount(1, 'data');
        $actorsResponse->assertJsonPath('data.0.id', $actor->id);
        $actorsResponse->assertJsonPath('data.0.name', 'Scoped Actor');
    }

    public function test_export_requires_activity_export_permission(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission): bool => $permission !== 'system-logs.activity-events.export'
            );
        });
        $context = AdminApiTestContext::create();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/activity/events/export');

        $response->assertForbidden();
        $response->assertJsonPath('success', false);
    }

    public function test_export_stream_contains_only_current_organization_events_with_readable_headers(): void
    {
        $this->allowAdminActivityPermissions();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Own project',
        ]);

        $this->createEvent($context->organization, [
            'title' => 'Own export event',
            'description' => 'Visible description',
            'project_id' => $project->id,
            'actor_name' => null,
            'occurred_at' => Carbon::parse('2026-05-10 10:00:00'),
        ]);
        $this->createEvent(Organization::factory()->verified()->create(), [
            'title' => 'Foreign export event',
            'description' => 'Hidden description',
            'occurred_at' => Carbon::parse('2026-05-10 11:00:00'),
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->get('/api/v1/admin/activity/events/export');

        $response->assertOk();

        $content = $response->streamedContent();

        foreach (['Дата и время', 'Пользователь', 'Действие', 'Модуль', 'Объект', 'Проект', 'Результат', 'Описание'] as $header) {
            $this->assertStringContainsString($header, $content);
        }
        $this->assertStringContainsString('Система', $content);
        $this->assertStringContainsString('Own export event', $content);
        $this->assertStringContainsString('Own project', $content);
        $this->assertStringNotContainsString('Foreign export event', $content);
    }

    private function allowAdminActivityPermissions(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
        });
    }

    private function createEvent(Organization $organization, array $attributes = []): ActivityEvent
    {
        return ActivityEvent::query()->create(array_merge([
            'organization_id' => $organization->id,
            'module' => 'construction-journal',
            'event_type' => 'construction_journal_entry.created',
            'action' => 'created',
            'result' => 'success',
            'severity' => 'info',
            'subject_type' => 'construction_journal_entry',
            'subject_id' => 1,
            'subject_label' => 'Journal entry #1',
            'title' => 'Activity event',
            'description' => 'Activity event description',
            'occurred_at' => now(),
        ], $attributes));
    }
}
