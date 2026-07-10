<?php

declare(strict_types=1);

namespace Tests\Feature\SiteRequests;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\BusinessModules\Features\SiteRequests\Enums\CalendarEventTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestCreated;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestStatusChanged;
use App\BusinessModules\Features\SiteRequests\Listeners\CreateCalendarEventOnSiteRequest;
use App\BusinessModules\Features\SiteRequests\Listeners\PublishSiteRequestOnSubmit;
use App\BusinessModules\Features\SiteRequests\Listeners\SendSiteRequestNotification;
use App\BusinessModules\Features\SiteRequests\Listeners\SendStatusChangeNotification;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestCalendarEvent;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestCalendarService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestNotificationService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\BusinessModules\Features\SiteRequests\SiteRequestsModule;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

final class SiteRequestDraftPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_created_draft_does_not_publish_notification_or_calendar_event(): void
    {
        [$request, $notificationService, $calendarService] = $this->publicationContext();

        $notificationService->notifyOnCreated($request->fresh());
        $calendarService->createCalendarEvent($request->fresh());

        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('site_request_calendar_events', 0);
    }

    public function test_event_registration_assigns_publication_only_to_status_transition(): void
    {
        $dispatcher = app('events');
        $this->assertInstanceOf(Dispatcher::class, $dispatcher);
        $listeners = $dispatcher->getRawListeners();
        $createdListeners = $listeners[SiteRequestCreated::class] ?? [];
        $statusListeners = $listeners[SiteRequestStatusChanged::class] ?? [];

        $this->assertNotContains(SendSiteRequestNotification::class, $createdListeners);
        $this->assertNotContains(CreateCalendarEventOnSiteRequest::class, $createdListeners);
        $this->assertContains(PublishSiteRequestOnSubmit::class, $statusListeners);
        $this->assertContains(SendStatusChangeNotification::class, $statusListeners);
    }

    public function test_draft_to_pending_publishes_calendar_and_notification_once(): void
    {
        [$request, $notificationService, $calendarService, $foreignUser] = $this->publicationContext();
        Event::fake([SiteRequestStatusChanged::class]);
        $submittedRequest = app(SiteRequestService::class)->submit($request, $request->user_id);
        Event::assertDispatched(
            SiteRequestStatusChanged::class,
            static fn (SiteRequestStatusChanged $event): bool => $event->oldStatus === SiteRequestStatusEnum::DRAFT->value
                && $event->newStatus === SiteRequestStatusEnum::PENDING->value
        );
        $rehydratedRequest = SiteRequest::query()->findOrFail($submittedRequest->id);
        $event = new SiteRequestStatusChanged(
            $rehydratedRequest,
            SiteRequestStatusEnum::DRAFT->value,
            SiteRequestStatusEnum::PENDING->value,
            $request->user_id
        );
        $listener = new PublishSiteRequestOnSubmit($calendarService, $notificationService);

        $listener->handle($event);
        $listener->handle($event);
        (new SendStatusChangeNotification($notificationService))->handle($event);
        (new SendStatusChangeNotification($notificationService))->handle($event);

        $this->assertDatabaseCount('site_request_calendar_events', 1);
        $this->assertDatabaseHas('site_request_calendar_events', [
            'site_request_id' => $request->id,
            'organization_id' => $request->organization_id,
        ]);
        $publicationKey = "site_request:{$request->id}:submitted";
        $this->assertSame(1, Notification::query()
            ->where('type', 'site_request_created')
            ->get()
            ->filter(static fn (Notification $notification): bool => ($notification->data['publication_key'] ?? null) === $publicationKey
            )
            ->count());
        $this->assertSame(0, Notification::query()
            ->where('type', 'site_request_status_changed')
            ->count());

        $rehydratedRequest->update(['status' => SiteRequestStatusEnum::IN_REVIEW->value]);
        $statusEvent = new SiteRequestStatusChanged(
            $rehydratedRequest->fresh(),
            SiteRequestStatusEnum::PENDING->value,
            SiteRequestStatusEnum::IN_REVIEW->value,
            $foreignUser->id
        );
        $statusListener = new SendStatusChangeNotification($notificationService);
        $statusListener->handle($statusEvent);
        $statusListener->handle($statusEvent);

        $this->assertSame(1, Notification::query()
            ->where('type', 'site_request_status_changed')
            ->get()
            ->filter(static fn (Notification $notification): bool => ($notification->data['transition_key'] ?? null) === $statusEvent->transitionKey
            )
            ->count());
    }

    public function test_status_transition_event_queues_publication_and_status_listeners(): void
    {
        [$request] = $this->publicationContext();
        $request->update(['status' => SiteRequestStatusEnum::PENDING->value]);
        Queue::fake();

        event(new SiteRequestStatusChanged(
            $request->fresh(),
            SiteRequestStatusEnum::DRAFT->value,
            SiteRequestStatusEnum::PENDING->value,
            $request->user_id
        ));

        Queue::assertPushed(
            CallQueuedListener::class,
            static fn (CallQueuedListener $job): bool => $job->class === PublishSiteRequestOnSubmit::class
                && $job->afterCommit === true
        );
        Queue::assertPushed(
            CallQueuedListener::class,
            static fn (CallQueuedListener $job): bool => $job->class === SendStatusChangeNotification::class
        );
    }

    public function test_legacy_draft_calendar_event_is_not_visible_in_shared_calendar_or_request_list(): void
    {
        [$request, , $calendarService, $foreignUser] = $this->publicationContext();
        SiteRequestCalendarEvent::query()->create([
            'site_request_id' => $request->id,
            'organization_id' => $request->organization_id,
            'project_id' => $request->project_id,
            'event_type' => CalendarEventTypeEnum::DEADLINE->value,
            'title' => 'Legacy draft event',
            'color' => '#9E9E9E',
            'start_date' => now()->addDay()->toDateString(),
            'all_day' => true,
        ]);

        $events = $calendarService->getCalendarEvents(
            $request->organization_id,
            Carbon::today(),
            Carbon::today()->addDays(2)
        );
        $eventsOnDate = $calendarService->getEventsOnDate(
            $request->organization_id,
            Carbon::tomorrow()
        );
        $ical = $calendarService->exportToICal(
            $request->organization_id,
            Carbon::today(),
            Carbon::today()->addDays(2)
        );
        $visibleRequests = SiteRequest::query()
            ->forOrganization($request->organization_id)
            ->visibleToActor($foreignUser->id)
            ->pluck('id');

        $this->assertEmpty($events);
        $this->assertEmpty($eventsOnDate);
        $this->assertStringNotContainsString('Legacy draft event', $ical);
        $this->assertNotContains($request->id, $visibleRequests);
    }

    private function publicationContext(): array
    {
        $organization = Organization::factory()->verified()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $creator = User::factory()->create(['current_organization_id' => $organization->id]);
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $foreignUser = User::factory()->create(['current_organization_id' => $organization->id]);

        foreach ([$creator, $manager, $foreignUser] as $user) {
            $organization->users()->attach($user->id, [
                'is_owner' => false,
                'is_active' => true,
                'settings' => null,
            ]);
        }

        UserRoleAssignment::assignRole(
            user: $manager,
            roleSlug: 'organization_owner',
            context: AuthorizationContext::getOrganizationContext($organization->id)
        );

        $request = SiteRequest::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $creator->id,
            'title' => 'Concrete delivery',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::DRAFT->value,
            'priority' => SiteRequestPriorityEnum::MEDIUM->value,
            'material_name' => 'Concrete',
            'material_quantity' => 5,
            'material_unit' => 'm3',
            'required_date' => now()->addDay()->toDateString(),
        ]);

        $module = Mockery::mock(SiteRequestsModule::class);
        $module->shouldReceive('getSettings')->andReturn([
            'notify_on_create' => true,
            'notify_on_status_change' => true,
        ]);
        $module->shouldReceive('hasNotifications')->andReturn(true);
        $module->shouldReceive('hasScheduleManagement')->andReturn(false);

        $this->app->instance(NotificationService::class, new class extends NotificationService
        {
            public function __construct() {}

            public function send(
                User $user,
                string $type,
                array $data,
                ?string $notificationType = 'system',
                ?string $priority = 'normal',
                ?array $channels = null,
                ?int $organizationId = null,
                string|array|null $requiredPermissions = null
            ): Notification {
                return Notification::query()->create([
                    'type' => $type,
                    'notifiable_type' => User::class,
                    'notifiable_id' => $user->id,
                    'organization_id' => $organizationId,
                    'notification_type' => $notificationType,
                    'priority' => $priority,
                    'channels' => $channels ?? [],
                    'data' => $data,
                    'delivery_status' => [],
                ]);
            }
        });

        return [
            $request,
            new SiteRequestNotificationService($module),
            new SiteRequestCalendarService($module),
            $foreignUser,
        ];
    }
}
