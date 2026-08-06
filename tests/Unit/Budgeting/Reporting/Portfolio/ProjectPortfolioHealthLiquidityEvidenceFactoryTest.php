<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthLiquidityEvidenceFactory;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceComponent;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceGap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectPortfolioHealthLiquidityEvidenceFactoryTest extends TestCase
{
    #[Test]
    public function it_builds_order_stable_evidence_from_complete_versions(): void
    {
        $factory = new ProjectPortfolioHealthLiquidityEvidenceFactory;
        $items = [$this->item(1, 10), $this->item(2, 20)];
        $versions = [$this->version(1, 'a'), $this->version(2, 'b')];

        $first = $factory->make($items, $versions, [], '2026-08-04T00:00:00+00:00');
        $second = $factory->make(array_reverse($items), array_reverse($versions), [], '2026-08-04T00:00:00+00:00');

        self::assertInstanceOf(ProjectPortfolioHealthSourceComponent::class, $first);
        self::assertInstanceOf(ProjectPortfolioHealthSourceComponent::class, $second);
        self::assertSame($first->sourceHash, $second->sourceHash);
    }

    #[Test]
    public function it_fails_closed_when_any_calendar_item_lacks_one_exact_version(): void
    {
        $factory = new ProjectPortfolioHealthLiquidityEvidenceFactory;
        $items = [$this->item(1, 10), $this->item(2, 20)];

        self::assertInstanceOf(
            ProjectPortfolioHealthSourceGap::class,
            $factory->make($items, [$this->version(1, 'a')], [], '2026-08-04T00:00:00+00:00'),
        );
        self::assertInstanceOf(
            ProjectPortfolioHealthSourceGap::class,
            $factory->make($items, [$this->version(1, 'a'), $this->version(1, 'b')], [], '2026-08-04T00:00:00+00:00'),
        );
        self::assertInstanceOf(
            ProjectPortfolioHealthSourceGap::class,
            $factory->make(
                $items,
                [$this->version(1, 'a'), $this->version(2, 'b')],
                [['code' => 'source_projection_gap']],
                '2026-08-04T00:00:00+00:00',
            ),
        );
    }

    private function item(int $sourceId, int $projectId): PaymentCalendarItem
    {
        return new PaymentCalendarItem(
            organizationId: 38,
            date: '2026-08-04',
            originalDate: null,
            direction: 'outflow',
            bucket: 'planned',
            amount: '100.00',
            remainingAmount: '100.00',
            currency: 'RUB',
            probability: 100,
            status: 'planned',
            sourceType: 'payment_document',
            sourceId: $sourceId,
            cashFlowKey: 'payment_document:'.$sourceId,
            projectId: $projectId,
        );
    }

    /** @return array<string,mixed> */
    private function version(int $sourceId, string $hashCharacter): array
    {
        return [
            'source_type' => 'payment_document',
            'source_id' => $sourceId,
            'source_version' => 'v'.$sourceId,
            'source_hash' => str_repeat($hashCharacter, 64),
            'history_complete' => true,
        ];
    }
}
