<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Payments\Reporting\FinanceSourceAccessPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FinanceSourceAccessPolicyTest extends TestCase
{
    #[Test]
    public function source_object_abac_is_fail_closed_for_unscoped_and_malformed_references(): void
    {
        $visible = (new FinanceSourceAccessPolicy)->visibleRefs(
            $this->context(),
            [
                ['type' => 'contract', 'id' => 10, 'hash' => str_repeat('a', 64)],
                ['type' => 'contract', 'id' => 11, 'hash' => str_repeat('b', 64)],
                ['type' => 'payment_document', 'id' => 20],
                ['type' => 'unknown', 'id' => 10],
                ['type' => 'contract', 'id' => '../10'],
                'invalid',
            ],
            ['contract', 'payment_document'],
        );

        self::assertSame(
            [['type' => 'contract', 'id' => '10', 'hash' => str_repeat('a', 64)]],
            $visible,
        );
    }

    #[Test]
    public function non_list_source_payload_is_never_exposed(): void
    {
        self::assertSame(
            [],
            (new FinanceSourceAccessPolicy)->visibleRefs(
                $this->context(),
                ['type' => 'contract', 'id' => 10],
                ['contract'],
            ),
        );
    }

    #[Test]
    public function aggregate_rows_are_restricted_by_matching_source_resources(): void
    {
        $policy = new FinanceSourceAccessPolicy;
        $scope = $this->context()->scope;

        self::assertTrue($policy->allowsAggregate(
            $scope,
            [['type' => 'contract', 'id' => 10]],
            ['contract'],
        ));
        self::assertFalse($policy->allowsAggregate(
            $scope,
            [['type' => 'contract', 'id' => 11]],
            ['contract'],
        ));
        self::assertFalse($policy->allowsAggregate($scope, [], ['contract']));
        self::assertTrue($policy->allowsAggregate($scope, [], ['change_request']));
    }

    #[Test]
    public function aggregate_rows_fail_closed_when_any_relevant_source_is_outside_scope(): void
    {
        self::assertFalse((new FinanceSourceAccessPolicy)->allowsAggregate(
            $this->context()->scope,
            [
                ['type' => 'contract', 'id' => 10],
                ['type' => 'contract', 'id' => 11],
            ],
            ['contract'],
        ));
    }

    #[Test]
    public function aggregate_rows_only_enforce_resource_kinds_constrained_by_the_scope(): void
    {
        self::assertTrue((new FinanceSourceAccessPolicy)->allowsAggregate(
            $this->context()->scope,
            [
                ['type' => 'contract', 'id' => 10],
                ['type' => 'payment_transaction', 'id' => 500],
                ['type' => 'completed_work', 'id' => 700],
            ],
            ['contract', 'payment_transaction', 'completed_work'],
        ));
    }

    private function context(): ReportExecutionContext
    {
        $timezone = new DateTimeZone('Europe/Moscow');
        $resources = [
            new ReportScopedResource('contract', 10, 100),
            new ReportScopedResource('payment_document', 21, 100),
        ];
        $scope = new ReportScope(1, [1], [100], $resources, $timezone);

        return new ReportExecutionContext(
            new ReportActor(7, 'active', ['reports.view']),
            $scope,
            new ReportVisibility(true, false, false, false, false, false, false),
            new AuthorizationDecisionContext('http', 1, [1], [100], $resources, $timezone, 'corr-1', null),
        );
    }
}
