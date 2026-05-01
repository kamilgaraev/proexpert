<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();
        $organizationId = (int) $this->attributes->get('current_organization_id');

        if (!$user || $organizationId <= 0) {
            return false;
        }

        return app(AuthorizationService::class)->can($user, 'procurement.purchase_requests.create', [
            'organization_id' => $organizationId,
        ]);
    }

    public function rules(): array
    {
        $organizationId = (int) $this->attributes->get('current_organization_id');

        return [
            'site_request_id' => [
                'sometimes',
                'integer',
                Rule::exists('site_requests', 'id')->where(static function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->whereNull('deleted_at');
                }),
            ],
            'assigned_to' => [
                'sometimes',
                'integer',
                Rule::exists('organization_user', 'user_id')->where(static function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->where('is_active', true);
                }),
            ],
            'needed_by' => ['sometimes', 'nullable', 'date'],
            'budget_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'budget_currency' => ['sometimes', 'string', 'size:3'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.material_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('materials', 'id')->where(static function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->whereNull('deleted_at');
                }),
            ],
            'lines.*.name' => ['required_with:lines', 'string', 'max:1000'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'min:0.001'],
            'lines.*.unit' => ['required_with:lines', 'string', 'max:32'],
            'lines.*.specification' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'lines.*.needed_by' => ['sometimes', 'nullable', 'date'],
            'lines.*.metadata' => ['sometimes', 'array'],
            'notes' => ['sometimes', 'string', 'max:5000'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
