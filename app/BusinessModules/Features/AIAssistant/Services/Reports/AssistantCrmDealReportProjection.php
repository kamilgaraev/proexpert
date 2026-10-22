<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\Crm\Services\CrmDealReportReadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class AssistantCrmDealReportProjection
{
    public const LABEL_FIELDS = CrmDealReportReadService::LABEL_FIELDS;

    public const HIDDEN_FIELDS = CrmDealReportReadService::HIDDEN_FIELDS;

    public static function links(array $rows, array $fields): array
    {
        $links = array_map(static fn (array $row): array => [
            'deal_id' => (string) $row['id'],
            'company_id' => in_array('company_name', $fields, true) ? $row['_report_crm_company_id'] : null,
            'owner_user_id' => in_array('owner_name', $fields, true) && $row['_report_crm_owner_id'] !== null ? (int) $row['_report_crm_owner_id'] : null,
            'stage_id' => in_array('stage_label', $fields, true) ? $row['_report_crm_stage_id'] : null,
            'pipeline_id' => in_array('stage_label', $fields, true) ? $row['_report_crm_pipeline_id'] : null,
        ], $rows);
        usort($links, static fn (array $left, array $right): int => strcmp($left['deal_id'], $right['deal_id']));

        return $links;
    }

    public function canReadLinks(Builder $deals, mixed $links, array $ids, array $fields): bool
    {
        if (! is_array($links) || ! array_is_list($links) || count($links) !== count($ids) || count($links) > 1000) {
            return false;
        }
        $normalized = $seen = [];
        foreach ($links as $link) {
            if (! is_array($link) || count($link) !== 5 || array_diff(['deal_id', 'company_id', 'owner_user_id', 'stage_id', 'pipeline_id'], array_keys($link)) !== []
                || ! is_string($link['deal_id']) || ! Str::isUuid($link['deal_id']) || isset($seen[$link['deal_id']])
                || ($link['owner_user_id'] !== null && (! is_int($link['owner_user_id']) || $link['owner_user_id'] < 1))) {
                return false;
            }
            foreach (['company_id', 'stage_id', 'pipeline_id'] as $field) {
                if ($link[$field] !== null && (! is_string($link[$field]) || ! Str::isUuid($link[$field]))) {
                    return false;
                }
            }
            if (($link['stage_id'] === null) !== ($link['pipeline_id'] === null)) {
                return false;
            }
            $seen[$link['deal_id']] = true;
            $normalized[] = ['deal_id' => $link['deal_id'], 'company_id' => $link['company_id'], 'owner_user_id' => $link['owner_user_id'],
                'stage_id' => $link['stage_id'], 'pipeline_id' => $link['pipeline_id']];
        }
        $expectedIds = array_keys($seen);
        $actualIds = array_map('strval', $ids);
        sort($expectedIds, SORT_STRING);
        sort($actualIds, SORT_STRING);
        if ($expectedIds !== $actualIds) {
            return false;
        }
        usort($normalized, static fn (array $left, array $right): int => strcmp($left['deal_id'], $right['deal_id']));
        $current = (clone $deals)->whereKey($ids)->select(['id', ...self::HIDDEN_FIELDS])->get()
            ->map(static fn ($deal): array => $deal->getRawOriginal())->all();

        return self::links($current, $fields) === $normalized;
    }
}
