<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ContractPerformanceAct;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use function trans_message;

class ActReportStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ContractPerformanceAct $act,
        private readonly string $message
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'act_report_status',
            'title' => $this->message,
            'message' => $this->message,
            'category' => 'act_reports',
            'notification_type' => 'act_reports',
            'interface' => 'admin',
            'act_id' => $this->act->id,
            'act_number' => $this->act->act_document_number,
            'contract_id' => $this->act->contract_id,
            'contract_number' => $this->act->contract?->number,
            'project_id' => $this->act->project_id,
            'project_name' => $this->act->contract?->project?->name,
            'status' => $this->act->status,
            'status_label' => $this->statusLabel((string) $this->act->status),
            'entity' => [
                'type' => 'act_report',
                'id' => $this->act->id,
            ],
            'target_route' => "/reports/act-reports/{$this->act->id}",
        ];
    }

    private function statusLabel(string $status): string
    {
        return trans_message("act_reports.statuses.{$status}");
    }
}
