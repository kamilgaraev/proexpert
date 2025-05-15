<?php

namespace App\Http\Requests\Api\V1\Admin\CompletedWork;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\DTOs\CompletedWork\CompletedWorkDTO;
use Carbon\Carbon;
use App\Models\Project; // Для проверки принадлежности контракта проекту
use App\Models\Contract; // Для проверки принадлежности контракта организации
use Illuminate\Validation\Rule;

class StoreCompletedWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check(); // Предполагаем, что доступ контролируется middleware группы
    }

    public function rules(): array
    {
        $organizationId = $this->route('organization')?->id ?? Auth::user()->current_organization_id;

        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('organization_id', $organizationId)],
            'contract_id' => [
                'nullable',
                'integer',
                Rule::exists('contracts', 'id')->where('organization_id', $organizationId),
                // Дополнительная проверка: контракт должен принадлежать тому же проекту, что и работа
                function ($attribute, $value, $fail) {
                    if ($value && $this->input('project_id')) {
                        $contract = Contract::find($value);
                        if ($contract && $contract->project_id != $this->input('project_id')) {
                            $fail('Указанный договор не относится к выбранному проекту.');
                        }
                    }
                },
            ],
            'work_type_id' => ['required', 'integer', Rule::exists('work_types', 'id')->where('organization_id', $organizationId)],
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')], // Можно добавить проверку на принадлежность юзера организации
            'quantity' => 'required|numeric|min:0.001',
            'price' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'completion_date' => 'required|date_format:Y-m-d',
            'notes' => 'nullable|string|max:65535',
            'status' => 'required|string|in:draft,confirmed,cancelled', // Пример статусов
            'additional_info' => 'nullable|array',
        ];
    }

    public function toDto(): CompletedWorkDTO
    {
        $validatedData = $this->validated();
        return new CompletedWorkDTO(
            id: null, // Для StoreRequest ID всегда null
            organization_id: $this->route('organization')?->id ?? Auth::user()->current_organization_id,
            project_id: $validatedData['project_id'],
            contract_id: $validatedData['contract_id'] ?? null,
            work_type_id: $validatedData['work_type_id'],
            user_id: $validatedData['user_id'],
            quantity: (float)$validatedData['quantity'],
            price: isset($validatedData['price']) ? (float)$validatedData['price'] : null,
            total_amount: isset($validatedData['total_amount']) ? (float)$validatedData['total_amount'] : null,
            completion_date: Carbon::parse($validatedData['completion_date']),
            notes: $validatedData['notes'] ?? null,
            status: $validatedData['status'],
            additional_info: $validatedData['additional_info'] ?? null
        );
    }
} 