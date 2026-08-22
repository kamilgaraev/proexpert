<?php

declare(strict_types=1);

namespace App\Http\Requests\ConstructionJournal;

use App\Http\Responses\AdminResponse;
use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ConstructionJournalFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        $message = trans_message('construction_journal.errors.validation_failed');
        $response = $this->is('api/v1/mobile/*')
            ? MobileResponse::error($message, 422, $validator->errors()->toArray())
            : AdminResponse::error($message, 422, $validator->errors()->toArray());

        throw new HttpResponseException($response);
    }
}
