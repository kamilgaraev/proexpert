<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\Http\Responses\AdminResponse;
use Illuminate\Foundation\Http\FormRequest;

use function trans_message;

class CreateEstimateGenerationSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $authService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
        $user = $this->user();
        $context = $user?->current_organization_id ? ['organization_id' => $user->current_organization_id] : null;

        return $user !== null && $authService->can($user, 'ai_estimates.generate', $context);
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            'building_type' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'parameters' => ['nullable', 'array'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            AdminResponse::error(trans_message('estimate_generation.access_denied'), 403)
        );
    }
}
