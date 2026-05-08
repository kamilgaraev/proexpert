<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\DTOs\Activity\ActivityEventData;
use App\Enums\Activity\ActivityActionEnum;
use App\Models\Activity\ActivityEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Activity\ActivityEventRecorder;
use Tests\TestCase;

class ActivityEventRecorderTest extends TestCase
{
    public function test_it_records_presented_and_redacted_activity_event(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['name' => 'Иван']);
        $target = User::factory()->create(['name' => 'Анна']);

        app(ActivityEventRecorder::class)->record(ActivityEventData::make(
            organizationId: $organization->id,
            module: 'users',
            eventType: 'user.admin.created',
            action: ActivityActionEnum::Created,
            actorUserId: $actor->id,
            actorName: $actor->name,
            actorEmail: $actor->email,
            interface: 'admin',
            subjectType: 'user',
            subjectId: $target->id,
            subjectLabel: $target->name,
            targetUserId: $target->id,
            context: [
                'target_name' => $target->name,
                'password' => 'secret',
            ]
        ));

        $event = ActivityEvent::query()->firstOrFail();

        $this->assertSame('Иван создал пользователя Анна', $event->title);
        $this->assertSame('users', $event->module);
        $this->assertSame('[скрыто]', $event->context['password']);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame($target->id, $event->target_user_id);
    }
}
