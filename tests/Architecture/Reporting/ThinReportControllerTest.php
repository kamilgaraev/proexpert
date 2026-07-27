<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportFormRequest;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ThinReportControllerTest extends TestCase
{
    #[DataProvider('actionSignatureProvider')]
    public function test_action_ports_expose_only_the_frozen_handle_signature(
        string $interface,
        array $parameters,
        string $returnType,
    ): void {
        $reflection = new ReflectionClass($interface);

        self::assertTrue($reflection->isInterface());
        self::assertSame(['handle'], array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(),
        ));

        $method = $reflection->getMethod('handle');
        self::assertSame($parameters, array_map(
            static fn ($parameter): string => (string) $parameter->getType(),
            $method->getParameters(),
        ));
        self::assertSame($returnType, (string) $method->getReturnType());
    }

    #[DataProvider('controllerMethodProvider')]
    public function test_controller_endpoints_accept_one_reporting_request_and_return_json(
        string $controller,
        string $method,
        string $request,
    ): void {
        $reflection = new ReflectionMethod($controller, $method);
        $parameters = $reflection->getParameters();

        self::assertCount($method === 'store' && str_ends_with($controller, 'ReportRunController') ? 2 : 1, $parameters);
        self::assertSame($request, (string) $parameters[0]->getType());
        self::assertTrue(is_subclass_of($request, ReportFormRequest::class));
        self::assertSame(JsonResponse::class, (string) $reflection->getReturnType());

        $constructor = (new ReflectionClass($controller))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(
            ReportExecutionContextFactory::class,
            (string) $constructor->getParameters()[0]->getType(),
        );
    }

    public function test_controllers_contain_no_data_access_dispatch_or_manual_json_response(): void
    {
        foreach (glob(dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/Http/Admin/Controllers/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            self::assertDoesNotMatchRegularExpression(
                '/\b(DB|Model|FileService|QueryBuilder)\b|->transaction\(|->dispatch\(|\bdispatch\(|response\(\)->json\(/',
                $source,
                $file,
            );
            self::assertDoesNotMatchRegularExpression(
                '/\b(can|authorize|hasPermission|permission)\s*\(/i',
                $source,
                $file,
            );
        }
    }

    public static function actionSignatureProvider(): array
    {
        $root = 'App\\BusinessModules\\Core\\Reporting\\';
        $context = $root.'Domain\\DTO\\ReportExecutionContext';

        return [
            'catalog' => [$root.'Application\\Contracts\\GetReportCatalogAction', [$context], $root.'Domain\\DTO\\ReportCatalogView'],
            'create run' => [$root.'Application\\Contracts\\CreateReportRunAction', [$context, $root.'Application\\Input\\CreateReportRunData', $root.'Domain\\ValueObjects\\IdempotencyKey'], $root.'Domain\\DTO\\ReportRun'],
            'get run' => [$root.'Application\\Contracts\\GetReportRunAction', [$context, 'string'], $root.'Domain\\DTO\\ReportRun'],
            'rows' => [$root.'Application\\Contracts\\GetReportRowsAction', [$context, 'string', $root.'Domain\\DTO\\ReportRowsWindow'], $root.'Domain\\DTO\\ReportPage'],
            'drill-down' => [$root.'Application\\Contracts\\GetReportDrillDownAction', [$context, 'string', $root.'Domain\\DTO\\ReportDrillDownRequest'], $root.'Domain\\DTO\\ReportDrillDownResult'],
            'retry run' => [$root.'Application\\Contracts\\RetryReportRunAction', [$context, 'string'], $root.'Domain\\DTO\\ReportRun'],
            'cancel run' => [$root.'Application\\Contracts\\CancelReportRunAction', [$context, 'string'], $root.'Domain\\DTO\\ReportRun'],
            'create export' => [$root.'Application\\Contracts\\CreateReportExportAction', [$context, 'string', $root.'Application\\Input\\CreateReportExportData', $root.'Domain\\ValueObjects\\IdempotencyKey'], $root.'Domain\\DTO\\ReportExport'],
            'get export' => [$root.'Application\\Contracts\\GetReportExportAction', [$context, 'string'], $root.'Domain\\DTO\\ReportExport'],
            'retry export' => [$root.'Application\\Contracts\\RetryReportExportAction', [$context, 'string'], $root.'Domain\\DTO\\ReportExport'],
            'cancel export' => [$root.'Application\\Contracts\\CancelReportExportAction', [$context, 'string'], $root.'Domain\\DTO\\ReportExport'],
            'download' => [$root.'Application\\Contracts\\CreateReportDownloadLinkAction', [$context, $root.'Application\\Input\\CreateReportDownloadLinkData'], $root.'Domain\\DTO\\ReportDownloadLink'],
        ];
    }

    public static function controllerMethodProvider(): array
    {
        $controllers = 'App\\BusinessModules\\Core\\Reporting\\Http\\Admin\\Controllers\\';
        $requests = 'App\\BusinessModules\\Core\\Reporting\\Http\\Admin\\Requests\\';

        return [
            [$controllers.'ReportCatalogController', '__invoke', $requests.'GetReportCatalogRequest'],
            [$controllers.'ReportRunController', 'store', $requests.'CreateReportRunRequest'],
            [$controllers.'ReportRunController', 'show', $requests.'ReportRunRouteRequest'],
            [$controllers.'ReportRunController', 'retry', $requests.'ReportRunRouteRequest'],
            [$controllers.'ReportRunController', 'cancel', $requests.'ReportRunRouteRequest'],
            [$controllers.'ReportRowsController', '__invoke', $requests.'GetReportRowsRequest'],
            [$controllers.'ReportDrillDownController', '__invoke', $requests.'CreateReportDrillDownRequest'],
            [$controllers.'ReportExportController', 'store', $requests.'CreateReportExportRequest'],
            [$controllers.'ReportExportController', 'show', $requests.'ReportExportRouteRequest'],
            [$controllers.'ReportExportController', 'retry', $requests.'ReportExportRouteRequest'],
            [$controllers.'ReportExportController', 'cancel', $requests.'ReportExportRouteRequest'],
            [$controllers.'ReportExportController', 'downloadLink', $requests.'CreateReportDownloadLinkRequest'],
        ];
    }
}
