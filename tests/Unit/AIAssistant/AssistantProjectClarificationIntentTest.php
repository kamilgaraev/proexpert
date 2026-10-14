<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectClarificationIntent;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use PHPUnit\Framework\TestCase;

final class AssistantProjectClarificationIntentTest extends TestCase
{
    public function test_project_only_reply_preserves_the_rd_question_with_current_project_scope(): void
    {
        $payload = ['context' => ['entity_refs' => [['type' => 'project', 'id' => 103]]]];
        $previous = ['last_capability' => null, 'last_request' => ['message' => 'напиши каких разделов РД не хватает',
            'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
        $query = AssistantProjectClarificationIntent::query('по проекту общежитие на 1200 мест', $payload, $previous);
        self::assertSame("напиши каких разделов РД не хватает\nпо проекту общежитие на 1200 мест", $query);
        self::assertSame(['name' => 'get_project_design_readiness', 'arguments' => ['project_id' => 103]],
            AssistantRequiredProjectRead::fromPlan(['request' => ['message' => $query] + $payload]));
        self::assertSame([['type' => 'project', 'id' => 103]], $payload['context']['entity_refs']);
        self::assertNotNull(AssistantRequiredProjectRead::decisionReader('get_project_design_readiness', $query));
        self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_design_readiness', 'по проекту общежитие на 1200 мест'));
    }

    public function test_standalone_requests_ambiguous_or_missing_scope_and_writes_are_not_replayed(): void
    {
        $payload = ['context' => ['entity_refs' => [['type' => 'project', 'id' => 103]]]];
        $previous = ['last_request' => ['message' => 'напиши каких разделов РД не хватает']];
        foreach (['Покажи проект общежитие.', 'Какие платежи по проекту общежитие?',
            'по проекту общежитие оплати счета', 'по проекту общежитие и также покажи смету'] as $query) {
            self::assertSame($query, AssistantProjectClarificationIntent::query($query, $payload, $previous));
        }
        $query = 'по проекту общежитие';
        foreach ([[], ['context' => ['entity_refs' => [['type' => 'project', 'id' => 103], ['type' => 'project', 'id' => 104]]]],
            $payload + ['allow_actions' => true]] as $invalid) {
            self::assertSame($query, AssistantProjectClarificationIntent::query($query, $invalid, $previous));
        }
        foreach (['Отправь комплект заказчику.', 'Измени состав РД.', 'Оплати счета.'] as $message) {
            self::assertSame($query, AssistantProjectClarificationIntent::query($query, $payload, ['last_request' => ['message' => $message]]));
        }
    }
}
