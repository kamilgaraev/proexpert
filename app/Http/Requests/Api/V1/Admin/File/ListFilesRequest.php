<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\File;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class ListFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'folder' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', 'nullable', 'string'],
            'filename' => ['sometimes', 'nullable', 'string'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date'],
            'sort_by' => ['sometimes', 'in:created_at,size,filename'],
            'sort_dir' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'include_download_urls' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('date_from') || ! $this->filled('date_to')
                || $validator->errors()->has('date_from') || $validator->errors()->has('date_to')) {
                return;
            }

            $from = Carbon::parse((string) $this->input('date_from'))->toDateString();
            $to = Carbon::parse((string) $this->input('date_to'))->toDateString();
            if ($to < $from) {
                $validator->errors()->add('date_to', trans_message('reports.validation.date_to_after_or_equal'));
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach ($this->all() as $key => $value) {
            $clean[$key] = $value === '' ? null : $value;
        }

        $this->merge($clean);
    }
}
