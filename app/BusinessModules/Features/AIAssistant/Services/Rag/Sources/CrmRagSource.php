<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class CrmRagSource extends ModelDomainRagSource
{
    public function sourceType(): string
    {
        return 'crm';
    }

    public function entities(): array
    {
        return [
            'crm_deal' => ['model' => \App\BusinessModules\Features\Crm\Models\CrmDeal::class, 'fields' => ['id','project_id','contract_id','title','status','stage_code','pipeline_code','company_id','primary_contact_id','owner_user_id','expected_close_at']],
            'crm_lead' => ['model' => \App\BusinessModules\Features\Crm\Models\CrmLead::class, 'fields' => ['id','title','status','priority','need_description','expected_start_date']],
            'crm_company' => ['model' => \App\BusinessModules\Features\Crm\Models\CrmCompany::class, 'fields' => ['id','name','legal_name','status','company_type','inn']],
            'crm_contact' => ['model' => \App\BusinessModules\Features\Crm\Models\CrmContact::class, 'fields' => ['id','full_name','position','company_id','status']],
            'crm_activity' => ['model' => \App\BusinessModules\Features\Crm\Models\CrmActivity::class, 'fields' => ['id','subject','body','status','type','due_at','deal_id']],
            'customer_issue' => ['model' => \App\Models\CustomerIssue::class, 'fields' => ['id','project_id','contract_id','title','issue_reason','status','body','due_date','resolved_at']]
        ];
    }
}