<?php

declare(strict_types=1);

namespace App\Services\Privacy\PublicCore;

use Closure;

final readonly class PublicCoreDispatchAuthority
{
    public function __construct(private PublicCoreReceiptStore $receipts, private PublicCoreRuntimeReadiness $readiness)
    {
    }

    public function authority(?string $contextRef): ?array
    {
        return $this->receipts->authority($contextRef);
    }

    public function projectForDispatch(array $corePreparedPayload, array $committedPrivateBinding, ?object $qualifiedGatewayProfile = null): array
    {
        return $this->unavailable();
    }

    public function dispatch(?string $contextRef, ?Closure $writer = null): array
    {
        return $this->unavailable();
    }

    private function unavailable(): array
    {
        $current = $this->readiness->resolve();
        return ['schemaVersion' => 'public-core-authority/1', 'status' => 'unavailable',
            'reasonCode' => $current['reason_code'], 'transportAllowed' => false];
    }
}
