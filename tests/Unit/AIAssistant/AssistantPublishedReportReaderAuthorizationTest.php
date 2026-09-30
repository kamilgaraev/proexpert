<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRowsAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantPublishedReportProjection;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantPublishedReportReader;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;

final class AssistantPublishedReportReaderAuthorizationTest extends TestCase
{
    public function test_native_current_report_authorization_failure_prevents_all_numeric_reads(): void
    {
        $reader = $this->readerDeniedByCurrentNativeReportAccess();
        $actor = new User;
        $actor->forceFill(['id' => 9, 'current_organization_id' => 1, 'is_active' => true]);
        $organization = new Organization;
        $organization->id = 1;
        $this->expectException(ReportContractException::class);
        $reader->read($actor, $organization, '01K5ABCDEFGHJKMNPQRSTVWXYZ');
    }

    public function test_saved_projection_receipt_cannot_reauthorize_a_now_unreadable_report(): void
    {
        $reader = $this->readerDeniedByCurrentNativeReportAccess();
        $actor = new User;
        $actor->forceFill(['id' => 9, 'current_organization_id' => 1, 'is_active' => true]);
        self::assertFalse($reader->matchesReference($actor, 1, ['entity_type' => 'published_report_financial_projection',
            'entity_id' => '01K5ABCDEFGHJKMNPQRSTVWXYZ', 'organization_id' => 1,
            'composite_key' => ['scope' => 'returned_report_row', 'row_key' => 'row1', 'currency' => 'RUB'],
            'source_version' => hash('sha256', 'old-authorized-receipt'), 'cursor' => null, 'limit' => 20]));
    }

    private function readerDeniedByCurrentNativeReportAccess(): AssistantPublishedReportReader
    {
        $database = $this->createMock(ConnectionInterface::class);
        $database->expects(self::once())->method('transaction')->willReturnCallback(static fn (Closure $callback): mixed => $callback());
        $database->expects(self::once())->method('statement')->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY')->willReturn(true);
        $subjects = $this->createMock(ReportAuthorizationSubjectReader::class);
        $subjects->expects(self::once())->method('run')->with('01K5ABCDEFGHJKMNPQRSTVWXYZ')
            ->willThrowException(ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN));
        $targets = $this->createMock(ReportHttpAuthorizationTargetResolver::class);
        $targets->expects(self::never())->method('run');
        $runs = $this->createMock(GetReportRunAction::class);
        $runs->expects(self::never())->method('handle');
        $rows = $this->createMock(GetReportRowsAction::class);
        $rows->expects(self::never())->method('handle');
        $store = $this->createMock(ReportRunStore::class);
        $store->expects(self::never())->method('queryForRun');
        return new AssistantPublishedReportReader(new ReportHttpAuthorizationOrchestrator($database, new ReportExecutionContextFactory,
            $targets, $subjects, $this->createMock(CurrentReportScopeAuthorizer::class)), $runs, $rows, $store, new ReportDefinitionBindingMap([]), new AssistantPublishedReportProjection);
    }
}
