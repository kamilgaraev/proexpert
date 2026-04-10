<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\CompletedWork;

use App\DTOs\CompletedWork\CompletedWorkDTO;
use App\DTOs\CompletedWork\CompletedWorkMaterialDTO;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Rules\ProjectAccessibleRule;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateCompletedWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        /** @var CompletedWork $completedWork */
        $completedWork = $this->route('completedWork') ?? $this->route('completed_work');
        $organizationId = $completedWork->organization_id;
        $projectId = $this->input('project_id', $completedWork->project_id);

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
            'work_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('work_types', 'id')->where('organization_id', $organizationId)],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'contractor_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('contractors', 'id')->where('organization_id', $organizationId),
            ],
            'schedule_task_id' => ['sometimes', 'nullable', 'integer', Rule::exists('schedule_tasks', 'id')],
            'estimate_item_id' => ['sometimes', 'nullable', 'integer', Rule::exists('estimate_items', 'id')],
            'quantity' => 'sometimes|required|numeric|min:0.001',
            'completed_quantity' => 'sometimes|nullable|numeric|min:0',
            'price' => 'sometimes|nullable|numeric|min:0',
            'total_amount' => 'sometimes|nullable|numeric|min:0',
            'completion_date' => 'sometimes|required|date_format:Y-m-d',
            'notes' => 'sometimes|nullable|string|max:65535',
            'status' => 'sometimes|required|string|in:draft,pending,in_review,confirmed,cancelled,rejected',
            'work_origin_type' => 'sometimes|nullable|string|in:manual,schedule,journal',
            'planning_status' => 'sometimes|nullable|string|in:planned,requires_schedule',
            'additional_info' => 'sometimes|nullable|array',
            'materials' => 'sometimes|nullable|array',
            'materials.*.material_id' => [
                'required_with:materials',
                'integer',
                Rule::exists('materials', 'id')->where('organization_id', $organizationId),
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
        /** @var CompletedWork $completedWork */
        $completedWork = $this->route('completedWork') ?? $this->route('completed_work');

        $materials = null;
        if (array_key_exists('materials', $validatedData)) {
            $materials = isset($validatedData['materials'])
                ? array_map(
                    fn (array $material) => CompletedWorkMaterialDTO::fromArray($material),
                    $validatedData['materials']
                )
                : [];
        }

        return new CompletedWorkDTO(
            id: $completedWork->id,
            organization_id: $completedWork->organization_id,
            project_id: $validatedData['project_id'] ?? $completedWork->project_id,
            schedule_task_id: array_key_exists('schedule_task_id', $validatedData) ? ($validatedData['schedule_task_id'] ?? null) : $completedWork->schedule_task_id,
            estimate_item_id: array_key_exists('estimate_item_id', $validatedData) ? ($validatedData['estimate_item_id'] ?? null) : $completedWork->estimate_item_id,
            journal_entry_id: $completedWork->journal_entry_id,
            work_origin_type: $validatedData['work_origin_type'] ?? ($completedWork->work_origin_type ?? CompletedWork::ORIGIN_MANUAL),
            planning_status: $validatedData['planning_status'] ?? ($completedWork->planning_status ?? CompletedWork::PLANNING_PLANNED),
            contract_id: array_key_exists('contract_id', $validatedData) ? ($validatedData['contract_id'] ?? null) : $completedWork->contract_id,
            contractor_id: array_key_exists('contractor_id', $validatedData) ? ($validatedData['contractor_id'] ?? null) : $completedWork->contractor_id,
            work_type_id: array_key_exists('work_type_id', $validatedData) ? ($validatedData['work_type_id'] ?? null) : $completedWork->work_type_id,
            user_id: array_key_exists('user_id', $validatedData) ? ($validatedData['user_id'] ?? null) : $completedWork->user_id,
            quantity: isset($validatedData['quantity']) ? (float) $validatedData['quantity'] : (float) $completedWork->quantity,
            completed_quantity: array_key_exists('completed_quantity', $validatedData)
                ? (isset($validatedData['completed_quantity']) ? (float) $validatedData['completed_quantity'] : null)
                : (isset($completedWork->completed_quantity) ? (float) $completedWork->completed_quantity : null),
            price: isset($validatedData['price']) ? (float) $validatedData['price'] : (isset($completedWork->price) ? (float) $completedWork->price : null),
            total_amount: isset($validatedData['total_amount']) ? (float) $validatedData['total_amount'] : (isset($completedWork->total_amount) ? (float) $completedWork->total_amount : null),
            completion_date: isset($validatedData['completion_date']) ? Carbon::parse($validatedData['completion_date']) : $completedWork->completion_date,
            notes: $validatedData['notes'] ?? $completedWork->notes,
            status: $validatedData['status'] ?? $completedWork->status,
            additional_info: $validatedData['additional_info'] ?? $completedWork->additional_info,
            materials: $materials
        );
    }
}
