<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateWarehouseStockReportTool;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantGeneratedReportStorage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Report\ReportService;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class GenerateWarehouseStockReportToolTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_requests_stock_report_without_asset_type_restriction(): void
    {
        $organization = new Organization;
        $organization->id = 77;

        $user = new User;
        $user->id = 12;
        $user->current_organization_id = 77;
        $reportService = Mockery::mock(ReportService::class);

        $reportService
            ->shouldReceive('getWarehouseStockReport')
            ->once()
            ->with(Mockery::on(static function (Request $request): bool {
                return $request->query('format') === 'pdf'
                    && ! $request->query->has('asset_type')
                    && (int) $request->attributes->get('current_organization_id') === 77;
            }))
            ->andReturn(new StreamedResponse(static function (): void {
                echo '%PDF';
            }));

        $reportStorage = Mockery::mock(AssistantGeneratedReportStorage::class);
        $reportStorage
            ->shouldReceive('storePdf')
            ->once()
            ->with(
                '%PDF',
                Mockery::pattern('/^warehouse_stock_report_\d+\.pdf$/'),
                $organization,
                $user,
            )
            ->andReturn([
                'pdf_url' => 'https://storage.example.test/report.pdf',
                'filename' => 'warehouse-stock.pdf',
                'storage_disk' => 's3',
                'storage_path' => 'org-77/personal-files/user-12/report.pdf',
                'expires_at' => null,
                'size' => 4,
            ]);

        $result = (new GenerateWarehouseStockReportTool($reportService, $reportStorage))->execute([], $user, $organization);

        $this->assertSame('success', $result['status']);
        $this->assertSame('https://storage.example.test/report.pdf', $result['pdf_url']);
    }
}
