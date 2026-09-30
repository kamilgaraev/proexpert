<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRowsAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantPublishedReportProjection;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantPublishedReportReader;
use App\Models\Organization;
use App\Models\User;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class AssistantPublishedReportReaderFreshnessTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_sensitive_right_revoked_after_rows_hides_previously_read_money_and_totals(): void
    {
        [$reader, $actor, $organization] = $this->fixture(false);
        $output = $reader->read($actor, $organization, '01K5ABCDEFGHJKMNPQRSTVWXYZ');
        self::assertCount(2, $output['financial_evidence']['rows']);
        self::assertSame(['wip' => '3'], $output['financial_evidence']['rows'][0]['fields']);
        self::assertSame(['wip_minor' => '6'], $output['financial_evidence']['rows'][1]['fields']);
        self::assertStringNotContainsString('999999999', $output['server_formatted_answer']);
        self::assertSame('budgeting_project_finance', $output['source_refs'][0]['snapshot_kind']);
    }

    public function test_view_right_revoked_after_rows_prevents_returning_any_financial_evidence(): void
    {
        [$reader, $actor, $organization] = $this->fixture(true);
        $this->expectException(ReportContractException::class);
        $reader->read($actor, $organization, '01K5ABCDEFGHJKMNPQRSTVWXYZ');
    }

    public function test_saved_jsonb_composite_order_is_accepted_but_extra_identity_fields_are_rejected(): void
    {
        [$reader, $actor, $organization] = $this->fixture(false, 3);
        $reference = $reader->read($actor, $organization, '01K5ABCDEFGHJKMNPQRSTVWXYZ')['source_refs'][0];
        $reference['composite_key'] = array_reverse($reference['composite_key'], true);
        self::assertTrue($reader->matchesReference($actor, 1, $reference));
        $reference['composite_key']['extra'] = 'forged';
        self::assertFalse($reader->matchesReference($actor, 1, $reference));
    }

    private function fixture(bool $denyFinalRead, int $reads = 1): array
    {
        $id = '01K5ABCDEFGHJKMNPQRSTVWXYZ';
        $date = new DateTimeImmutable('-1 hour');
        $definition = (new ReportDefinitionBuilder)->code('wip_completion_forecast')->columns([['id' => 'wip'], ['id' => 'eac']])
            ->sorts([['id' => 'wip', 'direction' => 'asc']])->outputClassification(new ReportOutputClassification(ReportDataClassification::STANDARD, ['eac'], [], false, false, false))->payload();
        $scope = new ReportScope(1, [1], [7], [], new DateTimeZone('UTC'));
        $actor = new User;
        $actor->forceFill(['id' => 9, 'current_organization_id' => 1, 'is_active' => true]);
        $organization = new Organization;
        $organization->id = 1;
        $query = new ReportQuery($definition, $scope, new ReportFilterSet([]), [], $date, 'ru');
        $hash = new Sha256Hash(str_repeat('b', 64));
        $snapshot = new ReportSnapshotRef('budgeting_project_finance', 'snapshot1', $scope, $definition->definitionHash, $definition->formulaVersion, $hash, $date, $date->modify('+1 day'), [], ReportSnapshotClassification::OPERATIONAL, null);
        $target = new CurrentReportAuthorizationTarget($definition, ReportOperation::VIEW, $snapshot);
        $subjects = $this->createMock(ReportAuthorizationSubjectReader::class);
        $subjects->expects(self::exactly(2 * $reads))->method('run')->with($id)->willReturn(new ReportAuthorizationSubject(ReportDispatchAggregate::RUN, $id, $definition, $scope, $snapshot, null, null));
        $targets = $this->createMock(ReportHttpAuthorizationTargetResolver::class);
        $targets->expects(self::exactly(2 * $reads))->method('run')->with($id, ReportOperation::VIEW)->willReturn($target);
        $authorizer = $this->createMock(CurrentReportScopeAuthorizer::class);
        $calls = 0;
        $authorizer->expects(self::exactly(2 * $reads))->method('authorizeExact')->willReturnCallback(static function () use (&$calls, $denyFinalRead, $target): CurrentReportAuthorization {
            $calls++;
            if ($calls === 2 && $denyFinalRead) { throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN); }
            return new CurrentReportAuthorization(new ReportActor(9, 'active', []), new AuthorizationDecisionContext('http', 1, [1], [7], [], new DateTimeZone('UTC'), 'current-report-access', null),
                new ReportVisibility(true, false, false, false, false, $calls === 1, false), $target);
        });
        $quality = new ReportQuality(ReportQualityStatus::COMPLETE, null, [], 0, ReportReconciliationStatus::MATCHED, [], []);
        $provenance = new ReportProvenance('most', [new ReportSourceRef('ledger', $snapshot->kind, $snapshot->id, 'v1', 'v1', 1, $hash)], $hash, null);
        $metadata = new ReportResultMetadata($snapshot, 1, $date, $snapshot->staleAt);
        $run = new ReportRun($id, $definition->code, ReportRunStatus::READY, $definition->definitionHash, $definition->contractVersion, $definition->formulaVersion,
            $definition->sourceSchemaVersion, $definition->rendererVersion, $query->queryHash, $hash, 100, 1, $metadata, ['RUB' => ['wip_minor' => 6, 'eac_minor' => 999999999]],
            ReportFreshnessStatus::FRESH, $quality, $provenance, $date, $date, $date, $date->modify('+1 day'), null, 'reused', null);
        $runs = $this->createMock(GetReportRunAction::class);
        $runReads = 0;
        $runs->expects(self::exactly($denyFinalRead ? 1 : 2 * $reads))->method('handle')->willReturnCallback(static function () use (&$runReads, $run): ReportRun {
            $runReads++;
            return $runReads % 2 === 1 ? $run : $run->withTotals(['RUB' => ['wip_minor' => 6]]);
        });
        $rows = $this->createMock(GetReportRowsAction::class);
        $rows->expects(self::exactly($reads))->method('handle')->willReturn(new ReportPage([['row_key' => 'row1', 'currency' => 'RUB', 'wip' => 3, 'eac' => 999999999]], $run->totals, ReportFreshnessStatus::FRESH, $quality, null, 20, false, new ReportWindowSort('wip', ReportSortDirection::ASC)));
        $store = $this->createMock(ReportRunStore::class);
        $store->expects(self::exactly($reads))->method('queryForRun')->willReturn($query);
        $provider = $this->createMock(ReportDataProvider::class);
        $provider->expects(self::never())->method('materialize');
        $provider->expects(self::exactly($reads))->method('result')->willReturn(new ReportResult($metadata, $run->totals, ReportFreshnessStatus::FRESH, $quality, $provenance, [['id' => 'wip', 'type' => 'money_minor'], ['id' => 'eac', 'type' => 'money_minor']], []));
        $bindings = new ReportDefinitionBindingMap([$definition->code => new ReportDefinitionBinding($definition->code, $definition->definitionHash, $definition->contractVersion, $provider, $this->createMock(ReportRowQuery::class), $this->createMock(ReportDrillDownProvider::class), null)]);
        $database = $this->createMock(ConnectionInterface::class);
        $database->expects(self::exactly(2 * $reads))->method('transaction')->willReturnCallback(static fn (Closure $callback): mixed => $callback());
        $database->expects(self::exactly(2 * $reads))->method('statement')->willReturn(true);
        $reader = new AssistantPublishedReportReader(new ReportHttpAuthorizationOrchestrator($database, new ReportExecutionContextFactory, $targets, $subjects, $authorizer), $runs, $rows, $store, $bindings, new AssistantPublishedReportProjection);
        return [$reader, $actor, $organization];
    }
}
