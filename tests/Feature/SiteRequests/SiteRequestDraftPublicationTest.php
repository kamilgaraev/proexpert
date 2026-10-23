<?php

declare(strict_types=1);

namespace Tests\Feature\SiteRequests;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use App\BusinessModules\Features\ScheduleManagement\Services\ProjectEventService;
use App\BusinessModules\Features\SiteRequests\Enums\CalendarEventTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestCreated;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestStatusChanged;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestUpdated;
use App\BusinessModules\Features\SiteRequests\Listeners\CreateCalendarEventOnSiteRequest;
use App\BusinessModules\Features\SiteRequests\Listeners\PublishSiteRequestOnSubmit;
use App\BusinessModules\Features\SiteRequests\Listeners\SendSiteRequestNotification;
use App\BusinessModules\Features\SiteRequests\Listeners\SendStatusChangeNotification;
use App\BusinessModules\Features\SiteRequests\Listeners\UpdateCalendarEventOnSiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestCalendarEvent;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestCalendarService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestNotificationService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\BusinessModules\Features\SiteRequests\SiteRequestsModule;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use ReflectionClass;
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

    public function test_stale_draft_update_does_not_publish_after_request_is_submitted(): void
    {
        [$request, $notificationService, $calendarService] = $this->publicationContext();
        $oldRequiredDate = $request->required_date?->toDateString();
        $request->update(['required_date' => now()->addDays(2)->toDateString()]);
        $updateEvent = new SiteRequestUpdated(
            $request->fresh(),
            ['required_date' => $oldRequiredDate]
        );
        Queue::fake();
        event($updateEvent);
        Queue::assertPushed(
            CallQueuedListener::class,
            static fn (CallQueuedListener $job): bool => $job->class === UpdateCalendarEventOnSiteRequest::class
        );
        $serializedEvent = serialize($updateEvent);

        Event::fake([SiteRequestStatusChanged::class]);
        $submittedRequest = app(SiteRequestService::class)->submit($request, $request->user_id);
        $rehydratedEvent = unserialize($serializedEvent, ['allowed_classes' => true]);
        $this->assertInstanceOf(SiteRequestUpdated::class, $rehydratedEvent);
        $this->assertSame(SiteRequestStatusEnum::DRAFT->value, $rehydratedEvent->statusAtDispatch);
        $this->assertSame(SiteRequestStatusEnum::PENDING, $rehydratedEvent->siteRequest->status);

        (new UpdateCalendarEventOnSiteRequest($calendarService))->handle($rehydratedEvent);

        $this->assertDatabaseCount('site_request_calendar_events', 0);

        $submitEvent = new SiteRequestStatusChanged(
            $submittedRequest->fresh(),
            SiteRequestStatusEnum::DRAFT->value,
            SiteRequestStatusEnum::PENDING->value,
            $request->user_id
        );
        $publicationListener = new PublishSiteRequestOnSubmit($calendarService, $notificationService);
        $publicationListener->handle($submitEvent);
        $publicationListener->handle($submitEvent);

        $this->assertDatabaseCount('site_request_calendar_events', 1);
        $this->assertDatabaseHas('site_request_calendar_events', [
            'site_request_id' => $request->id,
        ]);

        $publishedCalendarEvent = SiteRequestCalendarEvent::query()
            ->where('site_request_id', $request->id)
            ->sole();
        $publishedRequiredDate = $submittedRequest->required_date?->toDateString();
        $updatedRequiredDate = now()->addDays(3)->toDateString();
        $submittedRequest->update(['required_date' => $updatedRequiredDate]);
        $publishedUpdateEvent = new SiteRequestUpdated(
            $submittedRequest->fresh(),
            ['required_date' => $publishedRequiredDate]
        );

        (new UpdateCalendarEventOnSiteRequest($calendarService))->handle($publishedUpdateEvent);

        $this->assertDatabaseCount('site_request_calendar_events', 1);
        $this->assertDatabaseHas('site_request_calendar_events', [
            'id' => $publishedCalendarEvent->id,
            'start_date' => $updatedRequiredDate,
        ]);
    }

    public function test_legacy_updated_event_without_status_snapshot_fails_closed(): void
    {
        [$request, , $calendarService] = $this->publicationContext();
        $legacyEvent = $this->legacyEvent(SiteRequestUpdated::class, [
            'siteRequest' => $request->fresh(),
            'oldValues' => ['required_date' => $request->required_date?->toDateString()],
        ]);
        $this->assertInstanceOf(SiteRequestUpdated::class, $legacyEvent);
        $this->assertFalse(isset($legacyEvent->statusAtDispatch));

        (new UpdateCalendarEventOnSiteRequest($calendarService))->handle($legacyEvent);

        $this->assertDatabaseCount('site_request_calendar_events', 0);
    }

    public function test_legacy_status_event_without_transition_key_is_idempotent(): void
    {
        [$request, $notificationService, , $foreignUser] = $this->publicationContext();
        $request->update(['status' => SiteRequestStatusEnum::IN_REVIEW->value]);
        $legacyEvent = $this->legacyEvent(SiteRequestStatusChanged::class, [
            'siteRequest' => $request->fresh(),
            'oldStatus' => SiteRequestStatusEnum::PENDING->value,
            'newStatus' => SiteRequestStatusEnum::IN_REVIEW->value,
            'changedByUserId' => $foreignUser->id,
        ]);
        $this->assertInstanceOf(SiteRequestStatusChanged::class, $legacyEvent);
        $this->assertFalse(isset($legacyEvent->transitionKey));
        $listener = new SendStatusChangeNotification($notificationService);

        $listener->handle($legacyEvent);
        $listener->handle($legacyEvent);

        $this->assertSame(1, Notification::query()
            ->where('type', 'site_request_status_changed')
            ->count());
    }

    public function test_stale_schedule_creator_cannot_restore_calendar_after_dates_are_cleared(): void
    {
        [$request, , $calendarService] = $this->publicationContext(true);
        $request->update(['status' => SiteRequestStatusEnum::PENDING->value]);
        $scheduleEvent = new ProjectEvent;
        $scheduleEvent->setAttribute('id', 987654);
        $scheduleService = Mockery::mock(ProjectEventService::class);
        $scheduleService->shouldReceive('createEvent')
            ->once()
            ->andReturnUsing(function () use ($request, $scheduleEvent): ProjectEvent {
                $request->update(['required_date' => null]);
                SiteRequestCalendarEvent::query()
                    ->where('site_request_id', $request->id)
                    ->delete();

                return $scheduleEvent;
            });
        $scheduleService->shouldReceive('deleteEvent')
            ->once()
            ->with($scheduleEvent)
            ->andReturnTrue();
        $this->app->instance(ProjectEventService::class, $scheduleService);

        $result = $calendarService->createCalendarEvent($request->fresh());

        $this->assertNull($result);
        $this->assertDatabaseMissing('site_request_calendar_events', [
            'site_request_id' => $request->id,
        ]);
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

    public function test_equipment_mirror_preserves_the_source_interval_when_times_change_or_are_cleared(): void
    {
        [$request, , $calendarService] = $this->publicationContext(true);
        $this->actingAs(User::query()->findOrFail($request->user_id));
        $request->update([
            'request_type' => SiteRequestTypeEnum::EQUIPMENT_REQUEST->value,
            'status' => SiteRequestStatusEnum::PENDING->value,
            'required_date' => null,
            'equipment_type' => 'generator',
            'equipment_count' => 1,
            'rental_start_date' => '2026-10-27',
            'rental_end_date' => '2026-10-28',
            'equipment_start_at' => '2026-10-27T06:15:00Z',
            'equipment_end_at' => '2026-10-28T07:45:00Z',
        ]);
        $calendarService->createCalendarEvent($request->fresh());
        $source = SiteRequestCalendarEvent::query()->where('site_request_id', $request->id)->sole();
        $mirrorId = $source->schedule_event_id;
        $this->assertNotNull($mirrorId);

        foreach ([
            [[], '2026-10-27T09:15:00', '2026-10-28T10:45:00'],
            [[
                'equipment_start_at' => '2026-10-27T06:15:30Z',
                'equipment_end_at' => '2026-10-28T07:45:15Z',
            ], '2026-10-27T09:15:30', '2026-10-28T10:45:15'],
            [['equipment_end_at' => null], '2026-10-27T09:15:30', null],
            [['equipment_start_at' => null], '2026-10-27', '2026-10-29'],
        ] as [$changes, $start, $end]) {
            if ($changes !== []) {
                $request->refresh()->update($changes);
                $calendarService->updateCalendarEvent($request->fresh());
            }
            $source->refresh();
            $mirror = ProjectEvent::query()->findOrFail($mirrorId);
            $this->assertSame($mirrorId, $source->schedule_event_id);
            $this->assertSame($start, $mirror->getCalendarStartLocal());
            $this->assertSame($end, $mirror->getCalendarEndLocal());
            $this->assertSame($request->organization_id, $mirror->organization_id);
            $this->assertSame($request->project_id, $mirror->project_id);
            $this->assertDatabaseCount('project_events', 1);
        }
    }

    public function test_equipment_calendar_keeps_exact_moscow_times_and_cleared_end(): void
    {
        [$request, , $calendarService] = $this->publicationContext();
        $request->update([
            'request_type' => SiteRequestTypeEnum::EQUIPMENT_REQUEST->value,
            'status' => SiteRequestStatusEnum::PENDING->value,
            'required_date' => null,
            'equipment_type' => 'generator',
            'equipment_count' => 1,
            'rental_start_date' => '2026-10-27',
            'rental_end_date' => '2026-10-28',
            'equipment_start_at' => '2026-10-27T06:15:30Z',
            'equipment_end_at' => '2026-10-28T07:45:15Z',
        ]);

        $calendarService->createCalendarEvent($request->fresh());
        $event = SiteRequestCalendarEvent::query()->where('site_request_id', $request->id)->first();

        $this->assertInstanceOf(SiteRequestCalendarEvent::class, $event);
        $this->assertSame('Аренда: Генератор', $event->title);
        $this->assertFalse($event->all_day);
        $this->assertSame('2026-10-27', $event->start_date->toDateString());
        $this->assertSame('2026-10-28', $event->end_date->toDateString());
        $this->assertSame('09:15:30', $event->start_time);
        $this->assertSame('10:45:15', $event->end_time);
        $ical = $calendarService->exportToICal(
            $request->organization_id,
            Carbon::parse('2026-10-26'),
            Carbon::parse('2026-10-29'),
            $request->project_id,
        );
        $this->assertStringContainsString('DTSTART:20261027T091530', $ical);
        $this->assertStringContainsString('DTEND:20261028T104515', $ical);

        $request->update([
            'equipment_start_at' => '2026-10-26T22:15:00Z',
            'equipment_end_at' => null,
            'rental_start_date' => '2026-10-26',
            'rental_end_date' => '2026-11-07',
        ]);
        $calendarService->updateCalendarEvent($request->fresh());
        $updated = SiteRequestCalendarEvent::query()->where('site_request_id', $request->id)->firstOrFail();

        $this->assertSame($event->id, $updated->id);
        $this->assertSame('2026-10-27', $updated->start_date->toDateString());
        $this->assertSame('01:15:00', $updated->start_time);
        $this->assertNull($updated->end_date);
        $this->assertNull($updated->end_time);
        $this->assertFalse($updated->all_day);
        $this->assertDatabaseCount('site_request_calendar_events', 1);
    }

    public function test_equipment_calendar_accepts_canonical_interval_without_legacy_dates(): void
    {
        [$request, , $calendarService] = $this->publicationContext();
        $request->update([
            'request_type' => SiteRequestTypeEnum::EQUIPMENT_REQUEST->value,
            'status' => SiteRequestStatusEnum::PENDING->value,
            'required_date' => null,
            'rental_start_date' => null,
            'rental_end_date' => null,
            'equipment_start_at' => '2026-10-26T21:30:00Z',
            'equipment_end_at' => '2026-10-27T22:30:00Z',
        ]);

        $this->assertTrue($request->fresh()->hasCalendarEvent());
        $calendarService->createCalendarEvent($request->fresh());
        $event = SiteRequestCalendarEvent::query()->where('site_request_id', $request->id)->first();
        $this->assertInstanceOf(SiteRequestCalendarEvent::class, $event);
        $this->assertSame('2026-10-27', $event->start_date->toDateString());
        $this->assertSame('2026-10-28', $event->end_date->toDateString());
        $this->assertSame('00:30:00', $event->start_time);
        $this->assertSame('01:30:00', $event->end_time);

        $request->update(['equipment_start_at' => null, 'equipment_end_at' => null]);
        $calendarService->updateCalendarEvent($request->fresh());
        $this->assertDatabaseCount('site_request_calendar_events', 0);
    }

    public function test_equipment_time_only_updates_reconcile_through_service_event_listener(): void
    {
        [$request, , $calendarService] = $this->publicationContext();
        $request->update([
            'request_type' => SiteRequestTypeEnum::EQUIPMENT_REQUEST->value,
            'status' => SiteRequestStatusEnum::PENDING->value,
            'required_date' => null,
            'equipment_type' => 'generator',
            'equipment_count' => 1,
            'rental_start_date' => '2026-10-27',
            'rental_end_date' => '2026-10-28',
            'equipment_start_at' => '2026-10-27T06:15:00Z',
            'equipment_end_at' => '2026-10-28T07:45:00Z',
        ]);
        $calendarService->createCalendarEvent($request->fresh());
        $calendarId = SiteRequestCalendarEvent::query()->where('site_request_id', $request->id)->sole()->id;

        foreach ([
            [['equipment_start_at' => '2026-10-27T07:15:00Z'], '10:15:00', '2026-10-28', '10:45:00', false],
            [['equipment_end_at' => '2026-10-28T08:45:00Z'], '10:15:00', '2026-10-28', '11:45:00', false],
            [['equipment_end_at' => null], '10:15:00', null, null, false],
            [['equipment_start_at' => null], null, '2026-10-28', null, true],
        ] as [$changes, $startTime, $endDate, $endTime, $allDay]) {
            Event::fake([SiteRequestUpdated::class]);
            app(SiteRequestService::class)->update($request->fresh(), $request->user_id, $changes);
            $updates = Event::dispatched(SiteRequestUpdated::class);
            $this->assertCount(1, $updates);
            $update = $updates->first()[0];
            $this->assertInstanceOf(SiteRequestUpdated::class, $update);
            $this->assertSame(array_keys($changes), array_keys($update->oldValues));
            $this->assertSame(SiteRequestStatusEnum::PENDING->value, $update->statusAtDispatch);

            (new UpdateCalendarEventOnSiteRequest($calendarService))->handle($update);
            $calendarEvent = SiteRequestCalendarEvent::query()->where('site_request_id', $request->id)->sole();
            $this->assertSame($calendarId, $calendarEvent->id);
            $this->assertSame('2026-10-27', $calendarEvent->start_date->toDateString());
            $this->assertSame($startTime, $calendarEvent->start_time);
            $this->assertSame($endDate, $calendarEvent->end_date?->toDateString());
            $this->assertSame($endTime, $calendarEvent->end_time);
            $this->assertSame($allDay, $calendarEvent->all_day);
            $this->assertDatabaseCount('site_request_calendar_events', 1);
        }
    }

    public function test_legacy_equipment_dates_and_material_delivery_times_remain_available(): void
    {
        [$request, , $calendarService] = $this->publicationContext();
        $request->update([
            'request_type' => SiteRequestTypeEnum::EQUIPMENT_REQUEST->value,
            'status' => SiteRequestStatusEnum::PENDING->value,
            'required_date' => null,
            'rental_start_date' => '2026-10-27',
            'rental_end_date' => '2026-10-28',
        ]);
        $calendarService->createCalendarEvent($request->fresh());
        $event = SiteRequestCalendarEvent::query()->where('site_request_id', $request->id)->firstOrFail();
        $this->assertTrue($event->all_day);
        $this->assertSame('2026-10-27', $event->start_date->toDateString());
        $this->assertSame('2026-10-28', $event->end_date->toDateString());
        $this->assertNull($event->start_time);
        $this->assertNull($event->end_time);

        $request->update([
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'required_date' => '2026-10-29',
            'rental_start_date' => null,
            'rental_end_date' => null,
            'delivery_time_from' => '09:00:00',
            'delivery_time_to' => '10:00:00',
            'equipment_start_at' => '2026-10-30T21:30:00Z',
            'equipment_end_at' => '2026-10-31T22:30:00Z',
        ]);
        $calendarService->updateCalendarEvent($request->fresh());
        $updated = SiteRequestCalendarEvent::query()->where('site_request_id', $request->id)->firstOrFail();
        $this->assertFalse($updated->all_day);
        $this->assertSame('2026-10-29', $updated->start_date->toDateString());
        $this->assertSame('09:00:00', $updated->start_time);
        $this->assertSame('10:00:00', $updated->end_time);
    }

    public function test_ical_export_keeps_the_inclusive_last_day_and_date_value_type(): void
    {
        [$request, , $calendarService] = $this->publicationContext();
        $request->forceFill(['status' => SiteRequestStatusEnum::PENDING->value])->saveQuietly();

        foreach ([
            ['Single day', null],
            ['Explicit same day', '2026-10-24'],
            ['Multiple days', '2026-10-26'],
        ] as [$title, $endDate]) {
            $eventRequest = $request->replicate();
            $eventRequest->saveQuietly();
            SiteRequestCalendarEvent::query()->create([
                'site_request_id' => $eventRequest->id,
                'organization_id' => $request->organization_id,
                'project_id' => $request->project_id,
                'event_type' => CalendarEventTypeEnum::DEADLINE->value,
                'title' => $title,
                'color' => '#1976D2',
                'start_date' => '2026-10-24',
                'end_date' => $endDate,
                'all_day' => true,
            ]);
        }

        [$foreignRequest] = $this->publicationContext();
        $foreignRequest->forceFill(['status' => SiteRequestStatusEnum::PENDING->value])->saveQuietly();
        SiteRequestCalendarEvent::query()->create([
            'site_request_id' => $foreignRequest->id,
            'organization_id' => $foreignRequest->organization_id,
            'project_id' => $foreignRequest->project_id,
            'event_type' => CalendarEventTypeEnum::DEADLINE->value,
            'title' => 'Foreign organization event',
            'color' => '#1976D2',
            'start_date' => '2026-10-24',
            'all_day' => true,
        ]);

        $ical = $calendarService->exportToICal(
            $request->organization_id,
            Carbon::parse('2026-10-24'),
            Carbon::parse('2026-10-26'),
            $request->project_id,
        );

        foreach ([
            ['Single day', '20261025'],
            ['Explicit same day', '20261025'],
            ['Multiple days', '20261027'],
        ] as [$title, $exclusiveEnd]) {
            $this->assertStringContainsString(
                "DTSTART;VALUE=DATE:20261024\r\nDTEND;VALUE=DATE:{$exclusiveEnd}\r\nSUMMARY:{$title}\r\n",
                $ical,
            );
        }

        $this->assertStringNotContainsString('Foreign organization event', $ical);
        $this->assertSame(3, substr_count($ical, 'BEGIN:VEVENT'));
    }

    public function test_ical_export_formats_timed_events_with_seconds_and_utc_timestamp(): void
    {
        [$request, , $calendarService] = $this->publicationContext();
        $request->forceFill(['status' => SiteRequestStatusEnum::PENDING->value])->saveQuietly();

        foreach ([
            ['Timed same day', '09:15:30', null, '10:45:15'],
            ['Timed overnight', '23:30:00', '2026-10-25', '01:30:00'],
            ['Timed open end', '09:00:00', null, null],
        ] as [$title, $startTime, $endDate, $endTime]) {
            $eventRequest = $request->replicate();
            $eventRequest->saveQuietly();
            SiteRequestCalendarEvent::query()->create([
                'site_request_id' => $eventRequest->id,
                'organization_id' => $request->organization_id,
                'project_id' => $request->project_id,
                'event_type' => CalendarEventTypeEnum::DEADLINE->value,
                'title' => $title,
                'color' => '#1976D2',
                'start_date' => '2026-10-24',
                'end_date' => $endDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'all_day' => false,
            ]);
        }

        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Moscow');
        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'Europe/Moscow'));

        try {
            $ical = $calendarService->exportToICal(
                $request->organization_id,
                Carbon::parse('2026-10-24'),
                Carbon::parse('2026-10-25'),
                $request->project_id,
            );
        } finally {
            Carbon::setTestNow();
            date_default_timezone_set($timezone);
        }

        foreach ([
            ['Timed same day', '20261024T091530', '20261024T104515'],
            ['Timed overnight', '20261024T233000', '20261025T013000'],
            ['Timed open end', '20261024T090000', '20261025T000000'],
        ] as [$title, $start, $end]) {
            $this->assertStringContainsString("DTSTART:{$start}\r\nDTEND:{$end}\r\nSUMMARY:{$title}\r\n", $ical);
        }

        $this->assertStringContainsString('DTSTAMP:20261023T090000Z', $ical);
    }

    public function test_ical_export_only_includes_events_overlapping_the_selected_days(): void
    {
        [$request, , $calendarService] = $this->publicationContext();
        $request->forceFill(['status' => SiteRequestStatusEnum::PENDING->value])->saveQuietly();

        foreach ([
            ['Past single', '2026-10-13', null],
            ['Past closed', '2026-10-13', '2026-10-18'],
            ['Ongoing', '2026-10-13', '2026-10-20'],
            ['Ends on first day', '2026-10-18', '2026-10-19'],
            ['First day', '2026-10-19', null],
            ['Last day', '2026-10-25', null],
            ['Next single', '2026-10-26', null],
            ['Next closed', '2026-10-26', '2026-10-28'],
        ] as [$title, $startDate, $endDate]) {
            $eventRequest = $request->replicate();
            $eventRequest->saveQuietly();
            SiteRequestCalendarEvent::query()->create([
                'site_request_id' => $eventRequest->id,
                'organization_id' => $request->organization_id,
                'project_id' => $request->project_id,
                'event_type' => CalendarEventTypeEnum::DEADLINE->value,
                'title' => $title,
                'color' => '#1976D2',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'all_day' => true,
            ]);
        }

        $start = Carbon::parse('2026-10-19');
        $end = Carbon::parse('2026-10-25');
        $expected = ['Ongoing', 'Ends on first day', 'First day', 'Last day'];
        $events = $calendarService->getCalendarEvents($request->organization_id, $start, $end, $request->project_id);
        $this->assertEqualsCanonicalizing($expected, $events->pluck('title')->all());
        $onFirstDay = $calendarService->getEventsOnDate($request->organization_id, $start);
        $this->assertEqualsCanonicalizing(['Ongoing', 'Ends on first day', 'First day'], $onFirstDay->pluck('title')->all());

        $ical = $calendarService->exportToICal($request->organization_id, $start, $end, $request->project_id);
        foreach ($expected as $title) {
            $this->assertStringContainsString("SUMMARY:{$title}\r\n", $ical);
        }
        foreach (['Past single', 'Past closed', 'Next single', 'Next closed'] as $title) {
            $this->assertStringNotContainsString("SUMMARY:{$title}\r\n", $ical);
        }
        $this->assertSame(4, substr_count($ical, 'BEGIN:VEVENT'));
    }

    private function publicationContext(bool $scheduleManagement = false): array
    {
        $organization = Organization::factory()->verified()->create();
        $this->prepareModuleEntitlements($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        AuthorizationContext::getProjectContext($project->id, $organization->id);
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
        $module->shouldReceive('hasScheduleManagement')->andReturn($scheduleManagement);

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
                string|array|null $requiredPermissions = null,
                string|array|null $interfaces = null,
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

    private function prepareModuleEntitlements(Organization $organization): void
    {
        foreach ([
            'site-requests' => ['site_requests.view', 'site_requests.edit'],
            'notifications' => ['notifications.receive.site_requests'],
        ] as $slug => $permissions) {
            $module = Module::query()->firstOrCreate(['slug' => $slug], [
                'name' => $slug,
                'version' => '1.0.0',
                'type' => 'feature',
                'billing_model' => 'free',
                'category' => 'test',
                'permissions' => $permissions,
                'is_active' => true,
                'is_system_module' => false,
            ]);
            $module->forceFill(['permissions' => $permissions, 'is_active' => true])->save();
        }

        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'auto_renew_enabled' => true,
        ]);
        OrganizationPackageSubscription::query()->create([
            'organization_id' => $organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'machinery',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 0,
            'current_period_start_at' => now()->subDay(),
            'current_period_end_at' => now()->addDays(30),
        ]);
    }

    private function legacyEvent(string $eventClass, array $properties): object
    {
        $reflection = new ReflectionClass($eventClass);
        $event = $reflection->newInstanceWithoutConstructor();

        foreach ($properties as $property => $value) {
            $reflection->getProperty($property)->setValue($event, $value);
        }

        return $event;
    }
}
