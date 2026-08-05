<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateContractPaymentsReportTool;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantGeneratedReportStorage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Report\ReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class GenerateContractPaymentsReportToolTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_requests_contract_payments_report_with_open_project_start_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 12:00:00', 'Europe/Moscow'));

        $organization = new Organization;
        $organization->id = 77;

        $user = new User;
        $user->id = 12;
        $user->current_organization_id = 77;
        $reportService = Mockery::mock(ReportService::class);

        $reportService
            ->shouldReceive('getContractPaymentsReport')
            ->once()
            ->with(Mockery::on(static function (Request $request): bool {
                return $request->query('format') === 'pdf'
                    && $request->query('date_from') === null
                    && $request->query('date_to') === '2026-05-04 23:59:59'
                    && (int) $request->query('project_id') === 56
                    && (int) $request->attributes->get('current_organization_id') === 77;
            }))
            ->andReturn(new StreamedResponse(static function (): void {
                echo '%PDF-contract-payments';
            }));

        $reportStorage = Mockery::mock(AssistantGeneratedReportStorage::class);
        $reportStorage
            ->shouldReceive('storePdf')
            ->once()
            ->with(
                '%PDF-contract-payments',
                Mockery::pattern('/^contract_payments_report_\d+\.pdf$/'),
                $organization,
                $user,
            )
            ->andReturn([
                'pdf_url' => 'https://storage.example.test/contract-payments.pdf',
                'filename' => 'contract-payments.pdf',
                'storage_disk' => 's3',
                'storage_path' => 'org-77/personal-files/user-12/report.pdf',
                'expires_at' => null,
                'size' => 22,
            ]);

        $result = (new GenerateContractPaymentsReportTool($reportService, $reportStorage))->execute([
            'period' => 'с начала проекта по сегодняшний день',
            'date_to' => '2026-05-04',
            'project_id' => 56,
        ], $user, $organization);

        $this->assertSame('success', $result['status']);
        $this->assertSame('https://storage.example.test/contract-payments.pdf', $result['pdf_url']);
        $this->assertSame('org-77/personal-files/user-12/report.pdf', $result['storage_path']);
        $this->assertNull($result['expires_at']);
    }
}
