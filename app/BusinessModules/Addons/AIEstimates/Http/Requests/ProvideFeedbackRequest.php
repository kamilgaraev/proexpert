<?php

namespace App\BusinessModules\Addons\AIEstimates\Http\Requests;

use App\BusinessModules\Addons\AIEstimates\Enums\FeedbackType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProvideFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('ai_estimates.provide_feedback');
    }

    public function rules(): array
    {
        return [
            'feedback_type' => ['required', 'string', Rule::in(array_column(FeedbackType::cases(), 'value'))],
            'accepted_items' => ['nullable', 'array'],
            'accepted_items.*' => ['integer'],
            'edited_items' => ['nullable', 'array'],
            'edited_items.*.id' => ['required', 'integer'],
            'edited_items.*.changes' => ['required', 'array'],
            'rejected_items' => ['nullable', 'array'],
            'rejected_items.*' => ['integer'],
            'comments' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'feedback_type.required' => 'Тип обратной связи обязателен',
            'feedback_type.in' => 'Некорректный тип обратной связи',
            'comments.max' => 'Комментарий не должен превышать 5000 символов',
        ];
    }

    public function attributes(): array
    {
        return [
            'feedback_type' => 'Тип обратной связи',
            'comments' => 'Комментарий',
        ];
    }
}
