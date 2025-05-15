<?php

namespace App\Http\Requests\Api\V1\Admin\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Role;
use App\Models\Project; // Импортируем модель Project
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use App\DTOs\Project\ProjectDTO; // Добавляем импорт DTO

class UpdateProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Доступ к контроллеру уже проверен middleware 'can:access-admin-panel'
        // Дополнительно можно проверить, что пользователь аутентифицирован
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
            'name' => 'sometimes|required|string|max:255',
            'address' => 'nullable|string|max:1000',
            'description' => 'nullable|string|max:2000',
            'customer' => 'sometimes|nullable|string|max:255', // Новое правило + sometimes
            'designer' => 'sometimes|nullable|string|max:255', // Новое правило + sometimes
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'sometimes|required|string|in:active,completed,paused,cancelled',
            'is_archived' => 'sometimes|boolean',
            'additional_info' => 'sometimes|nullable|array',
            
            // Новые поля для интеграции с бухгалтерским учетом
            'external_code' => 'sometimes|nullable|string|max:100',
            'cost_category_id' => 'sometimes|nullable|exists:cost_categories,id',
            'accounting_data' => 'sometimes|nullable|array',
            'use_in_accounting_reports' => 'sometimes|nullable|boolean',
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
        // Текущий проект для получения старых значений, если они не переданы
        $currentProject = $this->route('project'); 

        return new ProjectDTO(
            name: $validated['name'] ?? $currentProject->name,
            address: $validated['address'] ?? $currentProject->address,
            description: $validated['description'] ?? $currentProject->description,
            customer: $validated['customer'] ?? $currentProject->customer,
            designer: $validated['designer'] ?? $currentProject->designer,
            start_date: $validated['start_date'] ?? $currentProject->start_date,
            end_date: $validated['end_date'] ?? $currentProject->end_date,
            status: $validated['status'] ?? $currentProject->status,
            is_archived: $validated['is_archived'] ?? $currentProject->is_archived,
            additional_info: $validated['additional_info'] ?? $currentProject->additional_info,
            external_code: $validated['external_code'] ?? $currentProject->external_code,
            cost_category_id: isset($validated['cost_category_id']) 
                                ? (int)$validated['cost_category_id'] 
                                : $currentProject->cost_category_id,
            accounting_data: $validated['accounting_data'] ?? $currentProject->accounting_data,
            use_in_accounting_reports: $validated['use_in_accounting_reports'] ?? $currentProject->use_in_accounting_reports
        );
    }
} 