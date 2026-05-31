<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

use function trans_message;

class UploadEstimateImportRequest extends FormRequest
{
    private const ALLOWED_EXTENSIONS = ['xlsx', 'xls', 'xlsm', 'xml', 'csv', 'txt', 'pdf'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!$value instanceof UploadedFile) {
                        $fail(trans_message('estimate.import_file_invalid'));
                        return;
                    }

                    $extension = strtolower($value->getClientOriginalExtension());

                    if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                        $fail(trans_message('estimate.import_file_mimes'));
                        return;
                    }

                    if ($extension === 'pdf' && !$this->isPdfFile($value)) {
                        $fail(trans_message('estimate.import_file_invalid'));
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => trans_message('estimate.import_file_required'),
            'file.max' => trans_message('estimate.import_file_max'),
        ];
    }

    private function isPdfFile(UploadedFile $file): bool
    {
        $path = $file->getRealPath();
        if ($path === false || !is_readable($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $signature = fread($handle, 5);
        fclose($handle);

        return $signature === '%PDF-';
    }
}
