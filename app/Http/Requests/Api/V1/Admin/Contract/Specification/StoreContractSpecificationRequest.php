<?php

namespace App\Http\Requests\Api\V1\Admin\Contract\Specification;

use Illuminate\Foundation\Http\FormRequest;
use App\DTOs\SpecificationDTO;

class StoreContractSpecificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'number' => 'required|string|max:255|unique:specifications,number',
            'spec_date' => 'required|date',
            'total_amount' => 'required|numeric|min:0',
            'scope_items' => 'required|array',
            'scope_items.*.name' => 'required|string',
            'scope_items.*.quantity' => 'nullable|numeric|min:0',
            'scope_items.*.unit' => 'nullable|string',
            'scope_items.*.price' => 'nullable|numeric|min:0',
            'status' => 'sometimes|string|in:draft,approved,archived',
        ];
    }

    public function messages(): array
    {
        return [
            'number.required' => 'Номер спецификации обязателен',
            'number.unique' => 'Спецификация с таким номером уже существует',
            'spec_date.required' => 'Дата спецификации обязательна',
            'spec_date.date' => 'Некорректный формат даты',
            'total_amount.required' => 'Сумма спецификации обязательна',
            'total_amount.numeric' => 'Сумма должна быть числом',
            'total_amount.min' => 'Сумма не может быть отрицательной',
            'scope_items.required' => 'Необходимо указать позиции спецификации',
            'scope_items.array' => 'Позиции должны быть массивом',
            'scope_items.*.name.required' => 'Название позиции обязательно',
            'scope_items.*.quantity.numeric' => 'Количество должно быть числом',
            'scope_items.*.price.numeric' => 'Цена должна быть числом',
            'status.in' => 'Недопустимый статус спецификации',
        ];
    }

    public function toDto(): SpecificationDTO
    {
        return new SpecificationDTO(
            number: $this->input('number'),
            spec_date: $this->input('spec_date'),
            total_amount: (float) $this->input('total_amount'),
            scope_items: $this->input('scope_items'),
            status: $this->input('status', 'draft')
        );
    }
}

