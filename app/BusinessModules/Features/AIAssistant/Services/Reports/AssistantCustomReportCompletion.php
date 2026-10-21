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

    public static function toolChoice(array $plan, array $tools, array $results, array $attemptedTools = []): ?array
    {
        if (! self::requiresFile($plan)) {
            return null;
        }
        $advertised = array_map(static fn (array $tool): mixed => $tool['function']['name'] ?? $tool['name'] ?? null, $tools);
        if (! in_array('generate_custom_report', $advertised, true)) {
            return null;
        }
        if (array_intersect(['generate_custom_report', 'generate_published_report', 'check_published_report_readiness'],
            [...$attemptedTools, ...array_column($results, '_tool_name')]) !== []) {
            return null;
        }
        $format = $plan['request_understanding']['output_format'];
        $hasSource = false;
        foreach ($results as $result) {
            $name = $result['_tool_name'] ?? null;
            if (($result['needs_clarification'] ?? false) === true) {
                return null;
            }
            if ($name === 'describe_custom_report_source' && ($result['status'] ?? null) === 'success'
                && is_string($result['domain'] ?? null) && $result['domain'] !== ''
                && is_string($result['entity_type'] ?? null) && $result['entity_type'] !== ''
                && is_array($result['fields'] ?? null) && $result['fields'] !== []
                && in_array($format, $result['formats'] ?? [], true)) {
                $hasSource = true;
            }
        }

        return $hasSource ? ['type' => 'function', 'function' => ['name' => 'generate_custom_report']] : null;
    }

    public static function missingFile(array $plan, bool $terminal, array $results, array $artifacts, array $controls): bool
    {
        if (! $terminal || ! self::requiresFile($plan) || $artifacts !== [] || $controls !== []) {
            return false;
        }
        foreach ($results as $result) {
            if (($result['needs_clarification'] ?? false) === true) {
                return false;
            }
        }

        return true;
    }
}
