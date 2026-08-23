<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\ScopedReportSourceGuard;
use App\BusinessModules\Core\Reporting\Support\SnapshotRowKeyset;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentRow;
use DateTimeZone;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedPostgresTestDatabase;

final class ReportingSourceGuardAndKeysetTest extends TestCase
{
    #[Test]
    public function source_guard_accepts_only_the_authorized_source_object_in_project(): void
    {
        $context = $this->context(new ReportScopedResource('safety_incident', 17, 11));

        ScopedReportSourceGuard::assertAccessible($context, 11, [
            new ReportScopedResource('safety_incident', 17, 11),
        ]);
        self::addToAssertionCount(1);

        try {
            ScopedReportSourceGuard::assertAccessible($context, 11, [
                new ReportScopedResource('safety_incident', 18, 11),
            ]);
            self::fail('Недоступный исходный объект был раскрыт.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode);
        }
    }

    #[Test]
    public function source_guard_rejects_a_source_from_another_project(): void
    {
        $this->expectException(ReportContractException::class);

        ScopedReportSourceGuard::assertAccessible(
            $this->context(new ReportScopedResource('safety_incident', 17, 11)),
            12,
            [new ReportScopedResource('safety_incident', 17, 12)],
        );
    }

    #[Test]
    public function nullable_keyset_places_nulls_last_and_continues_after_non_null_value(): void
    {
        $sort = new ReportWindowSort('due_date', ReportSortDirection::ASC);
        $query = $this->builder();
        SnapshotRowKeyset::after($query, $sort, '2026-07-30', 'action:7:event:1');
        SnapshotRowKeyset::order($query, $sort);
        $sql = strtolower($query->toSql());

        self::assertStringContainsString('due_date asc nulls last', $sql);
        self::assertStringContainsString('"due_date" is null', $sql);
        self::assertStringContainsString('row_key', $sql);
        self::assertContains('2026-07-30', $query->getBindings());
        self::assertContains('action:7:event:1', $query->getBindings());
    }

    #[Test]
    public function nullable_keyset_continues_inside_null_partition_without_offset(): void
    {
        $sort = new ReportWindowSort('due_date', ReportSortDirection::DESC);
        $query = $this->builder();
        SnapshotRowKeyset::after($query, $sort, null, 'action:7:event:3');
        SnapshotRowKeyset::order($query, $sort);
        $sql = strtolower($query->toSql());

        self::assertStringContainsString('due_date desc nulls last', $sql);
        self::assertStringContainsString('"due_date" is null', $sql);
        self::assertStringNotContainsString('offset', $sql);
        self::assertSame(['action:7:event:3'], $query->getBindings());
    }

    private function context(ReportScopedResource $resource): ReportExecutionContext
    {
        $timezone = new DateTimeZone('UTC');
        $scope = new ReportScope(1, [1], [11], [$resource], $timezone);
        $authorization = new AuthorizationDecisionContext(
            'http',
            1,
            [1],
            [11],
            [$resource],
            $timezone,
            'waves23-source-guard',
            null,
        );

        return new ReportExecutionContext(
            new ReportActor(1, 'active', ['reports.view']),
            $scope,
            new ReportVisibility(true, true, true, true, false, false, false),
            $authorization,
        );
    }

    private function builder(): EloquentBuilder
    {
        $connection = IsolatedPostgresTestDatabase::connection();
        $resolver = new ConnectionResolver(['testing' => $connection]);
        $resolver->setDefaultConnection('testing');
        Model::setConnectionResolver($resolver);
        $model = (new SafetyIncidentRow)->setConnection('testing');

        return $model->newQuery();
    }
}
