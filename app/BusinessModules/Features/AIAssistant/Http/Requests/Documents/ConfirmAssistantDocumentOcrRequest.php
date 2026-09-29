<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmAssistantDocumentOcrRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['quote_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid']]; }
}
