<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use App\Services\Contract\ContractSystemFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InstallContractSystemFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', Rule::in(array_column((new ContractSystemFields)->catalogue(), 'code'))]];
    }
}
