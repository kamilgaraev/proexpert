<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;

final class FakeReportTransitionAudit implements ReportTransitionAudit
{
    private array $events = [];

    public function __construct(private readonly bool $fails = false)
    {
    }

    public static function failing(): self
    {
        return new self(true);
    }

    public function append(
        string $eventId,
        string $eventType,
        ReportExecutionContext $context,
        array $subject,
        DateTimeImmutable $occurredAt,
    ): void {
        if ($this->fails) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_DEPENDENCY_FAILED);
        }

        if (trim($eventId) === '' || trim($eventType) === '') {
            throw new InvalidArgumentException('report_transition_audit_identity_invalid');
        }

        CanonicalJson::encode($subject);

        $this->events[] = [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'context' => $context,
            'subject' => $subject,
            'occurred_at' => $occurredAt,
        ];
    }

    public function events(): array
    {
        return $this->events;
    }
}
