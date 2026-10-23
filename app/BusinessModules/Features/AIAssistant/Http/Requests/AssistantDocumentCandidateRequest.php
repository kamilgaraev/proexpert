<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use App\Domain\Authorization\Http\Middleware\InterfaceRequestProvenance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AssistantDocumentCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'document_id' => ['bail', 'sometimes', 'required', 'integer', 'min:1', 'max:9223372036854775807'],
            'after_id' => ['bail', 'sometimes', 'required', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }

    public function validationData(): array
    {
        return $this->query->all();
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = ['document_id', 'after_id'];
            if (InterfaceRequestProvenance::isTrustedServerDerived($this) && $this->input('current_interface') === 'admin') {
                $allowed[] = 'current_interface';
            }
            $body = $this->getContent();
            $emptyBody = is_string($body) && ($body === ''
                || (strlen($body) <= 64 && $this->isJson() && json_decode($body, true, 2) === []));

            if (! $emptyBody
                || $this->files->all() !== []
                || array_diff(array_keys($this->request->all()), ['current_interface']) !== []
                || array_diff(array_keys($this->all()), $allowed) !== []) {
                $validator->errors()->add('input', trans_message('ai_assistant.request_invalid'));
            }
        }];
    }
}
