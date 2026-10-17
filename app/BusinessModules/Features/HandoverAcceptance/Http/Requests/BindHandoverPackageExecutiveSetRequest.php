<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Requests;

use App\Http\Responses\AdminResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class BindHandoverPackageExecutiveSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'executive_document_set_id' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(AdminResponse::error(
            trans_message('handover_acceptance.errors.validation_failed'),
            422,
            $validator->errors()->toArray(),
        ));
    }
}
