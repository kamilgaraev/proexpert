<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Domain\DTO;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
final readonly class CreateReportSubscriptionData { public function __construct(public string $savedViewId, public ReportSubscriptionFrequency $frequency, public ?int $weekday, public ?int $dayOfMonth, public string $localTime, public DateTimeZone $timezone, public array $periodPolicy, public string $format) { if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',$savedViewId)!==1 || preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/D',$localTime)!==1 || $periodPolicy===[] || !in_array($format,['csv','xlsx','pdf'],true) || ($frequency===ReportSubscriptionFrequency::DAILY && ($weekday!==null || $dayOfMonth!==null)) || ($frequency===ReportSubscriptionFrequency::WEEKLY && ($weekday===null || $weekday<1 || $weekday>7 || $dayOfMonth!==null)) || ($frequency===ReportSubscriptionFrequency::MONTHLY && ($dayOfMonth===null || $dayOfMonth<1 || $dayOfMonth>31 || $weekday!==null))) throw new InvalidArgumentException('report_subscription_schedule_invalid'); } }
