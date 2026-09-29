<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantLiveProjectFinanceProjection;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantLiveProjectFinanceReader;
use App\BusinessModules\Features\Budgeting\Contracts\ExactProjectFinanceSourceRead;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Closure;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class AssistantLiveProjectFinanceReaderTest extends TestCase
{
    use UsesAssistantUnitTranslations { tearDown as private translationsTearDown; }
    private ?ConnectionResolverInterface $previousResolver = null;

    protected function tearDown(): void
    {
        $this->previousResolver === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($this->previousResolver);
        $this->translationsTearDown();
    }

    public function test_revoked_finance_after_native_scoped_read_cannot_publish_previously_read_totals(): void
    {
        [$reader, $actor, $organization] = $this->fixture(true);
        $this->expectException(AccessDeniedHttpException::class);
        $reader->read($actor, $organization, ['project_id' => 7, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => null]);
    }

    public function test_native_project_authorization_reads_existing_sources_without_creating_a_report_run(): void
    {
        [$reader, $actor, $organization] = $this->fixture(false);
        $output = $reader->read($actor, $organization, ['project_id' => 7, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => null]);
        self::assertSame('partial', $output['status']);
        self::assertFalse($output['useful']);
        self::assertSame('1.25', $output['financial_evidence']['rows'][0]['fields']['actual_revenue']);
        self::assertSame('live_ledger_read', $output['source_refs'][0]['evidence_time_basis']);
    }

    public function test_native_machinery_shift_identity_uses_registered_shift_report_policy(): void
    {
        [$reader, $actor, $organization] = $this->fixture(false, 'machinery_shift');
        $output = $reader->read($actor, $organization, ['project_id' => 7, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => null]);
        self::assertSame('partial', $output['status']);
        self::assertSame('1.25', $output['financial_evidence']['rows'][0]['fields']['actual_cost']);
        self::assertNotSame('source_identity_unavailable', $output['reason'] ?? null);
    }

    private function fixture(bool $revokeAfterRead, string $sourceType = 'completed_work'): array
    {
        $this->previousResolver = Model::getConnectionResolver();
        $connection = $this->getMockBuilder(PostgresConnection::class)->setConstructorArgs([static fn () => throw new \LogicException('Pure test must not connect to PostgreSQL')])->onlyMethods(['select'])->getMock();
        $connection->method('select')->willReturn([(object) ['exists' => true]]);
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        $resolver->method('connection')->willReturn($connection);
        Model::setConnectionResolver($resolver);
        $schema = $this->createMock(\Illuminate\Database\Schema\Builder::class);
        $schema->method('getColumnListing')->willReturn(['id', 'organization_id', 'project_id', 'contract_id', 'asset_id', 'deleted_at', 'status', 'quantity', 'total_amount', 'amount', 'completion_date']);
        app()->instance('db.schema', $schema);
        $nativeChecks = 0;
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('forCurrentChecks')->willReturnSelf();
        $authorization->method('canCurrent')->willReturnCallback(static function (User $user, string $permission) use ($revokeAfterRead, &$nativeChecks): bool {
            return ! ($revokeAfterRead && $nativeChecks >= 2 && $permission === 'finance.view');
        });
        $modules = $this->createMock(OrganizationEntitlementService::class);
        $modules->method('getEffectiveModules')->willReturn(collect(array_map(static fn (string $slug): Module => new Module(['slug' => $slug]), ['ai-assistant', 'project-management', 'payments', 'contract-management', 'budgeting', 'machinery-operations'])));
        $projects = $this->createMock(UserProjectAccessService::class);
        $projects->method('queryAccessibleProjects')->willReturnCallback(static fn () => Project::query());
        $policy = new AssistantDataAccessPolicy($authorization, $projects, $modules);
        $actor = $this->createPartialMock(User::class, ['belongsToOrganization']);
        $actor->method('belongsToOrganization')->willReturn(true);
        $actor->forceFill(['id' => 9, 'current_organization_id' => 1, 'is_active' => true]);
        $organization = new Organization;
        $organization->id = 1;
        $definition = (new ReportDefinitionBuilder)->code('project_margin')->payload();
        $target = new CurrentReportAuthorizationTarget($definition, ReportOperation::RUN, null);
        $targets = $this->createMock(ReportHttpAuthorizationTargetResolver::class);
        $targets->expects(self::exactly(2))->method('createRun')->with('project_margin')->willReturn($target);
        $subjects = $this->createMock(ReportAuthorizationSubjectReader::class);
        $subjects->expects(self::never())->method('run');
        $scopes = $this->createMock(CurrentReportScopeAuthorizer::class);
        $scopes->expects(self::exactly(2))->method('authorizeExact')->willReturnCallback(static function () use (&$nativeChecks, $target): CurrentReportAuthorization {
            $nativeChecks++;
            return new CurrentReportAuthorization(new ReportActor(9, 'active', []), new AuthorizationDecisionContext('http', 1, [1], [7], [], new DateTimeZone('UTC'), 'live-financial-read', null),
                new ReportVisibility(true, true, false, false, false, true, true), $target);
        });
        $database = $this->createMock(ConnectionInterface::class);
        $database->expects(self::exactly(2))->method('transaction')->willReturnCallback(static fn (Closure $callback): mixed => $callback());
        $database->expects(self::exactly(2))->method('statement')->willReturn(true);
        $sources = $this->createMock(ExactProjectFinanceSourceRead::class);
        $source = ['source_type' => $sourceType, 'source_id' => 1, 'source_line_id' => 1, 'component' => 'actual', 'direction' => $sourceType === 'machinery_shift' ? 'cost' : 'revenue', 'currency' => 'RUB',
            'project_id' => 7, 'amount_without_vat' => '1.25', 'management_amount' => '1.25', 'recognition_date' => '2026-09-01', 'problem_flags' => ''];
        $coverage = array_map(static fn (string $type): array => ['source_type' => $type, 'available' => $type !== 'budget_amount', 'included_source_rows' => $type === $sourceType ? 1 : 0, 'problem_rows_count' => 0],
            ['budget_amount', 'contract_performance_act', 'completed_work', 'payment_document', 'warehouse_movement', 'time_entry', 'machinery_shift', 'machinery_maintenance']);
        $sources->expects(self::once())->method('exactFinancialSourceRows')->with(self::callback(static fn (array $input): bool => $input['organization_id'] === 1 && $input['project_id'] === 7), [7], $actor)->willReturn([
            'status' => 'success', 'filters' => ['organization_id' => 1, 'project_id' => 7, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => null], 'sources' => [$source],
            'aggregates' => [['project_id' => 7, 'currency' => 'RUB', 'source_rows_count' => 1, 'problem_flags' => '', 'plan_revenue' => '0', 'plan_cost' => '0', 'forecast_revenue' => '0', 'forecast_cost' => '0', 'actual_revenue' => $sourceType === 'machinery_shift' ? '0' : '1.25', 'actual_cost' => $sourceType === 'machinery_shift' ? '1.25' : '0']],
            'coverage' => $coverage, 'budget_version' => null, 'fetched_at' => (new \DateTimeImmutable('-1 second'))->format(DATE_ATOM)]);
        return [new AssistantLiveProjectFinanceReader(new ReportHttpAuthorizationOrchestrator($database, new ReportExecutionContextFactory, $targets, $subjects, $scopes), $sources, new AssistantLiveProjectFinanceProjection, $policy, $authorization), $actor, $organization];
    }
}
