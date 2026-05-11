<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePurchaseContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();
        $organizationId = (int) $this->attributes->get('current_organization_id');

        if (!$user || $organizationId <= 0) {
            return false;
        }

        return app(AuthorizationService::class)->can($user, 'procurement.contracts.create', [
            'organization_id' => $organizationId,
        ]);
    }

    public function rules(): array
    {
        $organizationId = (int) $this->attributes->get('current_organization_id');

        return [
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where(static function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->where('is_active', true)
                        ->whereNull('deleted_at');
                }),
            ],
            'project_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where(static function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->whereNull('deleted_at');
                }),
            ],
            'number' => 'sometimes|string|max:255',
            'date' => 'required|date',
            'subject' => 'required|string|max:1000',
            'total_amount' => 'required|numeric|min:0',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'notes' => 'sometimes|string|max:5000',
        ];
    }
}

