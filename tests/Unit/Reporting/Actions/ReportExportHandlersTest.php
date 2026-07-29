<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Actions;

use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\CancelReportExportHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\CreateReportDownloadLinkHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\CreateReportExportHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportExportHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\RetryReportExportHandler;
use App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportDownloadLinkAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportCoordinator;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportDownloadLinkData;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDownloadLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ReportExportHandlersTest extends TestCase
{
    public function test_public_action_contracts_are_exact_and_handlers_expose_one_workflow_method(): void
    {
        $this->assertMethod(
            ReportExportCoordinator::class,
            'create',
            [
                ['context', ReportExecutionContext::class],
                ['source', ReportRunExportSource::class],
                ['data', CreateReportExportData::class],
                ['key', IdempotencyKey::class],
            ],
            ReportExport::class,
        );
        $this->assertWorkflowSurface(ReportExportCoordinator::class, ['create']);

        foreach (self::handlerContracts() as [$handler, $interface, $parameters, $returnType]) {
            self::assertSame([$interface], array_values(class_implements($handler)));
            $this->assertMethod($handler, 'handle', $parameters, $returnType);
            $this->assertWorkflowSurface($handler, ['handle']);
            $reflection = new ReflectionClass($handler);
            self::assertTrue($reflection->isFinal());
            self::assertTrue($reflection->isReadOnly());
        }
    }

    public static function handlerContracts(): array
    {
        return [
            [
                CreateReportExportHandler::class,
                CreateReportExportAction::class,
                [
                    ['context', ReportExecutionContext::class],
                    ['runId', 'string'],
                    ['data', CreateReportExportData::class],
                    ['key', IdempotencyKey::class],
                ],
                ReportExport::class,
            ],
            [
                GetReportExportHandler::class,
                GetReportExportAction::class,
                [
                    ['context', ReportExecutionContext::class],
                    ['exportId', 'string'],
                ],
                ReportExport::class,
            ],
            [
                RetryReportExportHandler::class,
                RetryReportExportAction::class,
                [
                    ['context', ReportExecutionContext::class],
                    ['exportId', 'string'],
                    ['key', IdempotencyKey::class],
                ],
                ReportExport::class,
            ],
            [
                CancelReportExportHandler::class,
                CancelReportExportAction::class,
                [
                    ['context', ReportExecutionContext::class],
                    ['exportId', 'string'],
                ],
                ReportExport::class,
            ],
            [
                CreateReportDownloadLinkHandler::class,
                CreateReportDownloadLinkAction::class,
                [
                    ['context', ReportExecutionContext::class],
                    ['data', CreateReportDownloadLinkData::class],
                ],
                ReportDownloadLink::class,
            ],
        ];
    }

    #[DataProvider('retryableStatusProvider')]
    public function test_retry_rejects_expired_parent_before_coordinator_for_every_retryable_export_status(
        ReportExportStatus $status,
    ): void {
        $context = (new ReportExecutionContextBuilder)->build();
        $export = $this->export($status);
        $exports = $this->createStub(ReportExportStore::class);
        $exports->method('get')->willReturn($export);
        $runs = $this->createStub(ReportRunStore::class);
        $runs->method('exportSource')->willThrowException(
            ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED),
        );
        $coordinator = (new ReflectionClass(ReportExportCoordinator::class))->newInstanceWithoutConstructor();
        $handler = new RetryReportExportHandler($exports, $runs, $coordinator);

        try {
            $handler->handle($context, $export->id, new IdempotencyKey('retry-key-0001'));
            self::fail('Expected expired parent to reject retry.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED, $exception->errorCode);
        }
    }

    public static function retryableStatusProvider(): array
    {
        return [
            'failed' => [ReportExportStatus::FAILED],
            'cancelled' => [ReportExportStatus::CANCELLED],
            'expired' => [ReportExportStatus::EXPIRED],
        ];
    }

    private function export(ReportExportStatus $status): ReportExport
    {
        return new ReportExport(
            '01J00000000000000000000001',
            '01J00000000000000000000000',
            $status,
            new Sha256Hash(str_repeat('d', 64)),
            'pdf',
            ['amount', 'name'],
            new ReportWindowSort('name', ReportSortDirection::ASC),
            'ru-RU',
            new DateTimeZone('Europe/Moscow'),
            null,
            null,
            null,
            null,
            null,
            null,
            new DateTimeImmutable('2026-07-29T09:00:00+00:00'),
            new DateTimeImmutable('2026-07-29T09:01:00+00:00'),
            null,
            new DateTimeImmutable('2026-07-30T09:00:00+00:00'),
            null,
            'reused',
            null,
        );
    }

    private function assertMethod(string $class, string $method, array $parameters, string $returnType): void
    {
        $reflection = new \ReflectionMethod($class, $method);
        self::assertTrue($reflection->isPublic());
        self::assertSame(
            $parameters,
            array_map(
                static fn (\ReflectionParameter $parameter): array => [
                    $parameter->getName(),
                    (string) $parameter->getType(),
                ],
                $reflection->getParameters(),
            ),
        );
        self::assertSame($returnType, (string) $reflection->getReturnType());
    }

    private function assertWorkflowSurface(string $class, array $expected): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class
                    && $method->getName() !== '__construct',
            ),
        );
        sort($methods);
        sort($expected);
        self::assertSame($expected, $methods);
    }
}
