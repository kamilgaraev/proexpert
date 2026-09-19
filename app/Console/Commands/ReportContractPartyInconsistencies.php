<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contract;
use App\Services\Contract\ContractPartyConsistencyReport;
use Illuminate\Console\Command;

final class ReportContractPartyInconsistencies extends Command
{
    protected $signature = 'contracts:report-party-inconsistencies {--organization= : Organization ID}';
    protected $description = 'Report saved contract party inconsistencies without changing contracts';

    public function handle(ContractPartyConsistencyReport $report): int
    {
        $organizationId = filter_var($this->option('organization'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($organizationId === false) {
            $this->error(trans_message('contracts.report_organization_required'));

            return self::INVALID;
        }
        $contracts = Contract::query()->where('organization_id', $organizationId)
            ->with(['firstParty', 'secondParty', 'contractor']);
        foreach ($contracts->lazyById(200) as $contract) {
            $issues = $report->issues($contract);
            if ($issues !== []) {
                $this->line(json_encode([
                    'contract_id' => $contract->id,
                    'organization_id' => $contract->organization_id,
                    'issues' => $issues,
                    'requires_manual_review' => true,
                ], JSON_THROW_ON_ERROR));
            }
        }

        return self::SUCCESS;
    }
}
