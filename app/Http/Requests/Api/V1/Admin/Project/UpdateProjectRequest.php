<?php

namespace App\Http\Requests\Api\V1\Admin\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Role;
use App\Models\Project; // РРјРїРѕСЂС‚РёСЂСѓРµРј РјРѕРґРµР»СЊ Project
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use App\DTOs\Project\ProjectDTO; // Р”РѕР±Р°РІР»СЏРµРј РёРјРїРѕСЂС‚ DTO

class UpdateProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Р”РѕСЃС‚СѓРї Рє РєРѕРЅС‚СЂРѕР»Р»РµСЂСѓ СѓР¶Рµ РїСЂРѕРІРµСЂРµРЅ middleware СЃС‚РµРєРѕРј Р°РІС‚РѕСЂРёР·Р°С†РёРё Р°РґРјРёРЅРєРё
        // Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅРѕ РјРѕР¶РЅРѕ РїСЂРѕРІРµСЂРёС‚СЊ, С‡С‚Рѕ РїРѕР»СЊР·РѕРІР°С‚РµР»СЊ Р°СѓС‚РµРЅС‚РёС„РёС†РёСЂРѕРІР°РЅ
        return Auth::check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'address' => 'nullable|string|max:1000',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'description' => 'nullable|string|max:2000',
            'customer' => 'sometimes|nullable|string|max:255', // РќРѕРІРѕРµ РїСЂР°РІРёР»Рѕ + sometimes
            'designer' => 'sometimes|nullable|string|max:255', // РќРѕРІРѕРµ РїСЂР°РІРёР»Рѕ + sometimes
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'sometimes|required|string|in:active,completed,paused,cancelled',
            'is_archived' => 'sometimes|boolean',
            'additional_info' => 'sometimes|nullable|array',
            
            // РќРѕРІС‹Рµ РїРѕР»СЏ РґР»СЏ РёРЅС‚РµРіСЂР°С†РёРё СЃ Р±СѓС…РіР°Р»С‚РµСЂСЃРєРёРј СѓС‡РµС‚РѕРј
            'external_code' => 'sometimes|nullable|string|max:100',
            'cost_category_id' => 'sometimes|nullable|exists:cost_categories,id',
            'accounting_data' => 'sometimes|nullable|array',
            'use_in_accounting_reports' => 'sometimes|nullable|boolean',
            'budget_amount' => 'sometimes|nullable|numeric|min:0',
            'site_area_m2' => 'sometimes|nullable|numeric|min:0',
            'contract_number' => 'sometimes|nullable|string|max:100',
        ];
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РїРѕР»СЊР·РѕРІР°С‚РµР»СЊСЃРєРёРµ СЃРѕРѕР±С‰РµРЅРёСЏ РѕР± РѕС€РёР±РєР°С… РґР»СЏ РїСЂР°РІРёР» РїСЂРѕРІРµСЂРєРё.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'РќР°Р·РІР°РЅРёРµ РїСЂРѕРµРєС‚Р° РѕР±СЏР·Р°С‚РµР»СЊРЅРѕ РґР»СЏ Р·Р°РїРѕР»РЅРµРЅРёСЏ.',
            'end_date.after_or_equal' => 'Р”Р°С‚Р° РѕРєРѕРЅС‡Р°РЅРёСЏ РґРѕР»Р¶РЅР° Р±С‹С‚СЊ Р±РѕР»СЊС€Рµ РёР»Рё СЂР°РІРЅР° РґР°С‚Рµ РЅР°С‡Р°Р»Р°.',
            'cost_category_id.exists' => 'Р’С‹Р±СЂР°РЅРЅР°СЏ РєР°С‚РµРіРѕСЂРёСЏ Р·Р°С‚СЂР°С‚ РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = (new ValidationException($validator))->errors();

        throw new HttpResponseException(
            \App\Http\Responses\AdminResponse::fromPayload([
                'success' => false,
                'message' => 'Р”Р°РЅРЅС‹Рµ РЅРµ РїСЂРѕС€Р»Рё РІР°Р»РёРґР°С†РёСЋ.',
                'errors' => $errors,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
        );
    }

    public function toDto(): ProjectDTO
    {
        $validated = $this->validated();
        // РўРµРєСѓС‰РёР№ РїСЂРѕРµРєС‚ РґР»СЏ РїРѕР»СѓС‡РµРЅРёСЏ СЃС‚Р°СЂС‹С… Р·РЅР°С‡РµРЅРёР№, РµСЃР»Рё РѕРЅРё РЅРµ РїРµСЂРµРґР°РЅС‹
        $projectId = $this->route('project');
        $currentProject = $projectId instanceof \App\Models\Project ? $projectId : Project::find($projectId);
        if (!$currentProject) {
            throw new \RuntimeException('Project not found for DTO conversion.');
        }

        return new ProjectDTO(
            name: $validated['name'] ?? $currentProject->name,
            address: $validated['address'] ?? $currentProject->address,
            latitude: array_key_exists('latitude', $validated) ? ($validated['latitude'] !== null ? (float) $validated['latitude'] : null) : $currentProject->latitude,
            longitude: array_key_exists('longitude', $validated) ? ($validated['longitude'] !== null ? (float) $validated['longitude'] : null) : $currentProject->longitude,
            description: $validated['description'] ?? $currentProject->description,
            customer: $validated['customer'] ?? $currentProject->customer,
            designer: $validated['designer'] ?? $currentProject->designer,
            budget_amount: array_key_exists('budget_amount', $validated)
                ? ($validated['budget_amount'] !== null ? (float) $validated['budget_amount'] : null)
                : ($currentProject->budget_amount !== null ? (float) $currentProject->budget_amount : null),
            site_area_m2: array_key_exists('site_area_m2', $validated)
                ? ($validated['site_area_m2'] !== null ? (float) $validated['site_area_m2'] : null)
                : ($currentProject->site_area_m2 !== null ? (float) $currentProject->site_area_m2 : null),
            contract_number: $validated['contract_number'] ?? $currentProject->contract_number,
            start_date: array_key_exists('start_date', $validated)
                ? $validated['start_date']
                : $currentProject->start_date?->toDateString(),
            end_date: array_key_exists('end_date', $validated)
                ? $validated['end_date']
                : $currentProject->end_date?->toDateString(),
            status: $validated['status'] ?? $currentProject->status,
            is_archived: $validated['is_archived'] ?? $currentProject->is_archived,
            additional_info: $validated['additional_info'] ?? $currentProject->additional_info,
            external_code: $validated['external_code'] ?? $currentProject->external_code,
            cost_category_id: array_key_exists('cost_category_id', $validated)
                                ? ($validated['cost_category_id'] !== null ? (int) $validated['cost_category_id'] : null)
                                : $currentProject->cost_category_id,
            accounting_data: $validated['accounting_data'] ?? $currentProject->accounting_data,
            use_in_accounting_reports: $validated['use_in_accounting_reports'] ?? $currentProject->use_in_accounting_reports
        );
    }
}
