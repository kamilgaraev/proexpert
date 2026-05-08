<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\DTOs\Activity\ActivityEventData;
use App\Enums\Activity\ActivityActionEnum;
use App\Models\Activity\ActivityEvent;
use App\Services\Activity\ActivityEventPresenter;
use Tests\TestCase;

class ActivityEventPresenterTest extends TestCase
{
    public function test_it_builds_human_title_from_event_dictionary(): void
    {
        $presentation = app(ActivityEventPresenter::class)->presentForData(ActivityEventData::make(
            organizationId: 1,
            module: 'users',
            eventType: 'user.admin.created',
            action: ActivityActionEnum::Created,
            actorName: 'Иван',
            subjectLabel: 'Анна',
            context: ['target_name' => 'Анна']
        ));

        $this->assertSame('Иван создал пользователя Анна', $presentation['title']);
        $this->assertSame('Пользователь добавлен в организацию.', $presentation['description']);
    }

    public function test_it_returns_only_safe_flat_details_for_resource(): void
    {
        $event = new ActivityEvent([
            'context' => [
                'role' => 'organization_admin',
                'nested' => ['hidden' => true],
                'empty' => '',
            ],
        ]);

        $details = app(ActivityEventPresenter::class)->detailsForResource($event);

        $this->assertSame([
            [
                'label' => 'Роль',
                'value' => 'organization_admin',
            ],
        ], $details);
    }
}
