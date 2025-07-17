<?php

namespace App\Http\Requests\Api\V1\Admin\CompletedWork;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\DTOs\CompletedWork\CompletedWorkDTO;
use App\DTOs\CompletedWork\CompletedWorkMaterialDTO;
use Carbon\Carbon;
use App\Models\Contract;
use App\Models\Material;
use Illuminate\Validation\Rule;
use App\Rules\ProjectAccessibleRule;

class UpdateCompletedWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $organizationId = $this->route('completed_work')->organization_id; // Получаем ID организации из существующей записи
        $projectId = $this->input('project_id', $this->route('completed_work')->project_id);

        return [
            'project_id' => ['sometimes', 'required', 'integer', new ProjectAccessibleRule()],
            'contract_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('contracts', 'id')->where('organization_id', $organizationId),
                function ($attribute, $value, $fail) use ($projectId) {
                    if ($value && $projectId) {
                        $contract = Contract::find($value);
                        if ($contract && $contract->project_id != $projectId) {
                            $fail('Указанный договор не относится к выбранному проекту.');
                        }
                    }
                },
            ],
            'work_type_id' => ['sometimes', 'required', 'integer', Rule::exists('work_types', 'id')->where('organization_id', $organizationId)],
            'user_id' => ['sometimes', 'required', 'integer', Rule::exists('users', 'id')],
            'contractor_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('contractors', 'id')->where('organization_id', $organizationId)
            ],
            'quantity' => 'sometimes|required|numeric|min:0.001',
            'price' => 'sometimes|nullable|numeric|min:0',
            'total_amount' => 'sometimes|nullable|numeric|min:0',
            'completion_date' => 'sometimes|required|date_format:Y-m-d',
            'notes' => 'sometimes|nullable|string|max:65535',
            'status' => 'sometimes|required|string|in:draft,confirmed,cancelled',
            'additional_info' => 'sometimes|nullable|array',
            'materials' => 'sometimes|nullable|array',
            'materials.*.material_id' => [
                'required_with:materials',
                'integer',
                Rule::exists('materials', 'id')->where('organization_id', $organizationId)
            ],
            'materials.*.quantity' => 'required_with:materials|numeric|min:0.0001',
            'materials.*.unit_price' => 'nullable|numeric|min:0',
            'materials.*.total_amount' => 'nullable|numeric|min:0',
            'materials.*.notes' => 'nullable|string|max:1000',
        ];
    }

    public function toDto(): CompletedWorkDTO
    {
        $validatedData = $this->validated();
        $completedWork = $this->route('completed_work');

        $materials = null;
        if (array_key_exists('materials', $validatedData)) {
            $materials = isset($validatedData['materials']) 
                ? array_map(
                    fn(array $material) => CompletedWorkMaterialDTO::fromArray($material),
                    $validatedData['materials']
                )
                : [];
        }

        return new CompletedWorkDTO(
            id: $completedWork->id,
            organization_id: $completedWork->organization_id,
            project_id: $validatedData['project_id'] ?? $completedWork->project_id,
            contract_id: array_key_exists('contract_id', $validatedData) ? ($validatedData['contract_id'] ?? null) : $completedWork->contract_id,
            contractor_id: array_key_exists('contractor_id', $validatedData) ? ($validatedData['contractor_id'] ?? null) : $completedWork->contractor_id,
            work_type_id: $validatedData['work_type_id'] ?? $completedWork->work_type_id,
            user_id: $validatedData['user_id'] ?? $completedWork->user_id,
            quantity: isset($validatedData['quantity']) ? (float)$validatedData['quantity'] : (float)$completedWork->quantity,
            price: isset($validatedData['price']) ? (float)$validatedData['price'] : (isset($completedWork->price) ? (float)$completedWork->price : null),
            total_amount: isset($validatedData['total_amount']) ? (float)$validatedData['total_amount'] : (isset($completedWork->total_amount) ? (float)$completedWork->total_amount : null),
            completion_date: isset($validatedData['completion_date']) ? Carbon::parse($validatedData['completion_date']) : $completedWork->completion_date,
            notes: $validatedData['notes'] ?? $completedWork->notes,
            status: $validatedData['status'] ?? $completedWork->status,
            additional_info: $validatedData['additional_info'] ?? $completedWork->additional_info,
            materials: $materials
        );
    }
} 