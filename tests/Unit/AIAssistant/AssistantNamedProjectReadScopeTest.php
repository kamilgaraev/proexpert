<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantNamedProjectReadScope;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProductionScheduleReader;
use PHPUnit\Framework\TestCase;

final class AssistantNamedProjectReadScopeTest extends TestCase
{
    public function test_unique_named_project_overrides_background_scope_and_tool_arguments(): void
    {
        $plan = $this->plan('В проекте «Учебный ПТО» ремонт уже сделан? По выполненным работам всё выглядит готовым. Сверь с графиком: что осталось незавершённым, какой плановый срок и кто отвечает?');
        self::assertNull(AssistantRequiredProjectRead::fromPlan($plan));
        $plan['named_project_scope'] = ['project_id' => 999, 'message_sha256' => hash('sha256', $plan['request']['message'])];
        $resolved = AssistantNamedProjectReadScope::bind($plan, function (array $arguments): array {
            self::assertSame(['query' => 'Учебный ПТО', 'limit' => 2], $arguments);

            return $this->projectSearchResult([['id' => 102]], false);
        });
        self::assertSame(104, $resolved['request']['context']['entity_refs'][0]['id']);
        self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
        self::assertSame(['name' => 'get_project_production_schedule_summary', 'arguments' => ['project_id' => 102]], AssistantRequiredProjectRead::fromPlan($resolved));
        self::assertSame(['project_id' => 102], AssistantRequiredProjectRead::bindArguments($resolved, 'get_project_production_schedule_summary', ['project_id' => 104]));
        self::assertTrue(AssistantRequestProjectScope::fromPlan($resolved)->allowsReferences([['entity_type' => 'project', 'entity_id' => 102]]));
        self::assertFalse(AssistantRequestProjectScope::fromPlan($resolved)->allowsReferences([['entity_type' => 'project', 'entity_id' => 104]]));
    }

