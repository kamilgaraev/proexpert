<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionOwnershipGuard;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessingException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentUnitExecutionOwnershipGuardTest extends TestCase
{
    #[Test]
    #[DataProvider('lostOwnershipCases')]
    public function lost_or_expired_claim_is_rejected_before_page_reservation(array $changes): void
    {
        $values = [...$this->valid(), ...$changes];

        $this->expectException(DocumentUnitProcessingException::class);
        $this->expectExceptionMessage('unit_claim_lost');
        (new DocumentUnitExecutionOwnershipGuard)->assertOwned(...$values);
    }

    public static function lostOwnershipCases(): array
    {
        return [
            'expired' => [['leaseExpiresAt' => new DateTimeImmutable('2026-07-11T11:59:59+00:00')]],
            'token changed' => [['storedToken' => '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7e']],
            'source changed' => [['storedSourceVersion' => 'source-v2']],
            'status changed' => [['status' => 'completed']],
        ];
    }

    private function valid(): array
    {
        return [
            'status' => 'running',
            'storedToken' => '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c',
            'claimToken' => '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c',
            'storedSourceVersion' => 'source-v1',
            'claimSourceVersion' => 'source-v1',
            'leaseExpiresAt' => new DateTimeImmutable('2026-07-11T12:00:01+00:00'),
            'now' => new DateTimeImmutable('2026-07-11T12:00:00+00:00'),
            'storedScope' => [1, 2, 3, 4],
            'claimScope' => [1, 2, 3, 4],
        ];
    }
}
