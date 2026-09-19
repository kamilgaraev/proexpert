<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Contract\ContractRevisionApplicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ApplyDueContractRevisions extends Command
{
    protected $signature = 'contracts:apply-due-revisions';

    protected $description = 'Apply confirmed contract revisions whose effective date has arrived';

    public function handle(ContractRevisionApplicationService $applications): int
    {
        $failed = 0;
        $ids = DB::table('contract_revision_activations')->where('status', 'scheduled')
            ->whereDate('effective_date', '<=', now()->toDateString())->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            try {
                $applications->applyDue((int) $id);
            } catch (\Throwable) {
                $failed++;
            }
        }
        $this->line(json_encode(['processed' => $ids->count(), 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