    public function test_ambiguous_missing_invalid_or_failed_lookup_never_falls_back_to_ui_project(): void
    {
        foreach ([$this->projectSearchResult([], false), $this->projectSearchResult([['id' => 102], ['id' => 103]], false),
            $this->projectSearchResult([['id' => 102]], true), $this->projectSearchResult([['id' => '102']], false),
            ['status' => 'success', 'results' => [['id' => 102]]], ['status' => 'error']] as $result) {
            $resolved = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиком проекта «СевГУ».'), fn (): array => $result);
            self::assertTrue(AssistantRequestProjectScope::fromPlan($resolved)->requested);
            self::assertNull(AssistantRequestProjectScope::fromPlan($resolved)->projectId);
            self::assertNull(AssistantRequiredProjectRead::fromPlan($resolved));
        }
        $resolved = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиком проекта «СевГУ».'), static function (): never {
            throw new \RuntimeException('lookup_failed');
        });
        self::assertNull(AssistantRequestProjectScope::fromPlan($resolved)->projectId);
    }

    public function test_current_project_uses_ui_without_named_lookup_and_organization_scope_is_not_forced(): void
    {
        $search = static function (): never {
            self::fail('Unexpected named lookup');
        };
        $current = AssistantNamedProjectReadScope::bind($this->plan('Какие работы уже приняли на текущем объекте? Сверь с графиком.'), $search);
        self::assertArrayNotHasKey('named_project_scope', $current);
        self::assertSame(['project_id' => 104], AssistantRequiredProjectRead::fromPlan($current)['arguments']);
        $all = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиками по всем нашим проектам.'), $search);
        self::assertNull(AssistantRequiredProjectRead::fromPlan($all));
    }

    public function test_numeric_name_and_quoted_name_on_current_project_are_resolved_strictly(): void
    {
        $numeric = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиком проекта №102.'), function (array $arguments): array {
            self::assertSame(102, $arguments['project_id']);

            return $this->projectSearchResult([['id' => 102]], false);
        });
        self::assertSame(102, AssistantRequestProjectScope::fromPlan($numeric)->projectId);
        $named = AssistantNamedProjectReadScope::bind($this->plan('В нашем проекте «Сметный дом» сверь выполненные работы с графиком.'), fn (): array => $this->projectSearchResult([['id' => 102]], false));
        self::assertSame(102, AssistantRequestProjectScope::fromPlan($named)->projectId);
        self::assertSame('get_project_production_schedule_summary', AssistantRequiredProjectRead::fromPlan($named)['name']);
    }

    public function test_multiple_names_and_stale_server_marker_fail_closed(): void
    {
        $resolved = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиком проекта «Первый» и проекта «Второй».'), static function (): never {
            self::fail('Ambiguous names must not be searched as a single project');
        });
        self::assertNull(AssistantRequestProjectScope::fromPlan($resolved)->projectId);
        $resolved['named_project_scope'] = ['project_id' => 102, 'message_sha256' => hash('sha256', 'another question')];
        self::assertNull(AssistantRequestProjectScope::fromPlan($resolved)->projectId);
    }

    public function test_unquoted_project_name_excludes_following_question_and_preserves_name_words(): void
    {
        foreach (['Сверь выполненные работы с графиком проекта Северный дом и скажи, что осталось.',
            'Сверь выполненные работы с графиком проекта Северный дом. Покажи сроки.',
            'Сверь выполненные работы с графиком проекта Северный дом? Кто отвечает?',
            'Сверь выполненные работы с графиком проекта Северный дом какой срок завершения?'] as $query) {
            $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), function (array $arguments): array {
                self::assertSame(['query' => 'Северный дом', 'limit' => 2], $arguments);

                return $this->projectSearchResult([['id' => 102]], false);
            });
            self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId, $query);
        }
        foreach (['Сверь выполненные работы с графиком проекта Дом и сад и скажи, что осталось.' => 'Дом и сад',
            'Сверь выполненные работы с графиком проекта Дом им. Ленина. Покажи сроки.' => 'Дом им. Ленина',
            'Сверь выполненные работы с графиком проекта «Северный дом и скажи». Покажи сроки.' => 'Северный дом и скажи'] as $query => $name) {
            $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), function (array $arguments) use ($name): array {
                self::assertSame($name, $arguments['query']);

                return $this->projectSearchResult([['id' => 102]], false);
            });
            self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId, $query);
        }
    }

    public function test_file_and_write_requests_do_not_force_the_comparison_reader(): void
    {
        foreach (['Сформируй Excel: сверка выполненных работ с графиком проекта «ПТО».',
            'Сверь выполненные работы с графиком и закрой проект.', 'Сверь выполненные работы с графиком и оплати договор.',
            'Сверь выполненные работы с графиком и перенеси срок.', 'Сверь выполненные работы с графиком и назначь ответственного.'] as $query) {
            self::assertFalse(AssistantProjectProductionScheduleReader::matches($query));
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($query)));
        }
    }

    public function test_unquoted_name_after_our_project_is_resolved_instead_of_background_project(): void
    {
        foreach (['Сверь выполненные работы с графиком в нашем проекте Северный дом',
            'Сверь выполненные работы с графиком нашего проекта северный дом и скажи, что осталось.',
            'Сверь выполненные работы с графиком на нашем объекте №102.'] as $query) {
            $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), fn (): array => $this->projectSearchResult([['id' => 102]], false));
            self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId, $query);
        }
        foreach (['Сверь выполненные работы с графиком в нашем проекте.',
            'Какие задачи нашего объекта сейчас задерживают этапы?',
            'Какие открытые нарушения нашего проекта ещё не устранены?',
            'Какие работы нашего проекта остались незавершёнными?'] as $query) {
            self::assertNull(AssistantNamedProjectReadScope::name($this->plan($query)), $query);
        }
    }

    public function test_conversation_target_survives_detail_followup_without_changing_original_ui_context(): void
    {
        $plan = $this->plan('Сверь выполненные работы с графиком проекта «Северный дом».');
        $plan += ['task_type' => 'summary', 'capability' => ['id' => 'schedules'], 'access_context_public' => []];
        $plan['request']['context']['entity_references'] = [['entity_type' => 'project', 'entity_id' => 104], ['type' => 'user', 'id' => 9]];
        $resolved = AssistantNamedProjectReadScope::bind($plan, fn (): array => $this->projectSearchResult([['id' => 102]], false));
        $service = (new \ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        $save = new \ReflectionMethod(AIAssistantService::class, 'buildLastRequestContext');
        $saved = $save->invoke($service, $plan['request']['message'], $resolved);
        self::assertSame(102, AssistantRequestProjectScope::fromPlan(['request' => $saved['last_request']])->projectId);
        self::assertSame([['type' => 'user', 'id' => 9]], $saved['last_request_context']['entity_references']);
        self::assertSame(104, $resolved['request']['context']['entity_refs'][0]['id']);
        self::assertArrayNotHasKey('named_project_scope', $saved['last_request']);
        $merge = new \ReflectionMethod(AIAssistantService::class, 'mergeContinuationRequestPayload');
        foreach ([[], ['entity_refs' => [['type' => 'project', 'id' => 104]]],
            ['entity_refs' => [['type' => 'project', 'id' => 104]], 'entity_references' => [['type' => 'project', 'id' => 104]]],
            ['entity_refs' => [['type' => 'project', 'id' => 104]], 'entity_references' => [['entity_type' => 'project', 'entity_id' => 104]]]] as $context) {
            $followup = $merge->invoke($service, 'а подробнее', ['message' => 'а подробнее', 'context' => $context], $saved);
            self::assertSame(102, AssistantRequestProjectScope::fromPlan(['request' => $followup])->projectId);
        }
        $explicit = ['entity_refs' => [['type' => 'project', 'id' => 103, 'explicit_selection' => true]]];
        self::assertSame(103, AssistantRequestProjectScope::fromPlan(['request' => $merge->invoke($service, 'а подробнее', ['message' => 'а подробнее', 'context' => $explicit], $saved)])->projectId);
        foreach ([null, 0] as $id) {
            $resolved['named_project_scope']['project_id'] = $id;
            self::assertNull(AssistantRequestProjectScope::fromPlan(['request' => $save->invoke($service, $plan['request']['message'], $resolved)['last_request']])->projectId);
        }
        $resolved['named_project_scope'] = ['project_id' => 102, 'message_sha256' => hash('sha256', 'old question')];
        self::assertNull(AssistantRequestProjectScope::fromPlan(['request' => $save->invoke($service, $plan['request']['message'], $resolved)['last_request']])->projectId);
    }

    private function plan(string $message): array
    {
        return ['request' => ['message' => $message, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
    }

    private function projectSearchResult(array $rows, bool $more): array
    {
        return ['status' => 'success', 'results' => $rows, 'result_window' => ['has_more' => $more]];
    }
}
