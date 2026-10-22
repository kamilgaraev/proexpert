<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Services;

use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Crm\Models\CrmPipelineStage;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Database\Eloquent\Builder;

final readonly class CrmDealReportReadService
{
    public const LABEL_FIELDS = ['company_name', 'owner_name', 'stage_label'];

    public const HIDDEN_FIELDS = ['_report_crm_company_id', '_report_crm_owner_id', '_report_crm_stage_id', '_report_crm_pipeline_id'];

    private const BASE_FIELDS = ['id', 'organization_id', 'project_id', 'contract_id', 'title', 'status', 'stage_code', 'pipeline_code',
        'company_id', 'primary_contact_id', 'owner_user_id', 'expected_close_at', 'deleted_at'];

    public function __construct(private CrmTargetAccessService $access, private UserProjectAccessService $projects) {}

    public function query(User $actor, int $organizationId, Builder $readableDeals): Builder
    {
        $this->access->assertOperation($organizationId, $actor, 'crm.deals.view');
        if (! $readableDeals->getModel() instanceof CrmDeal) {
            throw new \LogicException('Expected a CRM deal restriction query');
        }
        $projects = $this->projects->queryAccessibleProjects($actor, $organizationId, true)->select('projects.id');
        $deals = CrmDeal::query()->forOrganization($organizationId)
            ->whereIn('crm_deals.id', (clone $readableDeals)->select('crm_deals.id'))
            ->where(static fn ($scope) => $scope->whereNull('crm_deals.project_id')->orWhereIn('crm_deals.project_id', $projects));
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
        $inner = (clone $deals)->select(array_map(static fn (string $column): string => $table.'.'.$column, self::BASE_FIELDS))
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
}
