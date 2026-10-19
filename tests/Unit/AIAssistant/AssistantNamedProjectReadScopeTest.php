<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantNamedProjectReadScope;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProductionSchedulePeriod;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProductionScheduleReader;
use PHPUnit\Framework\TestCase;

final class AssistantNamedProjectReadScopeTest extends TestCase
{
    public function test_relative_day_after_project_uses_ui_only_after_an_empty_exact_lookup(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['вчера' => '2026-10-18', 'позавчера' => '2026-10-17', 'ВЧЕРА' => '2026-10-18'] as $phrase => $date) {
                foreach (['', '?'] as $ending) {
                    $query = 'Сверь выполненные работы с графиком по проекту '.$phrase.$ending;
                    $calls = 0;
                    $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), function (array $arguments) use ($phrase, &$calls): array {
                        $calls++;
                        self::assertSame(['query' => $phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                        return $this->projectSearchResult([], false);
                    });
                    self::assertSame(1, $calls);
                    self::assertArrayNotHasKey('named_project_scope', $resolved);
                    self::assertSame(['project_id' => 104, 'period_start' => $date, 'period_end' => $date],
                        AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
                    $literal = AssistantNamedProjectReadScope::bind($this->plan($query), fn (): array => $this->projectSearchResult([['id' => 102, 'name' => $phrase]], false));
                    self::assertSame(['project_id' => 102], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
                    foreach ([$this->projectSearchResult([], true), $this->projectSearchResult([['id' => 102], ['id' => 103]], false), ['status' => 'error']] as $result) {
                        self::assertNull(AssistantRequestProjectScope::fromPlan(AssistantNamedProjectReadScope::bind($this->plan($query), fn (): array => $result))->projectId);
                    }
                }
                $quoted = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиком по проекту «'.$phrase.'»'), fn (): array => $this->projectSearchResult([], false));
                self::assertNull(AssistantRequestProjectScope::fromPlan($quoted)->projectId);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_indeclinable_camera_states_use_ui_scope_after_empty_exact_name_lookup(): void
    {
        foreach (['онлайн', 'офлайн', 'оффлайн', 'online', 'offline'] as $state) {
            $query = 'Какие камеры проекта '.$state.'?';
            self::assertNull(AssistantNamedProjectReadScope::name($this->plan($query)), $query);
            $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), function (array $arguments) use ($state): array {
                self::assertSame(['query' => $state, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                return $this->projectSearchResult([], false);
            });
            self::assertArrayNotHasKey('named_project_scope', $resolved);
            self::assertSame(['name' => 'get_project_camera_roster', 'arguments' => ['project_id' => 104]], AssistantRequiredProjectRead::fromPlan($resolved));
            $literal = AssistantNamedProjectReadScope::bind($this->plan($query), fn (): array => $this->projectSearchResult([['id' => 102]], false));
            self::assertSame(102, AssistantRequestProjectScope::fromPlan($literal)->projectId);
            foreach ([$this->projectSearchResult([['id' => 102], ['id' => 103]], false), ['status' => 'error']] as $result) {
                self::assertNull(AssistantRequestProjectScope::fromPlan(AssistantNamedProjectReadScope::bind($this->plan($query), fn (): array => $result))->projectId);
            }
            $quoted = AssistantNamedProjectReadScope::bind($this->plan('Какие камеры проекта «'.$state.'»?'), fn (): array => $this->projectSearchResult([], false));
            self::assertNull(AssistantRequestProjectScope::fromPlan($quoted)->projectId);
        }
    }

    public function test_short_adjective_predicates_preserve_ui_scope_unless_a_literal_project_exists(): void
    {
        foreach (['доступны', 'исправны', 'критичны', 'свободны', 'видны', 'доступен', 'доступна', 'доступно'] as $predicate) {
            foreach (['?', ''] as $ending) {
                $query = 'Какие камеры проекта '.$predicate.$ending;
                self::assertNull(AssistantNamedProjectReadScope::name($this->plan($query)), $query);
                $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), function (array $arguments) use ($predicate): array {
                    self::assertSame(['query' => $predicate, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                    return $this->projectSearchResult([], false);
                });
                self::assertArrayNotHasKey('named_project_scope', $resolved);
                self::assertSame(['name' => 'get_project_camera_roster', 'arguments' => ['project_id' => 104]], AssistantRequiredProjectRead::fromPlan($resolved));
                $named = AssistantNamedProjectReadScope::bind($this->plan($query), fn (): array => $this->projectSearchResult([['id' => 102, 'name' => $predicate]], false));
                self::assertSame(102, AssistantRequestProjectScope::fromPlan($named)->projectId);
                foreach ([$this->projectSearchResult([['id' => 102], ['id' => 103]], false), ['status' => 'error']] as $result) {
                    $blocked = AssistantNamedProjectReadScope::bind($this->plan($query), fn (): array => $result);
                    self::assertNull(AssistantRequestProjectScope::fromPlan($blocked)->projectId);
                }
            }
            $quoted = AssistantNamedProjectReadScope::bind($this->plan('Какие камеры проекта «'.$predicate.'»?'), fn (): array => $this->projectSearchResult([], false));
            self::assertNull(AssistantRequestProjectScope::fromPlan($quoted)->projectId);
        }
    }

    public function test_direct_project_names_keep_internal_punctuation_before_a_proven_command_boundary(): void
    {
        foreach (['Южный, корпус 2', 'Южный; корпус 2', 'Южный: корпус 2', 'Дома & сады', 'ЖК 1/2'] as $name) {
            foreach (['', ' и скажи, что осталось.', ', покажи сроки.', '; уточни ответственного.', ': назови остаток.'] as $ending) {
                $resolved = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиком проекта '.$name.$ending), function (array $arguments) use ($name): array {
                    self::assertSame(['query' => $name, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                    return $this->projectSearchResult([['id' => 102]], false);
                });
                self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId, $name.$ending);
            }
        }
        self::assertSame('Южный', \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportEntityQuery::extract('По проекту Южный, корпус 2', 'project'));
    }

    public function test_period_suffix_falls_back_after_full_exact_name_and_preserves_literal_names(): void
    {
        $query = 'Сверь выполненные работы с графиком проекта Северный дом за октябрь 2026';
        $seen = [];
        $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), function (array $arguments) use (&$seen): array {
            $seen[] = $arguments;

            return $this->projectSearchResult($arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], false);
        });
        self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
        self::assertSame(['Северный дом за октябрь 2026', 'Северный дом'], array_column($seen, 'query'));
        self::assertSame(['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'], AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
        $literal = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиком проекта Дом за октябрь'), fn (): array => $this->projectSearchResult([['id' => 95]], false));
        self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
        $ambiguousYear = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиком проекта «Северный дом» за октябрь'), fn (): array => $this->projectSearchResult([['id' => 102]], false));
        self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null], AssistantRequiredProjectRead::fromPlan($ambiguousYear)['arguments']);
    }

    public function test_a_delimiter_before_the_period_is_not_part_of_the_project_name(): void
    {
        foreach ([',', ':', ';', '—'] as $delimiter) {
            foreach (['Северный дом', 'Южный, корпус 2'] as $name) {
                $query = 'Сверь выполненные работы с графиком проекта '.$name.$delimiter.' за октябрь 2026';
                $seen = [];
                $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), function (array $arguments) use ($name, &$seen): array {
                    $seen[] = $arguments['query'];

                    return $this->projectSearchResult($arguments['query'] === $name ? [['id' => 102]] : [], false);
                });
                self::assertSame([$name.$delimiter.' за октябрь 2026', $name], $seen);
                self::assertSame(['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'], AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
                $literal = AssistantNamedProjectReadScope::bind($this->plan($query), fn (): array => $this->projectSearchResult([['id' => 95]], false));
                self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
            }
        }
        self::assertSame('Южный, корпус 2:', AssistantProductionSchedulePeriod::withoutSuffix('Южный, корпус 2:'));
    }

    public function test_unique_named_project_overrides_background_scope_and_tool_arguments(): void
    {
        $plan = $this->plan('В проекте «Учебный ПТО» ремонт уже сделан? По выполненным работам всё выглядит готовым. Сверь с графиком: что осталось незавершённым, какой плановый срок и кто отвечает?');
        self::assertNull(AssistantRequiredProjectRead::fromPlan($plan));
        $plan['named_project_scope'] = ['project_id' => 999, 'message_sha256' => hash('sha256', $plan['request']['message'])];
        $resolved = AssistantNamedProjectReadScope::bind($plan, function (array $arguments): array {
            self::assertSame(['query' => 'Учебный ПТО', 'limit' => 2, 'name_match' => 'exact'], $arguments);

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
                self::assertSame(['query' => 'Северный дом', 'limit' => 2, 'name_match' => 'exact'], $arguments);

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

    public function test_unquoted_project_names_keep_prepositions_without_changing_report_extraction(): void
    {
        foreach (['ЖК на Ленина', 'Школа для детей', 'Дом у озера', 'Центр по работе с детьми'] as $name) {
            foreach (['', ' и скажи, что осталось.', '. Покажи сроки.'] as $suffix) {
                $query = 'Сверь выполненные работы с графиком проекта '.$name.$suffix;
                $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), function (array $arguments) use ($name): array {
                    self::assertSame(['query' => $name, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                    return $this->projectSearchResult([['id' => 102]], false);
                });
                self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId, $query);
            }
        }
        self::assertSame('ЖК', \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportEntityQuery::extract('Отчёт проекта ЖК на Ленина', 'project'));
        foreach (['№102 за октябрь', '102 за октябрь', '№102 и скажи, что осталось'] as $number) {
            $resolved = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиком проекта '.$number), function (array $arguments): array {
                self::assertSame(102, $arguments['project_id']);

                return $this->projectSearchResult([['id' => 102]], false);
            });
            self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
        }
    }

    public function test_project_name_words_do_not_become_commands_or_financial_topics(): void
    {
        foreach (['Сметный дом', 'Договорный парк', 'Бюджетный квартал', 'работы'] as $name) {
            $query = 'Сверь выполненные работы с графиком проекта '.$name;
            self::assertTrue(AssistantProjectProductionScheduleReader::matches($query), $query);
            $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), function (array $arguments) use ($name): array {
                self::assertSame($name, $arguments['query']);

                return $this->projectSearchResult([['id' => 102]], false);
            });
            self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId, $query);
            foreach ([' и оплати договор', ' и закрой проект', '. Перенеси срок', ' нужно оплатить договор',
                ' и сколько потрачено?', ' и сформируй Excel'] as $suffix) {
                self::assertFalse(AssistantProjectProductionScheduleReader::matches($query.$suffix), $query.$suffix);
            }
        }
    }

    public function test_lowercase_clause_in_an_ordinary_question_is_not_an_unquoted_project_name(): void
    {
        foreach (['Какие нарушения проекта требуют устранения?', 'Какие нарушения нашего объекта нуждаются в устранении?',
            'Какие нарушения проекта мешают сдаче?', 'Сколько происшествий проекта зарегистрировано?',
            'Какие нарушения в нашем проекте препятствуют сдаче?'] as $query) {
            self::assertNull(AssistantNamedProjectReadScope::name($this->plan($query)), $query);
        }
        self::assertSame('Северный дом', AssistantNamedProjectReadScope::name($this->plan('Какие нарушения проекта Северный дом?')));
        self::assertSame('северный дом', AssistantNamedProjectReadScope::name($this->plan('Какие нарушения проекта северный дом?')));
        self::assertSame('требуют устранения', AssistantNamedProjectReadScope::name($this->plan('Какие нарушения проекта «требуют устранения»?')));
        $resolved = AssistantNamedProjectReadScope::bind($this->plan('Какие нарушения проекта зенит парк?'), function (array $arguments): array {
            self::assertSame(['query' => 'зенит парк', 'limit' => 2, 'name_match' => 'exact'], $arguments);

            return $this->projectSearchResult([['id' => 102]], false);
        });
        self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
        foreach ([['status' => 'error'], $this->projectSearchResult([['id' => 102], ['id' => 103]], false)] as $result) {
            $unresolved = AssistantNamedProjectReadScope::bind($this->plan('Какие нарушения проекта зенит парк?'), fn (): array => $result);
            self::assertNull(AssistantRequestProjectScope::fromPlan($unresolved)->projectId);
        }
        $implicit = AssistantNamedProjectReadScope::bind($this->plan('Какие нарушения проекта требуют устранения?'), fn (): array => $this->projectSearchResult([], false));
        self::assertArrayNotHasKey('named_project_scope', $implicit);
        self::assertSame(['name' => 'get_project_safety_summary', 'arguments' => ['project_id' => 104]], AssistantRequiredProjectRead::fromPlan($implicit));
    }

    public function test_exact_name_is_preferred_without_fuzzy_window_or_ui_fallback(): void
    {
        $calls = [];
        $resolved = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиком проекта «Дом».'), function (array $arguments) use (&$calls): array {
            $calls[] = $arguments;

            return ($arguments['name_match'] ?? null) === 'exact'
                ? $this->projectSearchResult([['id' => 102, 'name' => 'Дом']], false)
                : $this->projectSearchResult([['id' => 101, 'name' => 'Дом 1'], ['id' => 103, 'name' => 'Дом 2']], true);
        });
        self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
        self::assertSame([['query' => 'Дом', 'limit' => 2, 'name_match' => 'exact']], $calls);
        $duplicate = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиком проекта «Дом».'), fn (): array => $this->projectSearchResult([['id' => 101, 'name' => 'Дом'], ['id' => 102, 'name' => ' доМ ']], false));
        self::assertNull(AssistantRequestProjectScope::fromPlan($duplicate)->projectId);
    }

    public function test_unique_partial_name_is_kept_only_after_no_exact_matches(): void
    {
        $calls = [];
        $resolved = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиком проекта «Север».'), function (array $arguments) use (&$calls): array {
            $calls[] = $arguments;

            return ($arguments['name_match'] ?? null) === 'exact' ? $this->projectSearchResult([], false)
                : $this->projectSearchResult([['id' => 102, 'name' => 'Северный дом']], false);
        });
        self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
        self::assertCount(2, $calls);
        self::assertSame('exact', $calls[0]['name_match']);
        self::assertArrayNotHasKey('name_match', $calls[1]);
    }

    public function test_single_word_predicate_before_question_mark_or_end_uses_current_project(): void
    {
        foreach (['Какие камеры проекта работают?', 'Какие камеры проекта работают', 'Камеры проекта работают?'] as $query) {
            self::assertNull(AssistantNamedProjectReadScope::name($this->plan($query)), $query);
            $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), fn (): array => $this->projectSearchResult([], false));
            self::assertArrayNotHasKey('named_project_scope', $resolved);
            self::assertSame(['name' => 'get_project_camera_roster', 'arguments' => ['project_id' => 104]], AssistantRequiredProjectRead::fromPlan($resolved));
        }
        $named = AssistantNamedProjectReadScope::bind($this->plan('Какие камеры проекта работают?'), fn (): array => $this->projectSearchResult([['id' => 102, 'name' => 'работают']], false));
        self::assertSame(102, AssistantRequestProjectScope::fromPlan($named)->projectId);
        $missing = AssistantNamedProjectReadScope::bind($this->plan('Какие камеры проекта «работают»?'), fn (): array => $this->projectSearchResult([], false));
        self::assertTrue(AssistantRequestProjectScope::fromPlan($missing)->requested);
        self::assertNull(AssistantRequestProjectScope::fromPlan($missing)->projectId);
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

    public function test_pending_name_reply_keeps_original_period_and_filters_before_fresh_project_lookup(): void
    {
        $message = 'Сверь выполненные работы с графиком проекта «Старый дом».';
        $context = ['last_capability' => 'knowledge', 'pending_named_project_clarification' => ['message' => $message],
            'last_request' => ['message' => $message, 'context' => ['period' => 'октябрь 2026', 'filters' => ['status' => 'confirmed']]]];
        $current = ['message' => 'Северный дом', 'context' => ['source_module' => 'ai-assistant',
            'period' => 'ноябрь 2026', 'filters' => [], 'entity_refs' => [['type' => 'project', 'id' => 104]]]];
        $service = (new \ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        $merged = (new \ReflectionMethod(AIAssistantService::class, 'mergeContinuationRequestPayload'))->invoke($service, 'Северный дом', $current, $context);
        self::assertSame('октябрь 2026', $merged['context']['period']);
        self::assertSame(['status' => 'confirmed'], $merged['context']['filters']);
        self::assertSame($current['context']['entity_refs'], $merged['context']['entity_refs']);
        self::assertSame('Северный дом', $merged['message']);
    }

    public function test_named_project_semantics_do_not_change_other_operational_reader_routes(): void
    {
        foreach (['Какие нарушения проекта «Сметный дом»?' => ['get_project_safety_summary', 'Сметный дом'],
            'Проверь журналы работ проекта «Договорный парк».' => ['get_project_journal_readiness', 'Договорный парк'],
            'Какие камеры проекта «Бюджетный квартал»?' => ['get_project_camera_roster', 'Бюджетный квартал']] as $query => [$tool, $name]) {
            $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), function (array $arguments) use ($name): array {
                self::assertSame($name, $arguments['query']);

                return $this->projectSearchResult([['id' => 102]], false);
            });
            self::assertSame(['name' => $tool, 'arguments' => ['project_id' => 102]], AssistantRequiredProjectRead::fromPlan($resolved), $query);
            self::assertNotNull(AssistantRequiredProjectRead::decisionReader($tool, $query));
            self::assertContains($tool, \App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface::names([$tool], $resolved));
            self::assertStringNotContainsString($name, AssistantNamedProjectReadScope::planningQuery($query));
            self::assertSame(AssistantNamedProjectReadScope::semanticQuery($query), AssistantNamedProjectReadScope::semanticQuery(AssistantNamedProjectReadScope::semanticQuery($query)));
            self::assertSame($query, $resolved['request']['message']);
            self::assertNull(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry::intent($query.' И сколько потрачено?'));
            self::assertSame($query.' И сколько потрачено?', AssistantNamedProjectReadScope::planningQuery($query.' И сколько потрачено?'));
        }
    }

    public function test_numeric_quoted_name_stays_a_name_while_explicit_ids_keep_identity(): void
    {
        $query = 'Сверь выполненные работы с графиком проекта «102».';
        $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), function (array $arguments): array {
            self::assertSame(['query' => '102', 'limit' => 2, 'name_match' => 'exact'], $arguments);

            return $this->projectSearchResult([['id' => 95]], false);
        });
        self::assertSame(95, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
        foreach (['102', '№102', '«№102»', 'ID 102', '#102'] as $id) {
            $resolved = AssistantNamedProjectReadScope::bind($this->plan('Сверь выполненные работы с графиком проекта '.$id), function (array $arguments): array {
                self::assertSame(102, $arguments['project_id']);

                return $this->projectSearchResult([['id' => 102]], false);
            });
            self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
        }
    }

    public function test_scope_words_inside_a_quoted_project_name_do_not_request_organization_aggregates(): void
    {
        $query = 'Какие камеры проекта «Вся наша организация»?';
        self::assertFalse(AssistantRequestProjectScope::organizationWide($query));
        $resolved = AssistantNamedProjectReadScope::bind($this->plan($query), function (array $arguments): array {
            self::assertSame('Вся наша организация', $arguments['query']);

            return $this->projectSearchResult([['id' => 102]], false);
        });
        self::assertSame(102, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
        self::assertTrue(AssistantRequestProjectScope::organizationWide('Какие камеры проекта «Вся наша организация» и по всем нашим проектам?'));
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
