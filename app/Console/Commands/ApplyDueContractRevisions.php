<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Contract\ContractRevisionApplicationService;
use App\Services\Contract\ContractSupplementaryApplicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ApplyDueContractRevisions extends Command
{
    protected $signature = 'contracts:apply-due-revisions';

    protected $description = 'Apply confirmed contract revisions and supplementary documents whose effective date has arrived';

    public function handle(ContractRevisionApplicationService $applications, ContractSupplementaryApplicationService $supplementary): int
    {
        $failed = 0;
        $revisionIds = DB::table('contract_revision_activations')->where('status', 'scheduled')
            ->whereDate('effective_date', '<=', now()->toDateString())->orderBy('id')->limit(100)->pluck('id');
        foreach ($revisionIds as $id) {
            try {
                $applications->applyDue((int) $id);
            } catch (\Throwable) {
                $failed++;
            }
        }
        $documentIds = DB::table('contract_supplementary_activations')->where('status', 'scheduled')
            ->whereDate('effective_date', '<=', now()->toDateString())->orderBy('id')->limit(100)->pluck('id');
        foreach ($documentIds as $id) {
            try {
                $supplementary->applyDue((int) $id);
            } catch (\Throwable) {
                $failed++;
            }
        }
        $this->line(json_encode([
            'processed' => $revisionIds->count() + $documentIds->count(),
            'failed' => $failed,
        ], JSON_THROW_ON_ERROR));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
