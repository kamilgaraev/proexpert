<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rule;

/**
 * Валидация обновления группы заявок
 */
class UpdateSiteRequestGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            
            // Массив материалов для синхронизации
            'materials' => ['sometimes', 'array'],
            'materials.*.id' => ['nullable', 'integer', $this->siteRequestExistsInGroupRule()],
            'materials.*.material_id' => ['nullable', 'integer', $this->materialExistsRule()],
            'materials.*.estimate_item_id' => ['nullable', 'integer', 'exists:estimate_items,id'],
            'materials.*.name' => ['required_without:materials.*.material_id', 'nullable', 'string', 'max:255'],
            'materials.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'materials.*.unit' => ['required', 'string', 'max:50'],
            'materials.*.note' => ['nullable', 'string'],
            
            // Общие поля доставки
            'delivery_address' => ['nullable', 'string'],
            'delivery_time_from' => ['nullable', 'date_format:H:i'],
            'delivery_time_to' => ['nullable', 'date_format:H:i', 'after:delivery_time_from'],
            'contact_person_name' => ['nullable', 'string', 'max:255'],
            'contact_person_phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    private function siteRequestExistsInGroupRule(): Exists
    {
        $organizationId = (int) $this->attributes->get('current_organization_id');
        $groupId = (int) $this->route('id');

        return Rule::exists('site_requests', 'id')->where(function ($query) use ($organizationId, $groupId): void {
            $query->where('organization_id', $organizationId)
                ->where('site_request_group_id', $groupId)
                ->whereNull('deleted_at');
        });
    }

    private function materialExistsRule(): Exists
    {
        $organizationId = (int) $this->attributes->get('current_organization_id');

        return Rule::exists('materials', 'id')->where(function ($query) use ($organizationId): void {
            $query->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->whereNull('deleted_at');
        });
    }

    public function attributes(): array
    {
        return [
            'title' => 'название',
            'materials.*.name' => 'название материала',
            'materials.*.quantity' => 'количество',
            'materials.*.unit' => 'единица измерения',
        ];
    }
}
