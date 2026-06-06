<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;

final class DesignCompletenessRuleResult
{
    public function __construct(
        public readonly string $rule,
        public readonly DesignCompletenessStatusEnum $status,
        public readonly string $message,
        public readonly ?string $targetType = null,
        public readonly ?int $targetId = null,
        public readonly array $metadata = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'status' => $this->status->value,
            'message' => $this->message,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'metadata' => $this->metadata,
        ];
    }
}
