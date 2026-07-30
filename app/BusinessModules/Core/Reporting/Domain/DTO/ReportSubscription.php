<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Domain\DTO;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
final readonly class ReportSubscription { public function __construct(public string $id,public int $organizationId,public int $ownerId,public string $savedViewId,public string $reportCode,public ReportSubscriptionFrequency $frequency,public ?int $weekday,public ?int $dayOfMonth,public string $localTime,public DateTimeZone $timezone,public array $periodPolicy,public string $format,public ReportSubscriptionStatus $status,public ?string $disabledReason,public int $consecutiveFailures,public ?DateTimeImmutable $nextRunAt,public string $executionInputBytes,public Sha256Hash $executionInputHash,public Sha256Hash $definitionHash,public string $contractVersion,public int $transitionVersion,public DateTimeImmutable $createdAt,public DateTimeImmutable $updatedAt) {} }
