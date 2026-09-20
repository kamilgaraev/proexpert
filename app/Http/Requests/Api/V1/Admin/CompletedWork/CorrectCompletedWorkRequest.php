<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\CompletedWork;

use App\Services\CompletedWork\CompletedWorkCorrectionRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

final class CorrectCompletedWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return CompletedWorkCorrectionRules::all();
    }
}
