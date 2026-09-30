<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantRequestProgressTest extends TestCase
{
    public function test_progress_exposes_only_registered_codes_states_and_monotonic_ids(): void
    {
        $events = [
            ['id' => 1, 'code' => 'estimates', 'state' => 'started', 'name' => 'Private estimate', 'args' => ['id' => 123]],
            ['id' => 2, 'code' => 'private_name', 'state' => 'completed'],
            ['id' => 3, 'code' => 'warehouse', 'state' => 'failed'],
            ['id' => '4', 'code' => 'warehouse', 'state' => 'completed'],
            ['id' => 1, 'code' => 'warehouse', 'state' => 'completed'],
            ['id' => 5, 'code' => 'estimates', 'state' => 'completed', 'thought' => 'Hidden reasoning'],
        ];
        $this->assertSame([
            ['id' => 1, 'code' => 'estimates', 'state' => 'started'],
            ['id' => 5, 'code' => 'estimates', 'state' => 'completed'],
        ], AssistantRequestProgress::sanitize($events));
        $this->assertSame([], AssistantRequestProgress::sanitize(null));
    }

    public function test_progress_is_bounded_and_preserves_ids_when_old_events_are_dropped(): void
    {
        $events = [];
        for ($id = 1; $id <= 60; $id++) {
            $events = AssistantRequestProgress::append($events, 'warehouse', $id % 2 === 1 ? 'started' : 'completed');
        }
        $this->assertCount(24, $events);
        $this->assertSame(37, $events[0]['id']);
        $this->assertSame(60, $events[23]['id']);
        $this->assertSame($events, AssistantRequestProgress::append($events, 'warehouse', 'completed'));
    }

    public function test_unknown_event_cannot_be_written(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AssistantRequestProgress::append([], 'Private estimate name', 'completed');
    }

    public function test_tool_codes_depend_on_executed_tool_and_registered_domain(): void
    {
        $this->assertSame('warehouse', AssistantRequestProgress::toolCode('search_warehouse'));
        $this->assertSame('estimates', AssistantRequestProgress::toolCode('get_estimate_financial_snapshot'));
        $this->assertSame('work_volumes', AssistantRequestProgress::toolCode('assistant_domain_read', ['domain' => 'works', 'id' => 456]));
        $this->assertSame('estimates', AssistantRequestProgress::toolCode('assistant_domain_search', ['domain' => 'estimates']));
        $this->assertNull(AssistantRequestProgress::toolCode('assistant_domain_read', ['domain' => 'private text']));
        $this->assertNull(AssistantRequestProgress::toolCode('create_schedule_task'));
        $this->assertNull(AssistantRequestProgress::toolCode('assistant_domain_discover_capabilities', ['domain' => 'estimates']));
    }

    #[DataProvider('toolResults')]
    public function test_only_successful_reads_can_be_completed(mixed $result, bool $expected): void
    {
        $this->assertSame($expected, AssistantRequestProgress::toolCompleted($result));
    }

    public static function toolResults(): array
    {
        return [
            [['status' => 'success', 'results' => []], true],
            [['status' => 'resolved', 'estimate_id' => 1], true],
            [['source_refs' => [['entity_type' => 'estimate']]], true],
            [['results' => []], true],
            [['status' => 'failed', 'results' => [['id' => 1]]], false],
            [['status' => 'forbidden'], false],
            [['status' => 'access_denied'], false],
            [['status' => 'blocked_by_request_policy'], false],
            [['status' => 'pending_confirmation'], false],
            [['status' => 'unavailable'], false],
            [['status' => 'insufficient_data'], false],
            [['status' => 'unrecognized'], false],
            [['status' => 'success', 'error' => 'Failed'], false],
            [['success' => false, 'results' => []], false],
            [['status' => 'success', 'useful' => false], false],
            [[], false],
            ['Failed', false],
        ];
    }
}
