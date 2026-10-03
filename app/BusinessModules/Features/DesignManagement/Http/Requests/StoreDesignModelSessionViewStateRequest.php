<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionPayloadValidator as Payload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreDesignModelSessionViewStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'sequence' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'view_state' => ['required', 'array:schema_version,model_set_revision_id,camera,models,selection,sections'],
            'view_state.schema_version' => ['required', 'integer', 'in:1'],
            'view_state.model_set_revision_id' => ['required', 'integer', 'min:1'],
            'view_state.camera' => ['required', 'array'],
            'view_state.models' => ['present', 'array', 'max:100'],
            'view_state.models.*' => ['required', 'array:version_id,transform,visible,hidden_element_ids,isolated_element_ids'],
            'view_state.models.*.version_id' => ['required', 'integer', 'min:1', 'distinct'],
            'view_state.models.*.transform' => ['required', 'array:shift,rotation'],
            'view_state.models.*.transform.shift' => ['required', 'array', 'size:3'],
            'view_state.models.*.transform.rotation' => ['required', 'numeric'],
            'view_state.models.*.visible' => ['required', 'boolean'],
            'view_state.models.*.hidden_element_ids' => ['present', 'array', 'max:10000'],
            'view_state.models.*.isolated_element_ids' => ['present', 'array', 'max:10000'],
            'view_state.selection' => ['present', 'array', 'max:10000'],
            'view_state.selection.*' => ['required', 'array:version_id,element_id'],
            'view_state.selection.*.version_id' => ['required', 'integer', 'min:1'],
            'view_state.selection.*.element_id' => ['required'],
            'view_state.sections' => ['present', 'array', 'max:16'],
            'view_state.sections.*' => ['required', 'array:id,normal,constant,enabled'],
            'view_state.sections.*.id' => ['required', 'string', 'max:100', 'distinct'],
            'view_state.sections.*.normal' => ['required', 'array', 'size:3'],
            'view_state.sections.*.constant' => ['required', 'numeric'],
            'view_state.sections.*.enabled' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $state = $this->input('view_state');
            if (! is_array($state) || $validator->errors()->isNotEmpty()) {
                return;
            }
            $valid = strlen((string) json_encode($state)) <= 524288 && Payload::camera($state['camera']);
            foreach ($state['models'] as $model) {
                $valid = $valid && Payload::vector($model['transform']['shift']) && Payload::coordinate($model['transform']['rotation']);
                foreach (['hidden_element_ids', 'isolated_element_ids'] as $key) {
                    $valid = $valid && array_is_list($model[$key])
                        && count(array_filter($model[$key], Payload::elementId(...))) === count($model[$key]);
                }
            }
            foreach ($state['sections'] as $section) {
                $valid = $valid && Payload::vector($section['normal']) && Payload::coordinate($section['constant'])
                    && array_sum(array_map(static fn ($v): float => (float) $v ** 2, $section['normal'])) > 0;
            }
            foreach ($state['selection'] as $item) {
                $valid = $valid && Payload::elementId($item['element_id']);
            }
            $valid = $valid && array_is_list($state['models']) && array_is_list($state['selection']) && array_is_list($state['sections']);
            if (! $valid) {
                $validator->errors()->add('view_state', trans_message('design_bim.errors.event_payload_invalid'));
            }
        });
    }
}
