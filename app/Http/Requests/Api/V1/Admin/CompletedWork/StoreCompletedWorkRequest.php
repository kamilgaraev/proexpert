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

class StoreCompletedWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $organizationId = $this->route('organization')?->id ?? Auth::user()->current_organization_id;

        return [
            'project_id' => ['required', 'integer', new ProjectAccessibleRule()],
            'contract_id' => [
                'nullable',
                'integer',
                Rule::exists('contracts', 'id')->where('organization_id', $organizationId),
                function ($attribute, $value, $fail) {
                    if ($value && $this->input('project_id')) {
                        $contract = Contract::find($value);
                        if ($contract && $contract->project_id != $this->input('project_id')) {
                            $fail('Указанный договор не относится к выбранному проекту.');
                        }
                    }
                },
            ],
            'contractor_id' => [
                'nullable',
                'integer',
                Rule::exists('contractors', 'id')->where('organization_id', $organizationId),
            ],
            'work_type_id' => ['nullable', 'integer', Rule::exists('work_types', 'id')->where('organization_id', $organizationId)],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'schedule_task_id' => ['nullable', 'integer', Rule::exists('schedule_tasks', 'id')],
            'estimate_item_id' => ['nullable', 'integer', Rule::exists('estimate_items', 'id')],
            'quantity' => 'required|numeric|min:0.001',
            'completed_quantity' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'completion_date' => 'required|date_format:Y-m-d',
            'notes' => 'nullable|string|max:65535',
            'status' => 'required|string|in:draft,pending,in_review,confirmed,cancelled,rejected',
            'work_origin_type' => 'nullable|string|in:manual,schedule,journal',
            'planning_status' => 'nullable|string|in:planned,requires_schedule',
            'additional_info' => 'nullable|array',
            'materials' => 'nullable|array',
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

        $materials = null;
        if (isset($validatedData['materials'])) {
            $materials = array_map(
                fn (array $material) => CompletedWorkMaterialDTO::fromArray($material),
                $validatedData['materials']
            );
        }

        return new CompletedWorkDTO(
            id: null,
            organization_id: $this->route('organization')?->id ?? Auth::user()->current_organization_id,
            project_id: $validatedData['project_id'],
            schedule_task_id: $validatedData['schedule_task_id'] ?? null,
            estimate_item_id: $validatedData['estimate_item_id'] ?? null,
            journal_entry_id: null,
            work_origin_type: $validatedData['work_origin_type'] ?? CompletedWork::ORIGIN_MANUAL,
            planning_status: $validatedData['planning_status'] ?? (($validatedData['schedule_task_id'] ?? null)
                ? CompletedWork::PLANNING_PLANNED
                : CompletedWork::PLANNING_REQUIRES_SCHEDULE),
            contract_id: $validatedData['contract_id'] ?? null,
            contractor_id: $validatedData['contractor_id'] ?? null,
            work_type_id: $validatedData['work_type_id'] ?? null,
            user_id: $validatedData['user_id'] ?? null,
            quantity: (float) $validatedData['quantity'],
            completed_quantity: isset($validatedData['completed_quantity']) ? (float) $validatedData['completed_quantity'] : null,
            price: isset($validatedData['price']) ? (float) $validatedData['price'] : null,
            total_amount: isset($validatedData['total_amount']) ? (float) $validatedData['total_amount'] : null,
            completion_date: Carbon::parse($validatedData['completion_date']),
            notes: $validatedData['notes'] ?? null,
            status: $validatedData['status'],
            additional_info: $validatedData['additional_info'] ?? null,
            materials: $materials
        );
    }
}
