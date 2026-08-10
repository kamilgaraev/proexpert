<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt;

final readonly class VisionPhysicalAttemptSnapshot
{
    /** @param array<string, mixed>|null $responsePayload @param array<string, mixed>|null $priceSnapshot */
    public function __construct(
        public bool $reservedNow,
        public string $state,
        public ?array $responsePayload = null,
        public ?string $status = null,
        public ?int $httpCode = null,
        public ?int $durationMs = null,
        public ?string $reportedModel = null,
        public ?array $priceSnapshot = null,
        public bool $usageRecorded = false,
    ) {}
}
