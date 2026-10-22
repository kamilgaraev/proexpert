<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;

final class AssistantCustomReportCompletion
{
    public static function requiresFile(array $plan): bool
    {
        $message = (string) ($plan['request']['message'] ?? '');
        $understanding = AssistantRequestUnderstanding::fromArray($plan['request_understanding'] ?? []);

        return in_array($understanding->outputFormat, ['xlsx', 'csv', 'pdf'], true)
            && AssistantCustomReportIntent::matches($message, $understanding)
            && ! AssistantPublishedReportIntent::matches($message, $understanding)
            && ! AssistantPublishedReportIntent::readinessMatches($message, $understanding);
    }

    public static function toolChoice(array $plan, array $tools, array $results, array $attemptedTools = [], array $sourceHints = []): ?array
    {
        if (! self::requiresFile($plan)) {
            return null;
        }
        $advertised = array_map(static fn (array $tool): mixed => $tool['function']['name'] ?? $tool['name'] ?? null, $tools);
        if (! in_array('generate_custom_report', $advertised, true)) {
            return null;
        }
        foreach ([...$attemptedTools, ...array_column($results, '_tool_name')] as $toolName) {
            if ($toolName === 'check_published_report_readiness'
                || (str_starts_with($toolName, 'generate_') && str_ends_with($toolName, '_report'))) {
                return null;
            }
        }
        foreach ($results as $result) {
            if (($result['needs_clarification'] ?? false) === true) {
                return null;
            }
        }

        return self::hasSource($results, $plan['request_understanding']['output_format'], $sourceHints)
            ? ['type' => 'function', 'function' => ['name' => 'generate_custom_report']] : null;
    }

    public static function missingFile(array $plan, array $results, array $artifacts, array $controls): bool
    {
        if (! self::requiresFile($plan) || $artifacts !== [] || $controls !== []) {
            return false;
        }
        foreach ($results as $result) {
            if (($result['needs_clarification'] ?? false) === true) {
                return false;
            }
        }

        return true;
    }

    private static function hasSource(array $results, string $format, array $sourceHints): bool
    {
        foreach ($results as $result) {
            if (($result['_tool_name'] ?? null) === 'describe_custom_report_source' && ($result['status'] ?? null) === 'success'
                && self::hasFields($result)
                && in_array($format, $result['formats'] ?? [], true)) {
                return true;
            }
        }
        foreach ($sourceHints as $hint) {
            if (self::hasFields($hint)) {
                return true;
            }
        }

        return false;
    }

    private static function hasFields(array $source): bool
    {
        return is_string($source['domain'] ?? null) && $source['domain'] !== ''
            && is_string($source['entity_type'] ?? null) && $source['entity_type'] !== ''
            && is_array($source['fields'] ?? null) && $source['fields'] !== [];
    }
}
