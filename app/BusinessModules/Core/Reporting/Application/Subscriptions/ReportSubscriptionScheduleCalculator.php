<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription; use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency; use DateInterval; use DateTimeImmutable; use DateTimeZone;
final class ReportSubscriptionScheduleCalculator
{
    public function next(ReportSubscription $subscription, DateTimeImmutable $after): DateTimeImmutable
    {
        $timezone=$subscription->timezone; $cursor=$after->setTimezone($timezone); [$hour,$minute]=array_map('intval',explode(':',$subscription->localTime));
        $candidate=$cursor->setTime($hour,$minute);
        if ($candidate <= $cursor) $candidate=$candidate->modify('+1 day')->setTime($hour,$minute);
        if ($subscription->frequency===ReportSubscriptionFrequency::WEEKLY) { $delta=(($subscription->weekday-(int)$candidate->format('N')+7)%7); $candidate=$candidate->modify("+{$delta} days"); }
        if ($subscription->frequency===ReportSubscriptionFrequency::MONTHLY) { $day=$subscription->dayOfMonth; $year=(int)$candidate->format('Y'); $month=(int)$candidate->format('m'); if ((int)$candidate->format('d')>$day || ((int)$candidate->format('d')===$day && $candidate<=$cursor)) { $candidate=$candidate->modify('first day of next month')->setTime($hour,$minute); $year=(int)$candidate->format('Y'); $month=(int)$candidate->format('m'); } $candidate=$candidate->setDate($year,$month,min($day,(int)$candidate->format('t'))); }
        return $candidate->setTimezone(new DateTimeZone('UTC'));
    }
}
