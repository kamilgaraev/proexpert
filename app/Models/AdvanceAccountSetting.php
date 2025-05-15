<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvanceAccountSetting extends Model
{
    use HasFactory;

    protected $table = 'advance_account_settings';

    protected $fillable = [
        'organization_id',
        'max_single_issue_amount',
        'report_submission_deadline_days',
        'dual_authorization_threshold',
        'require_project_for_expense',
        'notify_admin_on_overdue_report',
        'notify_admin_on_high_value_transaction',
        'high_value_notification_threshold',
        'notify_user_on_transaction_approval',
        'notify_user_on_transaction_rejection',
        'send_report_reminder_days_before',
    ];

    protected $casts = [
        'max_single_issue_amount' => 'decimal:2',
        'report_submission_deadline_days' => 'integer',
        'dual_authorization_threshold' => 'decimal:2',
        'require_project_for_expense' => 'boolean',
        'notify_admin_on_overdue_report' => 'boolean',
        'notify_admin_on_high_value_transaction' => 'boolean',
        'high_value_notification_threshold' => 'decimal:2',
        'notify_user_on_transaction_approval' => 'boolean',
        'notify_user_on_transaction_rejection' => 'boolean',
        'send_report_reminder_days_before' => 'integer',
    ];

    /**
     * Организация, к которой относятся настройки.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
} 