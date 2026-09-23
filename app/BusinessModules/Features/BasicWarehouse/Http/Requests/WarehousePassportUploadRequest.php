<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WarehousePassportUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['passport' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480']];
    }
}
