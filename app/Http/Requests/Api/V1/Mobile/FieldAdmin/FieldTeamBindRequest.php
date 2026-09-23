<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile\FieldAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class FieldTeamBindRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
