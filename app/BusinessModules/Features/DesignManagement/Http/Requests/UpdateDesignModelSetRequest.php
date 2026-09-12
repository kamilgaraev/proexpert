<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

final class UpdateDesignModelSetRequest extends StoreDesignModelSetRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), ['expected_revision' => ['required', 'integer', 'min:1'], 'project_id' => ['prohibited'], 'title' => ['sometimes', 'string', 'max:255']]);
    }
}
