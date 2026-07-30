<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionWindow;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;

final class ListReportSubscriptionsRequest extends ReportFormRequest
{
    public function rules(): array
    {
        return [
            'cursor' => ['present', 'nullable', 'string', 'max:2048'],
            'limit' => ['required', 'integer', 'between:1,100'],
            'status' => ['present', 'nullable', 'in:active,paused,disabled'],
            ...$this->forbiddenClientFieldsRules(),
        ];
    }

    protected function acceptedQueryFields(): array
    {
        return ['cursor', 'limit', 'status'];
    }

    public function toWindow(): ReportSubscriptionWindow
    {
        $status = $this->validated('status');

        return new ReportSubscriptionWindow(
            $this->validated('cursor'),
            (int) $this->validated('limit'),
            $status === null ? null : ReportSubscriptionStatus::from((string) $status),
        );
    }
}
