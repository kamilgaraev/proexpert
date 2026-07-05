<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Counterparty;

use App\Enums\CounterpartyRoleEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SearchCounterpartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'inn' => ['nullable', 'string', 'max:12'],
            'role' => ['nullable', new Enum(CounterpartyRoleEnum::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return [
            'q' => 'поисковая строка',
            'search' => 'поисковая строка',
            'name' => 'название контрагента',
            'inn' => 'ИНН',
            'role' => 'роль контрагента',
            'limit' => 'лимит выдачи',
        ];
    }
}
