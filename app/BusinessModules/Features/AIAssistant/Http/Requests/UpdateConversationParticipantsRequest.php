<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConversationParticipantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['participants' => ['present', 'array', 'max:100'], 'participants.*.user_id' => ['required', 'integer', 'min:1', 'distinct'], 'participants.*.role' => ['required', 'in:viewer,editor']];
    }
}
