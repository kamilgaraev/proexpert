<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentAccessService;
use App\Models\User;

class BulkActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);
        $action = (string) $this->input('action');

        if ($organizationId <= 0 || $action === '') {
            return false;
        }

        $permission = PaymentDocumentAccessService::bulkPermission($action);
        $actor = $this->user();
        if ($permission === null || ! $actor instanceof User) {
            return false;
        }
        $ids = $this->input('ids');
        if (! is_array($ids) || $ids === []) {
            return true;
        }
        $validIds = [];
        foreach ($ids as $id) {
            $validId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validId !== false) {
                $validIds[] = $validId;
            }
        }
        if ($validIds !== []) {
            app(PaymentDocumentAccessService::class)->bulk($organizationId, $actor, $validIds, $permission);
        }

        return true;
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
            'budget_override_reason' => 'nullable|string|max:1000',
        ];
    }
}
