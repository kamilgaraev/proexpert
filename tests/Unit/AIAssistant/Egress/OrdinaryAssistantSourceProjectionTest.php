<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrdinaryAssistantSourceProjectionTest extends TestCase
{
    private const PRIVATE_ACCOUNT = 'PRIVATE_ACCOUNT_CANARY';

    public function test_attention_projection_preserves_project_evidence_without_account_identity_or_derived_strings(): void
    {
        $projection = new OrdinaryAssistantSourceProjection;
        $result = self::sourceResult(['groups' => [['kind' => 'critical_quality', 'total' => 12, 'has_more' => true, 'title' => 'Учебные дефекты',
            'items' => [['entity_type' => 'quality_defect', 'id' => 23, 'title' => 'Синтетический дефект', 'status' => 'open',
                'deadline' => '2026-10-30', 'owner_name' => self::PRIVATE_ACCOUNT, 'owner_state' => 'inactive', 'source_version' => '2026-10-23']]]],
            'coverage' => ['critical_quality' => ['available' => true, 'total' => 12, 'label' => 'Учебная проверка']]]);
        $result['server_formatted_answer'] = self::PRIVATE_ACCOUNT;
        $result['live_entity_reads'] = [['contractor' => ['name' => self::PRIVATE_ACCOUNT]]];
        $result['data']['account'] = ['email' => self::PRIVATE_ACCOUNT];
        $selected = $projection->project('get_project_attention', $result, 15, 7, 99);
        $wire = $projection->forProvider($selected);
        self::assertStringNotContainsString(self::PRIVATE_ACCOUNT, json_encode($wire, JSON_THROW_ON_ERROR));
        self::assertSame(12, $wire['data']['groups'][0]['total']);
        self::assertTrue($wire['data']['groups'][0]['has_more']);
        self::assertSame('assigned', $wire['data']['groups'][0]['items'][0]['assignment_state']);
        self::assertArrayNotHasKey('owner_state', $wire['data']['groups'][0]['items'][0]);
        self::assertArrayNotHasKey('source_version', $wire['data']['groups'][0]['items'][0]);
        self::assertSame([['entity_type' => 'project', 'entity_id' => 99, 'project_id' => 99]], $wire['source_refs']);
        self::assertArrayNotHasKey('actor_id', $wire['source_refs'][0]);
        self::assertArrayNotHasKey('organization_id', $wire['source_refs'][0]);
        self::assertArrayNotHasKey('source_version', $wire['source_refs'][0]);
        self::assertArrayNotHasKey('fetched_at', $wire['source_refs'][0]);
        self::assertArrayHasKey('source_version', $selected['source_refs'][0]);
    }

    public function test_schedule_projection_preserves_aggregate_units_and_examples_without_owner_names(): void
    {
        $projection = new OrdinaryAssistantSourceProjection;
        $result = self::sourceResult(['confirmed_works' => 15, 'open_tasks' => 4, 'overdue_tasks' => 2,
            'accepted_volumes' => [['unit' => 'м³', 'records' => 15, 'quantity' => '12.50']],
            'examples' => [['id' => 41, 'name' => 'Учебная задача', 'status' => 'in_progress', 'owner' => self::PRIVATE_ACCOUNT,
                'owner_state' => 'known', 'is_overdue' => true]]]);
        $wire = $projection->forProvider($projection->project('get_project_production_schedule_summary', $result, 15, 7, 99));
        self::assertStringNotContainsString(self::PRIVATE_ACCOUNT, json_encode($wire, JSON_THROW_ON_ERROR));
        self::assertSame('12.50', $wire['data']['accepted_volumes'][0]['quantity']);
        self::assertSame('м³', $wire['data']['accepted_volumes'][0]['unit']);
        self::assertSame(4, $wire['data']['open_tasks']);
        self::assertSame('assigned', $wire['data']['examples'][0]['assignment_state']);
    }

    public static function foreignScope(): array
    {
        return [['data-project'], ['ref-project'], ['ref-organization'], ['ref-actor'], ['string-result']];
    }

    #[DataProvider('foreignScope')]
    public function test_unknown_or_foreign_source_cannot_receive_a_project_projection(string $case): void
    {
        $result = self::sourceResult([]);
        match ($case) {
            'data-project' => $result['data']['project']['id'] = 100,
            'ref-project' => $result['source_refs'][0]['project_id'] = 100,
            'ref-organization' => $result['source_refs'][0]['organization_id'] = 16,
            'ref-actor' => $result['source_refs'][0]['actor_id'] = 8,
            'string-result' => $result = self::PRIVATE_ACCOUNT,
        };
        $this->expectException(AssistantEgressDenied::class);
        (new OrdinaryAssistantSourceProjection)->project('get_project_attention', $result, 15, 7, 99);
    }

    public static function forbiddenArguments(): array
    {
        return [['get_project_attention', ['project_id' => 100, 'focus' => 'quality', 'limit' => 20]],
            ['get_project_attention', ['project_id' => 99, 'focus' => 'procurement', 'limit' => 20]],
            ['get_project_attention', ['project_id' => 99, 'focus' => 'quality', 'limit' => 20, 'query' => self::PRIVATE_ACCOUNT]],
            ['search_assistant_documents', ['project_id' => 99]], ['get_project_workforce_summary', ['project_id' => 99]]];
    }

    #[DataProvider('forbiddenArguments')]
    public function test_initial_sql_source_policy_cannot_expand_through_tool_arguments(string $tool, array $arguments): void
    {
        $this->expectException(AssistantEgressDenied::class);
        (new OrdinaryAssistantSourceProjection)->arguments($tool, $arguments, 99);
    }

    public function test_null_optional_periods_have_the_same_registered_source_identity(): void
    {
        $projection = new OrdinaryAssistantSourceProjection;
        self::assertSame($projection->arguments('get_project_production_schedule_summary', ['project_id' => 99], 99),
            $projection->arguments('get_project_production_schedule_summary', ['project_id' => 99, 'period_start' => null, 'period_end' => null], 99));
    }

    private static function sourceResult(array $data): array
    {
        return ['status' => 'partial', 'data' => ['project' => ['id' => 99, 'name' => 'Учебный проект'], 'as_of_date' => '2026-10-23'] + $data,
            'source_refs' => [['entity_type' => 'synthetic_projection', 'entity_id' => 99, 'project_id' => 99,
                'organization_id' => 15, 'actor_id' => 7, 'source_version' => self::PRIVATE_ACCOUNT, 'fetched_at' => '2026-10-23T00:00:00Z']]];
    }
}
