<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Http;

use Illuminate\Foundation\Http\FormRequest;

final class EmployeeOverviewForm extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['month' => ['sometimes', 'date_format:Y-m'], 'work_date' => ['sometimes', 'date_format:Y-m-d']];
    }
}
