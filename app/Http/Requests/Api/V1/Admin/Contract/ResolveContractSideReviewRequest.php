<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use App\Enums\Contract\ContractSideTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ResolveContractSideReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_side_type' => ['required', new Enum(ContractSideTypeEnum::class)],
        ];
    }

    public function contractSideType(): ContractSideTypeEnum
    {
        return ContractSideTypeEnum::from((string) $this->input('contract_side_type'));
    }
}
