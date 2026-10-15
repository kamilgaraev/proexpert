<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\BusinessModules\Core\Payments\Http\Requests\Concerns\AuthorizesPaymentDocuments;

final class SchedulePaymentDocumentRequest extends FormRequest
{
    use AuthorizesPaymentDocuments;

    public function authorize(): bool
    {
        return $this->authorizePaymentAccess('payments.schedule.create');
    }

    public function rules(): array
    {
        return [
            'scheduled_at' => ['nullable', 'date'],
            'budget_override_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
