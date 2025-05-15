<?php

namespace App\DTOs;

use App\Models\AdvanceAccountSetting;
use Spatie\DataTransferObject\DataTransferObject;

class AdvanceAccountSettingDTO extends DataTransferObject
{
    public ?int $id;
    public int $organization_id;
    public ?float $max_single_issue_amount;
    public ?int $report_submission_deadline_days;
    public ?float $dual_authorization_threshold;
    public bool $require_project_for_expense;
    public bool $notify_admin_on_overdue_report;
    public bool $notify_admin_on_high_value_transaction;
    public ?float $high_value_notification_threshold;
    public bool $notify_user_on_transaction_approval;
    public bool $notify_user_on_transaction_rejection;
    public ?int $send_report_reminder_days_before;
    public ?string $created_at;
    public ?string $updated_at;

    public static function fromModel(AdvanceAccountSetting $setting): self
    {
        return new self([
            'id' => $setting->id,
            'organization_id' => $setting->organization_id,
            'max_single_issue_amount' => $setting->max_single_issue_amount,
            'report_submission_deadline_days' => $setting->report_submission_deadline_days,
            'dual_authorization_threshold' => $setting->dual_authorization_threshold,
            'require_project_for_expense' => (bool) $setting->require_project_for_expense,
            'notify_admin_on_overdue_report' => (bool) $setting->notify_admin_on_overdue_report,
            'notify_admin_on_high_value_transaction' => (bool) $setting->notify_admin_on_high_value_transaction,
            'high_value_notification_threshold' => $setting->high_value_notification_threshold,
            'notify_user_on_transaction_approval' => (bool) $setting->notify_user_on_transaction_approval,
            'notify_user_on_transaction_rejection' => (bool) $setting->notify_user_on_transaction_rejection,
            'send_report_reminder_days_before' => $setting->send_report_reminder_days_before,
            'created_at' => $setting->created_at?->toIso8601String(),
            'updated_at' => $setting->updated_at?->toIso8601String(),
        ]);
    }

    public static function fromModelOrDefaults(AdvanceAccountSetting $setting = null, int $organizationId): self
    {
        if ($setting) {
            return self::fromModel($setting);
        }

        // Возвращаем DTO с значениями по умолчанию, если модель не предоставлена
        return new self([
            'id' => null,
            'organization_id' => $organizationId,
            'max_single_issue_amount' => null, // или ваше значение по умолчанию
            'report_submission_deadline_days' => null,
            'dual_authorization_threshold' => null,
            'require_project_for_expense' => false,
            'notify_admin_on_overdue_report' => false,
            'notify_admin_on_high_value_transaction' => false,
            'high_value_notification_threshold' => null,
            'notify_user_on_transaction_approval' => true,
            'notify_user_on_transaction_rejection' => true,
            'send_report_reminder_days_before' => null,
            'created_at' => null,
            'updated_at' => null,
        ]);
    }
} 