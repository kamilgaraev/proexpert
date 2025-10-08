<?php

namespace App\BusinessModules\Features\AdvancedDashboard\DTOs;

use Carbon\Carbon;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;

class WidgetDataResponse
{
    public function __construct(
        public readonly WidgetType $widgetType,
        public readonly array $data,
        public readonly ?Carbon $generatedAt = null,
        public readonly ?array $metadata = null,
        public readonly bool $cached = false,
        public readonly bool $success = true,
        public readonly ?string $message = null,
    ) {}

    public static function success(
        WidgetType $widgetType,
        array $data,
        ?array $metadata = null,
        bool $cached = false
    ): self {
        return new self(
            widgetType: $widgetType,
            data: $data,
            generatedAt: Carbon::now(),
            metadata: $metadata,
            cached: $cached,
            success: true,
            message: null,
        );
    }

    public static function error(
        WidgetType $widgetType,
        string $message
    ): self {
        return new self(
            widgetType: $widgetType,
            data: [],
            generatedAt: Carbon::now(),
            metadata: null,
            cached: false,
            success: false,
            message: $message,
        );
    }

    public function toArray(): array
    {
        return [
            'widget_type' => $this->widgetType->value,
            'data' => $this->data,
            'generated_at' => $this->generatedAt?->toIso8601String(),
            'metadata' => $this->metadata,
            'cached' => $this->cached,
            'success' => $this->success,
            'message' => $this->message,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

