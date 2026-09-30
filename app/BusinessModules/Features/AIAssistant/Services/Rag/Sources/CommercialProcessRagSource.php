<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class CommercialProcessRagSource extends ModelDomainRagSource
{
    public function sourceType(): string
    {
        return 'commercial_processes';
    }

    public function entities(): array
    {
        return [
            'commercial_proposal' => ['model' => \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal::class, 'fields' => ['id','number','title','status','project_id','contract_id','currency','valid_until','sent_at']]
        ];
    }
}