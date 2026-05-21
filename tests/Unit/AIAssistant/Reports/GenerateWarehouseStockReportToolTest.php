<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateWarehouseStockReportTool;
use App\Models\Organization;
use App\Models\User;
use App\Services\Report\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

final class GenerateWarehouseStockReportToolTest extends TestCase
{
    public function test_requests_material_stock_report_only(): void
    {
        $organization = Organization::factory()->make(['id' => 77]);
        $user = User::factory()->make(['id' => 12, 'current_organization_id' => 77]);
        $reportService = Mockery::mock(ReportService::class);

        $reportService
            ->shouldReceive('getWarehouseStockReport')
            ->once()
            ->with(Mockery::on(static function (Request $request): bool {
                return $request->query('format') === 'pdf'
                    && $request->query('asset_type') === 'material'
                    && (int) $request->attributes->get('current_organization_id') === 77;
            }))
            ->andReturn(new StreamedResponse(static function (): void {
                echo '%PDF';
            }));

        $disk = Mockery::mock();
        $disk
            ->shouldReceive('put')
            ->once()
            ->with(Mockery::pattern('/^org-77\/reports\/warehouse_stock_report_\d+\.pdf$/'), '%PDF')
            ->andReturn(true);
        $disk
            ->shouldReceive('temporaryUrl')
            ->once()
            ->with(Mockery::type('string'), Mockery::type(\DateTimeInterface::class))
            ->andReturn('https://storage.example.test/report.pdf');

        Storage::shouldReceive('disk')->twice()->with('s3')->andReturn($disk);

        $result = (new GenerateWarehouseStockReportTool($reportService))->execute([], $user, $organization);

        $this->assertSame('success', $result['status']);
        $this->assertSame('https://storage.example.test/report.pdf', $result['pdf_url']);
    }
}
