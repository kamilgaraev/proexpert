<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;

final class BudgetStarterCatalogRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'groups' => ['required', 'array', 'min:1', 'max:3'],
            'groups.*' => ['required', 'string', 'distinct', Rule::in(['scenarios', 'responsibility_centers', 'articles'])],
        ];
    }
}
