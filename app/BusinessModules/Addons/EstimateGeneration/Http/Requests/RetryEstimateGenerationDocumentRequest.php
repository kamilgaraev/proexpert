<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class RetryEstimateGenerationDocumentRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        if (! $this->user() instanceof User) {
            return false;
        }

        return $this->authorizeEstimateGeneration('estimate_generation.review');
    }

    public function actor(): User
    {
        $actor = $this->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'state_version' => ['required', 'integer', 'min:0'],
            'source_version' => ['required', 'string', 'max:80'],
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
