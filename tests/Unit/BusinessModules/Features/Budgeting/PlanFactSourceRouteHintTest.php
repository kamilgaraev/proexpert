<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\PlanFactDrillDownItem;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PlanFactSourceRouteHintTest extends TestCase
{
    #[DataProvider('sourceRoutes')]
    public function test_source_links_only_target_the_actual_payment_entity(string $type, int $sourceId, ?int $documentId, array $expected): void
    {
        $service = (new ReflectionClass(PlanFactReportService::class))->newInstanceWithoutConstructor();
        $item = (new ReflectionMethod($service, 'drillDownItem'))->invoke($service, (object) [
            'source_type' => $type, 'source_id' => $sourceId, 'payment_document_id' => $documentId,
            'amount' => '10.00', 'source_date' => '2026-10-01', 'currency' => 'RUB',
            'status' => 'reserved', 'flow_direction' => 'outflow',
        ]);
        self::assertInstanceOf(PlanFactDrillDownItem::class, $item);
        self::assertSame($expected, $item->routeHint);
        self::assertSame($sourceId, $item->sourceId);
    }

    public static function sourceRoutes(): array
    {
        return [
            'standalone reservation cannot open an unrelated document' => ['budget_limit_reservation', 123, null, []],
            'linked reservation opens its document' => ['budget_limit_reservation', 123, 372, [
                'name' => 'admin.payments.documents.show', 'params' => ['id' => 372],
                'api_path' => '/api/v1/admin/payments/documents/372',
            ]],
            'document commitment opens its source' => ['payment_document_commitment', 372, null, [
                'name' => 'admin.payments.documents.show', 'params' => ['id' => 372],
                'api_path' => '/api/v1/admin/payments/documents/372',
            ]],
            'transaction preserves the transaction target' => ['payment_transaction', 81, 372, [
                'name' => 'admin.payments.transactions.show', 'params' => ['id' => 81],
                'api_path' => '/api/v1/admin/payments/transactions/81',
            ]],
            'unknown sources do not guess document ids' => ['unknown', 123, 372, []],
        ];
    }
}
