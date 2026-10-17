<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportSubscriptionFilters;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantReportSubscriptionFiltersTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public static function scopeChanges(): array
    {
        return [
            'clear scalar' => [['clear_project_scope' => true], 52],
            'clear operator' => [['clear_project_scope' => true], ['operator' => 'eq', 'value' => 52]],
            'replace scalar' => [['project_id' => 100], 52],
            'replace operator' => [['project_id' => 100], ['operator' => 'eq', 'value' => 52]],
        ];
    }

    #[DataProvider('scopeChanges')]
    public function test_explicit_scope_change_drops_only_inherited_project_condition(array $arguments, mixed $projectFilter): void
    {
        $inherited = ['project_id' => $projectFilter, 'status' => ['operator' => 'eq', 'value' => 'active'], 'period_from' => '2026-10-05'];
        $original = $inherited;

        $filters = AssistantReportSubscriptionFilters::resolve($arguments, $inherited);

        self::assertSame(['status' => ['operator' => 'eq', 'value' => 'active'], 'period_from' => '2026-10-05'], $filters);
        self::assertSame($original, $inherited);
    }

    public static function unchangedScopes(): array
    {
        return [[[]], [['local_time' => '10:00']], [['clear_project_scope' => false]]];
    }

    #[DataProvider('unchangedScopes')]
    public function test_schedule_only_change_preserves_legacy_scope(array $arguments): void
    {
        $inherited = ['project_id' => ['operator' => 'eq', 'value' => 52], 'status' => 'active'];

        self::assertSame($inherited, AssistantReportSubscriptionFilters::resolve($arguments, $inherited));
    }

    public function test_supplied_filters_replace_conditions_without_mutating_original(): void
    {
        $inherited = ['project_id' => 52, 'status' => 'active'];

        self::assertSame(['status' => 'closed'], AssistantReportSubscriptionFilters::resolve([
            'project_id' => 100, 'filters_json' => '{"status":"closed"}',
        ], $inherited));
        self::assertSame(['project_id' => 52, 'status' => 'active'], $inherited);
        self::assertSame([], AssistantReportSubscriptionFilters::resolve(['filters_json' => '{}'], $inherited));
    }

    public static function invalidFilters(): array
    {
        return [
            'scalar' => ['1'], 'list' => ['[]'], 'malformed' => ['{'], 'not string' => [[]],
            'project' => ['{"project_id":52}'], 'projects' => ['{"project_ids":[52]}'],
            'organization' => ['{"organization_id":38}'], 'holding' => ['{"holding_organization_ids":[38]}'],
            'too long' => [str_repeat(' ', 16001)],
            'too deep' => [str_repeat('{"nested":', 13).'1'.str_repeat('}', 13)],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_supplied_scope_keys_and_invalid_json_are_rejected(mixed $json): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(trans_message('ai_report_subscriptions.invalid'));

        AssistantReportSubscriptionFilters::resolve(['filters_json' => $json], []);
    }
}
