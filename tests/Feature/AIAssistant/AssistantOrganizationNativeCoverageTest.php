<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationActivityReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationNotificationsReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationProjectsReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationSuppliersReader;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\DTOs\Activity\ActivityEventFilters;
use App\Models\Activity\ActivityEvent;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Activity\ActivityEventQueryService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantOrganizationNativeCoverageTest extends TestCase
{
    public function test_project_archive_and_supplier_inventory_counts_current_scope_and_names(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $f = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $f->organization->id; $actor = $f->owner; $args = ['scope' => 'organization'];
        $active = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org, 'name' => 'Активный объект', 'status' => 'active', 'is_archived' => false]));
        $archive = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org, 'status' => 'active', 'is_archived' => true]));
        Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $f->foreignOrganization->id, 'name' => 'FOREIGN_PRIVATE', 'status' => 'active']));
        $member = $f->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view']], ['admin.projects.view']);
        $member->assignedProjects()->attach($archive->id, ['role' => 'member', 'is_active' => true]);
        $projects = app(AssistantOrganizationProjectsReader::class);
        $result = $projects->read($actor, $org, $args);
        self::assertSame(2, $result['data']['total']); self::assertSame(1, $result['data']['active']); self::assertSame(1, $result['data']['archived']);
        self::assertStringNotContainsString('FOREIGN_PRIVATE', json_encode($result));
        $limited = $projects->read($member, $org, $args);
        self::assertSame(1, $limited['data']['total']); self::assertSame(0, $limited['data']['active']); self::assertSame(1, $limited['data']['archived']);
        self::assertTrue($projects->matchesReference($actor, $org, $result['source_refs'][0]));
        $active->updateQuietly(['is_archived' => true]);
        self::assertFalse($projects->matchesReference($actor, $org, $result['source_refs'][0]));
        Model::withoutEvents(function () use ($org, $f): void {
            foreach (range(1, 23) as $i) { Supplier::query()->create(['organization_id' => $org, 'name' => 'Поставщик '.$i, 'is_active' => true, 'phone' => 'PRIVATE_PHONE', 'email' => 'PRIVATE_EMAIL']); }
            Supplier::query()->create(['organization_id' => $f->foreignOrganization->id, 'name' => 'FOREIGN_PRIVATE', 'is_active' => true]);
        });
        $suppliers = app(AssistantOrganizationSuppliersReader::class); $catalog = $suppliers->read($actor, $org, $args);
        self::assertSame(23, $catalog['data']['total']); self::assertSame(23, $catalog['data']['active']); self::assertCount(20, $catalog['data']['examples']);
        self::assertStringNotContainsString('PRIVATE', json_encode($catalog)); self::assertTrue($suppliers->matchesReference($actor, $org, $catalog['source_refs'][0]));
        Supplier::query()->where('name', 'Поставщик 1')->firstOrFail()->updateQuietly(['name' => 'Новое имя']);
        self::assertFalse($suppliers->matchesReference($actor, $org, $catalog['source_refs'][0]));
        $f->ownerAssignment->updateQuietly(['is_active' => false]);
        self::assertFalse($suppliers->matchesReference($actor, $org, $catalog['source_refs'][0]));
    }

    public function test_notifications_are_personal_and_interface_scoped_and_activity_denial_is_not_zero(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $f = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $f->organization->id; $actor = $f->owner; $args = ['scope' => 'organization'];
        $make = static function ($recipient, ?int $organizationId, string $interface, string $title, string $priority = 'normal'): Notification {
            return Model::withoutEvents(function () use ($recipient, $organizationId, $interface, $title, $priority): Notification {
                $notification = Notification::query()->create(['type' => 'admin_notification', 'notifiable_type' => User::class,
                    'notifiable_id' => $recipient->id, 'organization_id' => $organizationId, 'notification_type' => 'system', 'priority' => $priority,
                    'data' => ['title' => $title, 'message' => 'Обычное сообщение', 'secret_private' => 'PRIVATE_PAYLOAD'], 'metadata' => ['secret' => 'PRIVATE_METADATA']]);
                $notification->targets()->create(['interface' => $interface, 'read_at' => null]);
                return $notification;
            });
        };
        $one = null;
        foreach (range(1, 23) as $i) { $one ??= $make($actor, $org, 'admin', 'Сообщение '.$i, 'high'); if ($i !== 1) { $make($actor, $org, 'admin', 'Сообщение '.$i, 'high'); } }
        $make($actor, null, 'admin', 'Глобальное');
        $make($actor, $org, 'mobile', 'MOBILE_PRIVATE');
        $make($f->administrator, $org, 'admin', 'OTHER_ACTOR_PRIVATE');
        $make($actor, $f->foreignOrganization->id, 'admin', 'FOREIGN_ORG_PRIVATE');
        $reader = app(AssistantOrganizationNotificationsReader::class); $result = $reader->read($actor, $org, $args);
        self::assertSame(24, $result['data']['total']); self::assertSame(23, $result['data']['important']); self::assertCount(20, $result['data']['examples']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result));
        self::assertTrue($reader->matchesReference($actor, $org, $result['source_refs'][0]));
        self::assertNull($one->targets()->firstOrFail()->read_at);
        $one->targets()->firstOrFail()->updateQuietly(['read_at' => now()]);
        self::assertFalse($reader->matchesReference($actor, $org, $result['source_refs'][0]));
        Model::withoutEvents(function () use ($org, $f, $actor): void {
            foreach (range(1, 23) as $i) { ActivityEvent::query()->create(['organization_id' => $org, 'actor_user_id' => $actor->id,
                'actor_name' => 'Инициатор', 'module' => 'contracts', 'event_type' => 'contract.updated', 'action' => 'updated', 'title' => 'Изменён договор',
                'occurred_at' => now()->subDay(), 'changes' => ['password' => 'PRIVATE_CHANGE'], 'actor_email' => 'PRIVATE_EMAIL']); }
            ActivityEvent::query()->create(['organization_id' => $f->foreignOrganization->id, 'module' => 'contracts', 'event_type' => 'contract.updated',
                'action' => 'updated', 'title' => 'FOREIGN_PRIVATE', 'occurred_at' => now()->subDay()]);
        });
        $events = app(ActivityEventQueryService::class); $filters = ActivityEventFilters::fromRequest(Request::create('/', 'GET', ['date_from' => today()->subDays(6)->toDateString(), 'date_to' => today()->toDateString()]));
        self::assertSame(23, $events->summary($org, $filters)['total']);
        $facts = $events->recentReadFacts($org, $filters); self::assertCount(20, $facts);
        self::assertStringNotContainsString('PRIVATE', $facts->toJson());
        $activity = app(AssistantOrganizationActivityReader::class)->read($actor, $org, $args);
        self::assertFalse($activity['data']['available']); self::assertArrayNotHasKey('total', $activity['data']);
        self::assertStringContainsString('Количество событий и инициаторы не определены', $activity['server_formatted_answer']);
    }
}
