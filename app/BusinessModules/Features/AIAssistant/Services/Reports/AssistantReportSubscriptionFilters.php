<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use InvalidArgumentException;
use JsonException;

final class AssistantReportSubscriptionFilters
{
    public static function resolve(array $arguments, array $inherited): array
    {
        if (! array_key_exists('filters_json', $arguments)) {
            if (array_key_exists('project_id', $arguments) || ($arguments['clear_project_scope'] ?? false) === true) {
                unset($inherited['project_id']);
            }

            return $inherited;
        }
        $json = $arguments['filters_json'];
        if (! is_string($json) || strlen($json) > 16000) {
            self::invalid();
        }
        try {
            $object = json_decode($json, false, 12, JSON_THROW_ON_ERROR);
            $filters = json_decode($json, true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            self::invalid();
        }
        if (! $object instanceof \stdClass || ! is_array($filters)
            || array_intersect(array_keys($filters), ['project_id', 'organization_id', 'holding_organization_ids', 'project_ids']) !== []) {
            self::invalid();
        }

        return $filters;
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException(trans_message('ai_report_subscriptions.invalid'));
    }
}
