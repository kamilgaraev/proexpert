<?php

namespace App\BusinessModules\Features\AdvancedDashboard\DTOs;

use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use Carbon\Carbon;

class WidgetDataRequest
{
    public function __construct(
        public readonly WidgetType $widgetType,
        public readonly int $organizationId,
        public readonly ?int $userId = null,
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
        public readonly ?int $projectId = null,
        public readonly ?int $contractId = null,
        public readonly ?int $employeeId = null,
        public readonly array $filters = [],
        public readonly array $options = [],
    ) {}

    public static function fromArray(array $data, WidgetType $widgetType): self
    {
        return new self(
            widgetType: $widgetType,
            organizationId: $data['organization_id'],
            userId: $data['user_id'] ?? null,
            from: isset($data['from']) ? Carbon::parse($data['from']) : null,
            to: isset($data['to']) ? Carbon::parse($data['to']) : null,
            projectId: $data['project_id'] ?? null,
            contractId: $data['contract_id'] ?? null,
            employeeId: $data['employee_id'] ?? null,
            filters: $data['filters'] ?? [],
            options: $data['options'] ?? [],
        );
    }

    public function hasDateRange(): bool
    {
        return $this->from !== null && $this->to !== null;
    }

    public function getParam(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $this->filters[$key] ?? $default;
    }

    public function getFilter(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }

    public function toArray(): array
    {
        return [
            'widget_type' => $this->widgetType->value,
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'project_id' => $this->projectId,
            'contract_id' => $this->contractId,
            'employee_id' => $this->employeeId,
            'filters' => $this->filters,
            'options' => $this->options,
        ];
    }
}

