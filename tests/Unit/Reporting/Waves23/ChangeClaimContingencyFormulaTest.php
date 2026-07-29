<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ChangeExposureFact;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ContingencyMovement;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Exceptions\DuplicateChangeClaimLink;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services\ChangeClaimContingencyFormula;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChangeClaimContingencyFormulaTest extends TestCase
{
    #[Test]
    public function proposed_approved_claim_and_contingency_are_distinct(): void
    {
        $metric = (new ChangeClaimContingencyFormula())->summarize(
            [
                new ChangeExposureFact(
                    changeRequestId: 1,
                    changeVersion: 1,
                    projectId: 10,
                    allocationId: 20,
                    currency: 'RUB',
                    proposedMinor: 1_000_000,
                    approvedMinor: 750_000,
                    linkedClaims: [
                        ['id' => 100, 'version' => 1, 'amount_minor' => 250_000],
                    ],
                ),
            ],
            [
                ContingencyMovement::opening(2_000_000, 'RUB'),
                ContingencyMovement::allocation(500_000, 'RUB'),
                ContingencyMovement::consumption(300_000, 'RUB'),
                ContingencyMovement::release(100_000, 'RUB'),
            ],
        );

        self::assertSame(1_000_000, $metric->proposedExposureMinor);
        self::assertSame(750_000, $metric->approvedExposureMinor);
        self::assertSame(250_000, $metric->linkedClaimMinor);
        self::assertSame(2_100_000, $metric->closingContingencyMinor);
    }

    #[Test]
    public function only_the_latest_change_version_contributes_exposure(): void
    {
        $metric = (new ChangeClaimContingencyFormula())->summarize(
            [
                new ChangeExposureFact(1, 1, 10, 20, 'RUB', 1_000, null, []),
                new ChangeExposureFact(1, 2, 10, 20, 'RUB', 1_500, 1_200, []),
            ],
            [ContingencyMovement::opening(2_000, 'RUB')],
        );

        self::assertSame(1_500, $metric->proposedExposureMinor);
        self::assertSame(1_200, $metric->approvedExposureMinor);
    }

    #[Test]
    public function one_claim_link_cannot_inflate_two_change_versions(): void
    {
        $this->expectException(DuplicateChangeClaimLink::class);

        (new ChangeClaimContingencyFormula())->summarize(
            [
                new ChangeExposureFact(
                    1,
                    1,
                    10,
                    20,
                    'RUB',
                    1_000,
                    null,
                    [['id' => 100, 'version' => 1, 'amount_minor' => 250]],
                ),
                new ChangeExposureFact(
                    1,
                    2,
                    10,
                    20,
                    'RUB',
                    1_500,
                    1_200,
                    [['id' => 100, 'version' => 1, 'amount_minor' => 250]],
                ),
            ],
            [ContingencyMovement::opening(2_000, 'RUB')],
        );
    }

    #[Test]
    public function cross_currency_aggregation_is_rejected(): void
    {
        $this->expectException(DomainException::class);

        (new ChangeClaimContingencyFormula())->summarize(
            [new ChangeExposureFact(1, 1, 10, 20, 'RUB', 1_000, null, [])],
            [ContingencyMovement::opening(2_000, 'USD')],
        );
    }
}
