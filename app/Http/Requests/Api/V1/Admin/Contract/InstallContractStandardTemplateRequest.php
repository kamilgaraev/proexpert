<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use App\Services\Contract\ContractStandardTemplates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InstallContractStandardTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', Rule::in(ContractStandardTemplates::CODES)]];
    }
}
