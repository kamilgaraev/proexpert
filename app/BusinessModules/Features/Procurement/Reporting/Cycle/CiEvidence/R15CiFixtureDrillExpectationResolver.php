<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use App\BusinessModules\Core\Reporting\Application\Conformance\ReportConformanceDrillExpectation;
use App\BusinessModules\Core\Reporting\Application\Conformance\ReportConformanceDrillExpectationResolver;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class R15CiFixtureDrillExpectationResolver implements ReportConformanceDrillExpectationResolver
{
    public function __construct(private ReportConformanceDrillExpectation $expectation) {}

    public function resolve(Sha256Hash $fixtureHash): ReportConformanceDrillExpectation
    {
        if (! hash_equals($fixtureHash->value, $this->expectation->fixtureHash->value)) {
            throw new InvalidArgumentException('r15_ci_fixture_hash_mismatch');
        }

        return $this->expectation;
    }
}
