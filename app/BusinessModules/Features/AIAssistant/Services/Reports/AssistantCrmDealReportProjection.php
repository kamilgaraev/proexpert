<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmPipelineStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class AssistantCrmDealReportProjection
{
    public const LABEL_FIELDS = ['company_name', 'owner_name', 'stage_label'];

    public const HIDDEN_FIELDS = ['_report_crm_company_id', '_report_crm_owner_id', '_report_crm_stage_id', '_report_crm_pipeline_id'];

    public function apply(Builder $deals, int $organizationId, array $columns): Builder
    {
        $model = $deals->getModel();
        $table = $model->getTable();
        $companies = CrmCompany::query()->where('crm_companies.organization_id', $organizationId)
            ->whereColumn('crm_companies.organization_id', $table.'.organization_id')
            ->whereColumn('crm_companies.id', $table.'.company_id')->limit(1);
        $owners = User::query()->whereColumn('users.id', $table.'.owner_user_id')
            ->whereHas('organizations', static fn ($organizations) => $organizations
                ->where('organizations.id', $organizationId)->where('organization_user.is_active', true))->limit(1);
        $stages = CrmPipelineStage::query()->join('crm_pipelines', 'crm_pipelines.id', '=', 'crm_pipeline_stages.pipeline_id')
            ->whereColumn('crm_pipeline_stages.id', $table.'.stage_id')
            ->whereColumn('crm_pipeline_stages.pipeline_id', $table.'.pipeline_id')
            ->where('crm_pipelines.entity_type', 'deal')
            ->where(static fn ($pipelines) => $pipelines->where('crm_pipelines.organization_id', $organizationId)->orWhereNull('crm_pipelines.organization_id'))->limit(1);
        $stageLabel = (clone $stages)->select('crm_pipeline_stages.label');
        $inner = (clone $deals)->select(array_map(static fn (string $column): string => $table.'.'.$column, $columns))
            ->selectSub((clone $companies)->select('crm_companies.name'), 'company_name')
            ->selectSub((clone $companies)->select('crm_companies.id'), '_report_crm_company_id')
            ->selectSub((clone $owners)->select('users.name'), 'owner_name')
            ->selectSub((clone $owners)->select('users.id'), '_report_crm_owner_id')
            ->selectSub((clone $stages)->select('crm_pipeline_stages.id'), '_report_crm_stage_id')
            ->selectSub((clone $stages)->select('crm_pipeline_stages.pipeline_id'), '_report_crm_pipeline_id');
        $grammar = $inner->getQuery()->getGrammar();
        $inner->selectRaw('COALESCE(('.$stageLabel->toSql().'), '.$grammar->wrap($table.'.stage_code').') AS '.$grammar->wrap('stage_label'), $stageLabel->getBindings());

        return $model->newModelQuery()->fromSub($inner, $table);
    }

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
