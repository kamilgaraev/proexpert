<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Subscriptions;

use App\BusinessModules\Core\Reporting\Domain\DTO\UpdateReportSubscriptionData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UpdateReportSubscriptionDataTest extends TestCase
{
    public function test_accepts_allowed_associative_changes(): void
    {
        $data = new UpdateReportSubscriptionData(['format' => 'xlsx']);

        self::assertSame(['format' => 'xlsx'], $data->changes);
    }

    public function test_rejects_unknown_change_field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_subscription_update_invalid');

        new UpdateReportSubscriptionData(['unknown_field' => 'value']);
    }
}
