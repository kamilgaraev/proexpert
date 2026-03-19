<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);
        $action = (string) $this->input('action');

        if ($organizationId <= 0 || $action === '') {
            return false;
        }

        $permission = match ($action) {
            'submit' => 'payments.invoice.issue',
            'approve' => 'payments.transaction.approve',
            'pay' => 'payments.transaction.register',
            'cancel' => 'payments.invoice.cancel',
            'schedule' => 'payments.schedule.create',
            default => null,
        };

        return $permission !== null
            && (bool) $this->user()?->can($permission, ['organization_id' => $organizationId]);
    }

    public function rules(): array
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);

        return [
            'ids' => 'required|array|min:1',
            'ids.*' => [
                'integer',
                Rule::exists('payment_documents', 'id')->where('organization_id', $organizationId),
            ],
            'action' => 'required|string|in:submit,approve,pay,cancel,schedule',
            'reason' => 'required_if:action,cancel,reject|string|min:3',
            'scheduled_at' => 'required_if:action,schedule|date',
        ];
    }
}
