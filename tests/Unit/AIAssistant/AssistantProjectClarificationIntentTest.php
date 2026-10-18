<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantNamedProjectReadScope;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectClarificationIntent;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use PHPUnit\Framework\TestCase;

final class AssistantProjectClarificationIntentTest extends TestCase
{
    public function test_pending_named_project_reply_replaces_the_old_name_and_resolves_without_ui_scope(): void
    {
        foreach (['Сверь выполненные работы с графиком проекта «Старый дом» и скажи, что осталось.',
            'Сверь выполненные работы с графиком проекта Старый дом и скажи, что осталось.'] as $message) {
            $previous = ['last_request' => ['message' => $message], 'pending_named_project_clarification' => ['message' => $message]];
            foreach (['Северный дом', 'по проекту Северный дом', 'для объекта «Северный дом»'] as $reply) {
                $query = AssistantProjectClarificationIntent::query($reply, ['context' => []], $previous);
                self::assertSame('Сверь выполненные работы с графиком проекта «Северный дом» и скажи, что осталось.', $query);
                $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => $query, 'context' => []]], function (array $arguments): array {
                    self::assertSame('Северный дом', $arguments['query']);

                    return ['status' => 'success', 'results' => [['id' => 102]], 'result_window' => ['has_more' => false]];
                });
                self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
                self::assertSame('get_project_production_schedule_summary', AssistantRequiredProjectRead::fromPlan($resolved)['name']);
            }
        }
    }

    public function test_pending_project_does_not_replay_new_questions_writes_or_stale_state(): void
    {
        $message = 'Сверь выполненные работы с графиком проекта «Старый дом».';
        $previous = ['last_request' => ['message' => $message], 'pending_named_project_clarification' => ['message' => $message]];
        foreach (['Покажи договоры', 'Какие платежи просрочены?', 'Оплати счета', 'Не знаю', 'Северный дом и также покажи смету'] as $reply) {
            self::assertSame($reply, AssistantProjectClarificationIntent::query($reply, [], $previous));
        }
        self::assertSame('Северный дом', AssistantProjectClarificationIntent::query('Северный дом', ['allow_actions' => true], $previous));
        $previous['pending_named_project_clarification']['message'] = 'Другой запрос';
        self::assertSame('Северный дом', AssistantProjectClarificationIntent::query('Северный дом', [], $previous));
    }

    public function test_project_only_reply_preserves_the_rd_question_with_current_project_scope(): void
    {
        $payload = ['context' => ['entity_refs' => [['type' => 'project', 'id' => 103]]]];
        $previous = ['last_capability' => null, 'last_request' => ['message' => 'напиши каких разделов РД не хватает',
            'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
        $query = AssistantProjectClarificationIntent::query('по проекту общежитие на 1200 мест', $payload, $previous);
        self::assertSame("напиши каких разделов РД не хватает\nпо проекту общежитие на 1200 мест", $query);
        $plan = AssistantNamedProjectReadScope::bind(['request' => ['message' => $query] + $payload], static function (array $arguments): array {
            self::assertSame('общежитие на 1200 мест', $arguments['query']);

            return ['status' => 'success', 'results' => [['id' => 103]], 'result_window' => ['has_more' => false]];
        });
        self::assertSame(['name' => 'get_project_design_readiness', 'arguments' => ['project_id' => 103]],
            AssistantRequiredProjectRead::fromPlan($plan));
        self::assertSame([['type' => 'project', 'id' => 103]], $payload['context']['entity_refs']);
        self::assertNotNull(AssistantRequiredProjectRead::decisionReader('get_project_design_readiness', $query));
        self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_design_readiness', 'по проекту общежитие на 1200 мест'));
    }

    public function test_clarification_replaces_multiple_prior_names_and_keeps_questions_and_name_mentions_inside_quotes(): void
    {
        $message = 'Сверь выполненные работы с графиком проекта «Первый» и объекта «Второй проект Север»; скажи, что осталось.';
        $previous = ['last_request' => ['message' => $message], 'pending_named_project_clarification' => ['message' => $message]];
        $query = AssistantProjectClarificationIntent::query('ЖК на Ленина', [], $previous);
        self::assertSame('Сверь выполненные работы с графиком проекта «ЖК на Ленина» и объекта «ЖК на Ленина»; скажи, что осталось.', $query);
        $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => $query, 'context' => []]], static fn (): array => [
            'status' => 'success', 'results' => [['id' => 102]], 'result_window' => ['has_more' => false]]);
        self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
    }

    public function test_pending_project_name_allows_punctuation_and_preserves_nested_quotes(): void
    {
        $message = 'Сверь выполненные работы с графиком проекта «Старый дом».';
        $previous = ['last_request' => ['message' => $message], 'pending_named_project_clarification' => ['message' => $message]];
        foreach (['ЖК 1/2', 'Южный, корпус 2', 'Дома & сады', 'ЖК «Южный»'] as $name) {
            $query = AssistantProjectClarificationIntent::query($name, [], $previous);
            self::assertSame($name, AssistantNamedProjectReadScope::name(['request' => ['message' => $query]]), $name);
            self::assertStringNotContainsString('Старый дом', $query);
        }
        foreach (['102' => 102, '«102»' => 95] as $reply => $expected) {
            $query = AssistantProjectClarificationIntent::query((string) $reply, [], $previous);
            $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => $query]], static fn (array $arguments): array => [
                'status' => 'success', 'results' => [['id' => isset($arguments['project_id']) ? 102 : 95]], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
        }
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
