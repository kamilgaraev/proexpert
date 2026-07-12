<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Exceptions\NormativeContextPinUnavailable;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ApprovedNormativeDatasetLookup;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeDatasetPinPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativePinClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class NormativeDatasetPinPolicyTest extends TestCase
{
    public function test_old_request_gets_server_policy_version_and_immutable_clock_date(): void
    {
        $policy = new NormativeDatasetPinPolicy($this->lookup(true), $this->clock('2026-07-12'), 'approved-v1');
        self::assertSame(['normative_dataset_version' => 'approved-v1', 'business_date' => '2026-07-12'], $policy->resolve(null));
    }

    public function test_explicit_mismatch_and_unapproved_policy_fail_closed(): void
    {
        $this->expectException(NormativeContextPinUnavailable::class);
        (new NormativeDatasetPinPolicy($this->lookup(true), $this->clock('2026-07-12'), 'approved-v1'))->resolve('other-v1');
    }

    private function lookup(bool $approved): ApprovedNormativeDatasetLookup
    {
        return new class($approved) implements ApprovedNormativeDatasetLookup
        {
            public function __construct(private bool $approved) {}

            public function approved(string $version): bool
            {
                return $this->approved;
            }
        };
    }

    private function clock(string $date): NormativePinClock
    {
        return new class($date) implements NormativePinClock
        {
            public function __construct(private string $date) {}

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->date);
            }
        };
    }
}
