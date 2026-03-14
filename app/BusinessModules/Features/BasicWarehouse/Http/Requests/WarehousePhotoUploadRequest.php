<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehousePhotoUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photos' => 'required|array|min:1|max:4',
            'photos.*' => 'required|file|image|mimes:jpg,jpeg,png,webp,heic,heif|max:10240',
        ];
    }
}
