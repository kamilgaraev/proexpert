<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use App\Rules\ProjectAccessibleRule;
use App\Services\Contract\ContractAccessService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

use function trans_message;

final class PaymentDocumentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = $this->organizationId();

        return $organizationId > 0
            && (bool) $this->user()?->can('payments.invoice.view', ['organization_id' => $organizationId]);
    }

    public function rules(): array
    {
        $organizationId = $this->organizationId();
        $projectId = $this->filled('project_id') && is_numeric($this->input('project_id'))
            ? (int) $this->input('project_id')
            : null;

        return [
            'document_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'project_id' => ['nullable', 'integer', new ProjectAccessibleRule()],
            'contract_id' => ['nullable', 'integer', $this->accessibleContractRule($organizationId, $projectId)],
            'purchase_order_id' => [
                'nullable',
                'integer',
                Rule::exists('purchase_orders', 'id')->where('organization_id', $organizationId),
            ],
            'estimate_id' => [
                'nullable',
                'integer',
                Rule::exists('estimates', 'id')->where('organization_id', $organizationId),
            ],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'amount_from' => ['nullable', 'numeric', 'min:0'],
            'amount_to' => ['nullable', 'numeric', 'min:0'],
            'search' => ['nullable', 'string'],
            'sort_by' => ['nullable', 'string', Rule::in([
                'created_at',
                'document_date',
                'due_date',
                'amount',
                'remaining_amount',
                'document_number',
                'status',
                'document_type',
            ])],
            'sort_order' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = $this->validated();
        $filters['sort_by'] = $filters['sort_by'] ?? 'created_at';
        $filters['sort_order'] = $filters['sort_order'] ?? 'desc';
        $filters['per_page'] = (int) ($filters['per_page'] ?? 100);

        return $filters;
    }

    private function organizationId(): int
    {
        return (int) $this->attributes->get('current_organization_id', 0);
    }

    private function accessibleContractRule(int $organizationId, ?int $projectId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($organizationId, $projectId): void {
            if ($value === null || $value === '') {
                return;
            }

            if (
                ! is_numeric($value)
                || app(ContractAccessService::class)->findAccessible((int) $value, $organizationId, $projectId) === null
            ) {
                $fail(trans_message('contract.contract_not_found'));
            }
        };
    }
}
