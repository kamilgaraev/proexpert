<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

abstract class ReadonlyEstimateTool implements AIToolInterface
{
    protected function actor(?User $user): User
    {
        if ($user === null) {
            throw new AuthorizationException;
        }

        return $user;
    }

    protected function validate(array $arguments, array $rules): array
    {
        if (array_diff(array_keys($arguments), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['arguments' => trans_message('ai_assistant_financial.unverified_claim')]);
        }

        return Validator::make($arguments, $rules)->validate();
    }

    protected function schema(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
