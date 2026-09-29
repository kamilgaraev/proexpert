<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

class UpdateAssistantMemoryRequest extends StoreAssistantMemoryRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['conversation_id']);
        $rules['version'] = ['sometimes', 'integer', 'min:1'];

        return $rules;
    }
}
