<?php

namespace App\DTOs;

use App\Models\AdvanceAccountSetting;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class AdvanceAccountSettingDTO implements Arrayable, JsonSerializable
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

    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? null;
        $this->organization_id = $data['organization_id'];
        $this->max_single_issue_amount = $data['max_single_issue_amount'] ?? null;
        $this->report_submission_deadline_days = $data['report_submission_deadline_days'] ?? null;
        $this->dual_authorization_threshold = $data['dual_authorization_threshold'] ?? null;
        $this->require_project_for_expense = (bool) ($data['require_project_for_expense'] ?? false);
        $this->notify_admin_on_overdue_report = (bool) ($data['notify_admin_on_overdue_report'] ?? false);
        $this->notify_admin_on_high_value_transaction = (bool) ($data['notify_admin_on_high_value_transaction'] ?? false);
        $this->high_value_notification_threshold = $data['high_value_notification_threshold'] ?? null;
        $this->notify_user_on_transaction_approval = (bool) ($data['notify_user_on_transaction_approval'] ?? false);
        $this->notify_user_on_transaction_rejection = (bool) ($data['notify_user_on_transaction_rejection'] ?? false);
        $this->send_report_reminder_days_before = $data['send_report_reminder_days_before'] ?? null;
        $this->created_at = $data['created_at'] ?? null;
        $this->updated_at = $data['updated_at'] ?? null;
    }

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

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'max_single_issue_amount' => $this->max_single_issue_amount,
            'report_submission_deadline_days' => $this->report_submission_deadline_days,
            'dual_authorization_threshold' => $this->dual_authorization_threshold,
            'require_project_for_expense' => $this->require_project_for_expense,
            'notify_admin_on_overdue_report' => $this->notify_admin_on_overdue_report,
            'notify_admin_on_high_value_transaction' => $this->notify_admin_on_high_value_transaction,
            'high_value_notification_threshold' => $this->high_value_notification_threshold,
            'notify_user_on_transaction_approval' => $this->notify_user_on_transaction_approval,
            'notify_user_on_transaction_rejection' => $this->notify_user_on_transaction_rejection,
            'send_report_reminder_days_before' => $this->send_report_reminder_days_before,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
