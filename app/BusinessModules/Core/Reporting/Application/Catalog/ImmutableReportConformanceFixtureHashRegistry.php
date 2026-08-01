<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Catalog;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportConformanceFixtureHashRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportConformanceFixture;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;
use LogicException;

final readonly class ImmutableReportConformanceFixtureHashRegistry implements ReportConformanceFixtureHashRegistry
{
    private array $fixtures;

    public function __construct(array $fixtures)
    {
        $indexed = [];
        foreach ($fixtures as $code => $fixture) {
            if (! is_string($code)
                || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1
                || ! $fixture instanceof ReportConformanceFixture) {
                throw new InvalidArgumentException('report_conformance_fixture_registry_invalid');
            }
            $indexed[$code] = $fixture;
        }

        $this->fixtures = $indexed;
    }

    public function fixtureHash(string $code): Sha256Hash
    {
        $fixture = $this->fixtures[$code] ?? null;
        if (! $fixture instanceof ReportConformanceFixture) {
            throw new LogicException('report_conformance_fixture_not_found');
        }

        return $fixture->fixtureHash;
    }
}
