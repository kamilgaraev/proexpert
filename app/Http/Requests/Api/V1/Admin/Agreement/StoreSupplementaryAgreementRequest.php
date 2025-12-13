<?php

namespace App\Http\Requests\Api\V1\Admin\Agreement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\DTOs\SupplementaryAgreementDTO;

class StoreSupplementaryAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $contractId = $this->input('contract_id');

        return [
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('supplementary_agreements', 'number')
                    ->where('contract_id', $contractId)
                    ->whereNull('deleted_at'),
            ],
            'agreement_date' => ['required', 'date_format:Y-m-d'],
            'change_amount' => ['nullable', 'numeric'],
            'supersede_agreement_ids' => [
                'nullable',
                'array',
            ],
            'supersede_agreement_ids.*' => [
                'required',
                'integer',
                'exists:supplementary_agreements,id',
                function ($attribute, $value, $fail) use ($contractId) {
                    // Проверяем, что ДС принадлежит тому же контракту
                    $agreement = \App\Models\SupplementaryAgreement::find($value);
                    if ($agreement && $contractId && $agreement->contract_id != $contractId) {
                        $fail("Дополнительное соглашение #{$value} не принадлежит указанному контракту.");
                    }
                },
            ],
            'subject_changes' => ['required', 'array'],
            'subject_changes.*' => ['string'],
            'subcontract_changes' => ['nullable', 'array'],
            'gp_changes' => ['nullable', 'array'],
            'advance_changes' => ['nullable', 'array'],
            // Используем новую таблицу invoices
            'advance_changes.*.payment_id' => ['required', 'integer', 'exists:invoices,id'],
            'advance_changes.*.new_amount' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'number.unique' => 'Дополнительное соглашение с таким номером уже существует для этого контракта',
            'supersede_agreement_ids.*.exists' => 'Одно из указанных дополнительных соглашений не найдено',
        ];
    }

    /**
     * Дополнительная валидация
     * 
     * BUSINESS LOGIC:
     * Дополнительное соглашение может быть создано в следующих сценариях:
     * 1. С изменением суммы контракта (change_amount != 0)
     * 2. С аннулированием предыдущих ДС (supersede_agreement_ids)
     * 3. Без изменения суммы - для изменения неценовых условий:
     *    - Изменение сроков выполнения работ
     *    - Замена материалов/работ на эквивалентные
     *    - Изменение реквизитов, контактных лиц
     *    - Изменение спецификации без изменения стоимости
     *    - Изменение графика платежей
     *    - Изменение условий гарантии и т.д.
     * 
     * В любом случае должен быть указан subject_changes (предмет изменений) - это обязательное поле.
     */
    public function withValidator($validator)
    {
        // Валидация убрана - subject_changes является обязательным и достаточным
        // для создания ДС без изменения суммы или аннулирования других ДС
    }

    public function toDto(): SupplementaryAgreementDTO
    {
        $supersedeIds = $this->validated('supersede_agreement_ids');
        if ($supersedeIds !== null) {
            $supersedeIds = array_map('intval', $supersedeIds);
        }

        return new SupplementaryAgreementDTO(
            contract_id: $this->validated('contract_id'),
            number: $this->validated('number'),
            agreement_date: $this->validated('agreement_date'),
            change_amount: $this->validated('change_amount') !== null ? (float) $this->validated('change_amount') : null,
            subject_changes: $this->validated('subject_changes'),
            subcontract_changes: $this->validated('subcontract_changes'),
            gp_changes: $this->validated('gp_changes'),
            advance_changes: $this->validated('advance_changes'),
            supersede_agreement_ids: $supersedeIds,
        );
    }
} 