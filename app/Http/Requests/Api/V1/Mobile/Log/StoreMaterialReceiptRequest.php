<?php

namespace App\Http\Requests\Api\V1\Mobile\Log;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Project;
use App\Models\Material;
use App\Models\Supplier;
use Illuminate\Validation\Rule;
use App\Rules\ProjectAccessibleRule;

class StoreMaterialReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $project = Project::find($this->input('project_id'));
        $organizationId = $project?->organization_id;

        return [
            'project_id' => ['required','integer', new ProjectAccessibleRule()],
            'material_id' => [
                'required',
                'integer',
                Rule::exists(Material::class, 'id')->where(function ($query) use ($organizationId) {
                    if ($organizationId) {
                        $query->where('organization_id', $organizationId);
                    }
                }),
            ],
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists(Supplier::class, 'id')->where(function ($query) use ($organizationId) {
                    if ($organizationId) {
                        $query->where('organization_id', $organizationId);
                    }
                }),
            ],
            'quantity' => 'required|numeric|min:0.001',
            'usage_date' => 'required|date_format:Y-m-d', // Дата приемки
            'invoice_number' => 'nullable|string|max:100',
            'invoice_date' => 'nullable|date_format:Y-m-d',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png|max:5120', // 5MB Max
            'notes' => 'nullable|string|max:1000',
        ];
    }

    protected function prepareForValidation()
    {
        if ($this->has('usage_date') && !is_null($this->usage_date)) {
            try {
                $this->merge([
                    'usage_date' => \Carbon\Carbon::parse($this->usage_date)->format('Y-m-d'),
                ]);
            } catch (\Exception $e) {
                // Оставить как есть, валидатор поймает неверный формат
            }
        }
        if ($this->has('invoice_date') && !is_null($this->invoice_date)) {
            try {
                $this->merge([
                    'invoice_date' => \Carbon\Carbon::parse($this->invoice_date)->format('Y-m-d'),
                ]);
            } catch (\Exception $e) {
                 // Оставить как есть
            }
        }
    }
} 