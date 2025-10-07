<?php

namespace App\BusinessModules\Features\Notifications\Integration;

use App\Events\ContractStatusChanged;
use App\Events\ContractLimitWarning;
use App\BusinessModules\Features\Notifications\Facades\Notify;
use Illuminate\Support\Facades\Event;

class ContractEventIntegration
{
    public static function register(): void
    {
        Event::listen(ContractStatusChanged::class, function (ContractStatusChanged $event) {
            $contract = $event->contract;
            $users = $contract->organization->owners;

            foreach ($users as $user) {
                Notify::send(
                    $user,
                    'contract_status_changed',
                    [
                        'contract' => [
                            'id' => $contract->id,
                            'number' => $contract->number,
                            'total_amount' => number_format($contract->total_amount, 2, ',', ' '),
                            'completion_percentage' => round($contract->completion_percentage, 2),
                            'project_name' => $contract->project?->name,
                            'url' => url("/contracts/{$contract->id}"),
                        ],
                        'old_status' => $event->oldStatus,
                        'new_status' => $event->newStatus,
                    ],
                    'system',
                    'high',
                    null,
                    $contract->organization_id
                );
            }
        });

        Event::listen(ContractLimitWarning::class, function (ContractLimitWarning $event) {
            $contract = $event->contract;
            $users = $contract->organization->owners;

            foreach ($users as $user) {
                Notify::send(
                    $user,
                    'contract_limit_warning',
                    [
                        'contract' => [
                            'id' => $contract->id,
                            'number' => $contract->number,
                            'project_name' => $contract->project?->name,
                            'completion_percentage' => round($contract->completion_percentage, 2),
                            'completed_works_amount' => number_format($contract->completed_works_amount, 2, ',', ' '),
                            'total_amount' => number_format($contract->total_amount, 2, ',', ' '),
                            'remaining_amount' => number_format($contract->remaining_amount, 2, ',', ' '),
                            'url' => url("/contracts/{$contract->id}"),
                        ],
                        'level' => $event->getWarningLevel(),
                        'message' => $event->getWarningMessage(),
                    ],
                    'system',
                    'critical',
                    null,
                    $contract->organization_id
                );
            }
        });
    }
}

