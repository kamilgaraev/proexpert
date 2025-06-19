<?php

namespace App\Http\Requests\Api\V1\Admin\CompletedWork;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\DTOs\CompletedWork\CompletedWorkMaterialDTO;
use Illuminate\Validation\Rule;

class SyncCompletedWorkMaterialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $completedWork = $this->route('completed_work');
        $organizationId = $completedWork->organization_id;

        return [
            'materials' => 'required|array',
            'materials.*.material_id' => [
                'required',
                'integer',
                Rule::exists('materials', 'id')->where('organization_id', $organizationId)
            ],
            'materials.*.quantity' => 'required|numeric|min:0.0001',
            'materials.*.unit_price' => 'nullable|numeric|min:0',
            'materials.*.total_amount' => 'nullable|numeric|min:0',
            'materials.*.notes' => 'nullable|string|max:1000',
        ];
    }

    public function getMaterialsArray(): array
    {
        return array_map(
            fn(array $material) => CompletedWorkMaterialDTO::fromArray($material),
            $this->validated()['materials']
        );
    }
} 