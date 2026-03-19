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
            'notes' => ['sometimes', 'string', 'max:5000'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
