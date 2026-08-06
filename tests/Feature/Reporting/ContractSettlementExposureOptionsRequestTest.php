<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ContractSettlementExposureReportOptionsRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ContractSettlementExposureOptionsRequestTest extends TestCase
{
    #[Test]
    public function exact_as_of_is_the_only_client_owned_option(): void
    {
        $request = $this->request(['as_of' => '2026-08-06T14:15:16.123456+03:00']);

        $request->validateResolved();

        self::assertSame(
            '2026-08-06T14:15:16.123456+03:00',
            $request->asOf()->format('Y-m-d\TH:i:s.uP'),
        );
    }

    #[Test]
    #[DataProvider('clientContextFields')]
    public function client_cannot_replace_server_owned_context(string $field, mixed $value): void
    {
        $request = $this->request([
            'as_of' => '2026-08-06T14:15:16+03:00',
            $field => $value,
        ]);

        try {
            $request->validateResolved();
            self::fail('Client-owned reporting context must be rejected.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
            self::assertSame(['fields' => [$field]], $exception->safeFields);
        }
    }

    public static function clientContextFields(): iterable
    {
        yield 'organization' => ['organization_id', 999];
        yield 'current organization' => ['current_organization_id', 999];
        yield 'holding organizations' => ['holding_organization_ids', [999]];
        yield 'organizations' => ['organization_ids', [999]];
        yield 'project' => ['project_id', 999];
        yield 'current project' => ['current_project_id', 999];
        yield 'projects' => ['project_ids', [999]];
        yield 'user' => ['user_id', 999];
        yield 'actor' => ['actor_id', 999];
        yield 'scope' => ['scope', ['organization_id' => 999]];
        yield 'permissions' => ['permissions', ['reports.view']];
    }

    /** @param array<string, mixed> $query */
    private function request(array $query): ContractSettlementExposureReportOptionsRequest
    {
        $request = ContractSettlementExposureReportOptionsRequest::create(
            '/api/v1/admin/reports/contract-settlement-exposure/options',
            'GET',
            $query,
        );
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));

        return $request;
    }
}
