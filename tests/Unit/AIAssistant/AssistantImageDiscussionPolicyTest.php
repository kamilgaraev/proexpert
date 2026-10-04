<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantAccessContextResolver;
use App\BusinessModules\Features\AIAssistant\Services\AssistantCapabilityRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantImageDiscussionPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantImageDiscussionPolicyTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    #[DataProvider('requests')]
    public function test_discussion_keeps_image_context_and_allows_explicit_system_requests(string $query, bool $currentImages, bool $previousDiscussion, bool $expected): void
    {
        self::assertSame($expected, AssistantImageDiscussionPolicy::isDiscussion($query, $currentImages, $previousDiscussion));
    }

    public static function requests(): iterable
    {
        yield 'read attached photo' => ['объясни что здесь написано', true, false, true];
        yield 'read estimate photo' => ['Расшифруй текст сметы на фото', true, false, true];
        yield 'meaning of attached photo' => ['что это может значить', true, false, true];
        yield 'meaning followup' => ['что это может значить', false, true, true];
        yield 'alternate meaning followup' => ['А что это может означать?', false, true, true];
        yield 'specific point' => ['Поясни второй пункт', false, true, true];
        yield 'abbreviation' => ['Что значит АПС?', false, true, true];
        yield 'more details' => ['Можно подробнее?', false, true, true];
        yield 'practical next step' => ['Как это исправить?', false, true, true];
        yield 'no image history' => ['что это может значить', false, false, false];
        yield 'new unrelated question' => ['Какая погода завтра?', false, true, false];
        yield 'read system estimate' => ['Покажи последнюю смету', false, true, false];
        yield 'system followup' => ['Что это значит для сметы в системе?', false, true, false];
        yield 'system context with image' => ['Что у нас со сметами?', true, false, false];
        yield 'compare image with estimate' => ['Сравни фото со сметой', true, false, false];
        yield 'check records' => ['Проверь сметы и склад', true, false, false];
        yield 'find matching positions' => ['Найди позиции сметы для этих замечаний', false, true, false];
        yield 'knowledge search' => ['Объясни это по базе знаний', false, true, false];
        yield 'create record' => ['Создай документ по этой фотографии', true, false, false];
    }

    public function test_image_answer_does_not_require_a_business_capability(): void
    {
        $orchestrator = new AssistantTaskOrchestrator(new AssistantCapabilityRegistry, $this->createMock(AssistantAccessContextResolver::class));
        $plan = $orchestrator->plan('Что значит смета на фото?', ['image_discussion' => true, 'goal' => 'analyze'], []);
        $payload = $orchestrator->buildPayload($plan, 'Это расчёт стоимости работ.');

        self::assertTrue($plan['image_discussion']);
        self::assertNull($plan['capability']);
        self::assertSame('analyze', $plan['task_type']);
        self::assertSame([], $payload['missing_data']);
        self::assertSame([], $payload['access_limits']);
        self::assertSame([], $payload['next_actions']);
    }

    public function test_ordinary_system_request_keeps_domain_resolution(): void
    {
        $orchestrator = new AssistantTaskOrchestrator(new AssistantCapabilityRegistry, $this->createMock(AssistantAccessContextResolver::class));
        $plan = $orchestrator->plan('Покажи последнюю смету', [], []);

        self::assertFalse($plan['image_discussion']);
        self::assertSame('estimates', $plan['capability']['id']);
    }
}
