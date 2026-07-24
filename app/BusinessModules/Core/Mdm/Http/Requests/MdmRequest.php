<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

use App\BusinessModules\Core\Mdm\Services\MdmEntityRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class MdmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function organizationId(): int
    {
        return (int) ($this->attributes->get('current_organization_id') ?? $this->user()?->current_organization_id);
    }

    protected function entityTypeRule(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', Rule::in(array_keys(app(MdmEntityRegistry::class)->all()))];
    }

    protected function paginationRules(): array
    {
        return ['per_page' => ['nullable', 'integer', 'min:1', 'max:100']];
    }
}
