<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class PublicCoreTestRequest extends FormRequest
{
    public const KEYS = ['fixture_id', 'fixture_version', 'input_id', 'request_id', 'public_session_ref'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $selector = ['bail', 'required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._\/-]+\z/D'];

        return [
            'fixture_id' => $selector,
            'fixture_version' => $selector,
            'input_id' => $selector,
            'request_id' => ['bail', 'required', 'string', 'uuid', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD'],
            'public_session_ref' => ['sometimes', 'string', 'regex:/\A[A-Za-z0-9_-]{20,160}\z/D'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), self::KEYS) !== []) {
                $validator->errors()->add('input', trans_message('ai_assistant.request_invalid'));
            }
        }];
    }
}
