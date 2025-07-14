<?php

namespace App\Http\Requests\Api\V1\Admin\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Role;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use App\DTOs\Project\ProjectDTO;

class StoreProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Auth::check(); 
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:1000',
            'description' => 'nullable|string|max:2000',
            'customer' => 'nullable|string|max:255',
            'designer' => 'nullable|string|max:255',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'required|string|in:active,completed,paused,cancelled',
            'is_archived' => 'sometimes|boolean',
            'additional_info' => 'nullable|array',
            
            // Новые поля для интеграции с бухгалтерским учетом
            'external_code' => 'nullable|string|max:100',
            'cost_category_id' => 'nullable|exists:cost_categories,id',
            'accounting_data' => 'nullable|array',
            'use_in_accounting_reports' => 'nullable|boolean',
            'budget_amount' => 'nullable|numeric|min:0',
            'site_area_m2' => 'nullable|numeric|min:0',
            'contract_number' => 'nullable|string|max:100',
        ];
    }

    /**
     * Получить пользовательские сообщения об ошибках для правил проверки.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Название проекта обязательно для заполнения.',
            'end_date.after_or_equal' => 'Дата окончания должна быть больше или равна дате начала.',
            'cost_category_id.exists' => 'Выбранная категория затрат не существует.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = (new ValidationException($validator))->errors();

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Данные не прошли валидацию.',
                'errors' => $errors,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
        );
    }

    public function toDto(): ProjectDTO
    {
        $validated = $this->validated();
        return new ProjectDTO(
            name: $validated['name'],
            address: $validated['address'] ?? null,
            description: $validated['description'] ?? null,
            customer: $validated['customer'] ?? null,
            designer: $validated['designer'] ?? null,
            start_date: $validated['start_date'] ?? null,
            end_date: $validated['end_date'] ?? null,
            status: $validated['status'],
            is_archived: $validated['is_archived'] ?? false,
            additional_info: $validated['additional_info'] ?? null,
            external_code: $validated['external_code'] ?? null,
            cost_category_id: isset($validated['cost_category_id']) ? (int)$validated['cost_category_id'] : null,
            accounting_data: $validated['accounting_data'] ?? null,
            use_in_accounting_reports: $validated['use_in_accounting_reports'] ?? false,
            budget_amount: $validated['budget_amount'] ?? null,
            site_area_m2: $validated['site_area_m2'] ?? null,
            contract_number: $validated['contract_number'] ?? null
        );
    }
} 