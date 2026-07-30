<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Domain\Contracts;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
interface ReportSubscriptionEventRecorder { public function record(string $eventCode, ReportExecutionContext $context, string $subjectType, string $subjectId, int $transitionVersion, array $safeEvidence): void; }
