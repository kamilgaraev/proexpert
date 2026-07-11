<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\LaravelGeneratedEstimateNumberAllocator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\OrdinaryEstimateNumberLookup;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeneratedEstimateNumberAllocatorTest extends TestCase
{
    #[Test]
    public function free_session_number_is_stable_and_organization_scoped(): void
    {
        $lookup = new TestOrdinaryEstimateNumberLookup([]);
        $allocator = new LaravelGeneratedEstimateNumberAllocator($lookup, fn (): string => 'suffix');

        self::assertSame('AI-42', $allocator->allocate($this->session(), 0));
        self::assertSame([[10, 'AI-42']], $lookup->lookups);
    }

    #[Test]
    public function occupied_base_and_retry_attempts_receive_distinct_bounded_numbers(): void
    {
        $suffixes = ['01HAAA', '01HBBB'];
        $lookup = new TestOrdinaryEstimateNumberLookup(['AI-42']);
        $allocator = new LaravelGeneratedEstimateNumberAllocator(
            $lookup,
            static function () use (&$suffixes): string {
                return (string) array_shift($suffixes);
            },
        );

        $occupied = $allocator->allocate($this->session(), 0);
        $retry = $allocator->allocate($this->session(), 1);

        self::assertSame('AI-42-01HAAA', $occupied);
        self::assertSame('AI-42-01HBBB', $retry);
        self::assertNotSame($occupied, $retry);
        self::assertLessThanOrEqual(255, mb_strlen($retry));
    }

    private function session(): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession(['organization_id' => 10]);
        $session->id = 42;
        $session->exists = true;

        return $session;
    }
}

final class TestOrdinaryEstimateNumberLookup implements OrdinaryEstimateNumberLookup
{
    /** @var array<int, array{int, string}> */
    public array $lookups = [];

    /** @param array<int, string> $occupied */
    public function __construct(private array $occupied) {}

    public function exists(int $organizationId, string $number): bool
    {
        $this->lookups[] = [$organizationId, $number];

        return in_array($number, $this->occupied, true);
    }
}
