<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class ActOnMobilePtoExecutiveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $requiresComment = in_array((string) $this->route('action'), ['reject', 'add_remark'], true);

        return [
            'comment' => [$requiresComment ? 'required' : 'nullable', 'string', 'max:2000'],
            'version_id' => ['nullable', 'integer', 'min:1'],
            'severity' => ['nullable', Rule::in(['minor', 'major', 'critical'])],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('errors.validation_failed'),
            422,
            $validator->errors(),
        ));
    }
}
