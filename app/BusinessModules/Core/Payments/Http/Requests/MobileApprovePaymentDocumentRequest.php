<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class MobileApprovePaymentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:1000'],
            'budget_override_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
