<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDesignDrawingStrokeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'annotations' => ['required', 'array:schema_version,page_number,width,height,strokes'],
            'annotations.schema_version' => ['required', 'integer', 'in:1'],
            'annotations.page_number' => ['required', 'integer', 'min:1', 'max:10000'],
            'annotations.width' => ['required', 'numeric', 'gt:0', 'max:16384'],
            'annotations.height' => ['required', 'numeric', 'gt:0', 'max:16384'],
            'annotations.strokes' => ['required', 'array', 'size:1'],
        ];
    }
}
