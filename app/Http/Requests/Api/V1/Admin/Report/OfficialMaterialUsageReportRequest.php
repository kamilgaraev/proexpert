<?php

namespace App\Http\Requests\Api\V1\Admin\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Project;
use App\Models\Material;
use App\Models\User;
use App\Models\Supplier;
use App\Models\WorkType;
use App\Models\Role;

class OfficialMaterialUsageReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id');
        if (!$organizationId) {
            return ['organization_error' => 'required'];
        }

        return [
            'project_id' => [
                'required',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId)
            ],
            'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'report_number' => 'nullable|integer|min:1',
            
            'material_id' => [
                'nullable',
                'integer',
                Rule::exists('materials', 'id')->where('organization_id', $organizationId)
            ],
            'material_name' => 'nullable|string|max:255',
            
            'operation_type' => 'nullable|in:receipt,write_off',
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where('organization_id', $organizationId)
            ],
            'document_number' => 'nullable|string|max:255',
            
            'work_type_id' => [
                'nullable',
                'integer',
                Rule::exists('work_types', 'id')->where('organization_id', $organizationId)
            ],
            'work_description' => 'nullable|string|max:500',
            
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(function ($query) use ($organizationId) {
                    return $query->whereHas('roles', function ($roleQuery) use ($organizationId) {
                        $roleQuery->where('role_user.organization_id', $organizationId);
                    });
                })
            ],
            'foreman_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(function ($query) use ($organizationId) {
                    return $query->whereHas('roles', function ($roleQuery) use ($organizationId) {
                        $roleQuery->where('slug', Role::ROLE_FOREMAN)
                                  ->where('role_user.organization_id', $organizationId);
                    });
                })
            ],
            
            'invoice_date_from' => 'nullable|date_format:Y-m-d',
            'invoice_date_to' => 'nullable|date_format:Y-m-d|after_or_equal:invoice_date_from',
            
            'min_quantity' => 'nullable|numeric|min:0',
            'max_quantity' => 'nullable|numeric|min:0|gte:min_quantity',
            
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0|gte:min_price',
            
            'has_photo' => 'nullable|boolean',
            
            'format' => 'nullable|in:json,xlsx,csv',
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.required' => 'Поле "Проект" обязательно для заполнения',
            'project_id.exists' => 'Выбранный проект не найден в вашей организации',
            'date_from.required' => 'Поле "Дата с" обязательно для заполнения',
            'date_to.required' => 'Поле "Дата по" обязательно для заполнения',
            'date_to.after_or_equal' => 'Дата окончания должна быть больше или равна дате начала',
            'material_id.exists' => 'Выбранный материал не найден в вашей организации',
            'supplier_id.exists' => 'Выбранный поставщик не найден в вашей организации',
            'work_type_id.exists' => 'Выбранный вид работы не найден в вашей организации',
            'user_id.exists' => 'Выбранный пользователь не найден в вашей организации',
            'foreman_id.exists' => 'Выбранный прораб не найден в вашей организации',
            'max_quantity.gte' => 'Максимальное количество должно быть больше или равно минимальному',
            'max_price.gte' => 'Максимальная цена должна быть больше или равна минимальной',
            'operation_type.in' => 'Тип операции должен быть "receipt" или "write_off"',
            'format.in' => 'Формат должен быть json, xlsx или csv',
        ];
    }
} 