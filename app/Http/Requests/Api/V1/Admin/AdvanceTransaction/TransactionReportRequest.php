<?php

namespace App\Http\Requests\Api\V1\Admin\AdvanceTransaction;

use Illuminate\Foundation\Http\FormRequest;

class TransactionReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Проверка прав доступа пользователя (например, может ли создавать отчет по транзакции)
        return true; // Заменить на реальную проверку прав
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => 'required|string|max:255',
            'document_number' => 'required|string|max:100',
            'document_date' => 'required|date',
            'files' => 'nullable|array',
            'files.*' => 'file|max:10240', // Максимальный размер файла 10MB
        ];
    }
} 