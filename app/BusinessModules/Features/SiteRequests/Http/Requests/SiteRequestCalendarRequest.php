<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Http\Requests;

use App\BusinessModules\Features\SiteRequests\Enums\CalendarEventTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SiteRequestCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'project_id' => [
                'nullable', 'integer',
                Rule::exists('projects', 'id')->where('organization_id', (int) $this->attributes->get('current_organization_id')),
            ],
            'event_type' => ['nullable', Rule::enum(CalendarEventTypeEnum::class)],
            'type' => ['nullable', Rule::enum(SiteRequestTypeEnum::class)],
            'status' => is_array($this->input('status'))
                ? ['array', 'max:10']
                : ['nullable', Rule::enum(SiteRequestStatusEnum::class)],
            'status.*' => ['required', Rule::enum(SiteRequestStatusEnum::class)],
        ];
    }
}
