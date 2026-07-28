<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportDownloadLinkAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportCatalogAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportDrillDownAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRowsAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportDownloadLinkData;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDownloadLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRowsWindow;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use LogicException;
use Throwable;

final class FakeReportingActions
{
    public readonly GetReportCatalogAction $catalogAction;
    public readonly CreateReportRunAction $createRunAction;
    public readonly GetReportRunAction $getRunAction;
    public readonly GetReportRowsAction $rowsAction;
    public readonly GetReportDrillDownAction $drillDownAction;
    public readonly RetryReportRunAction $retryRunAction;
    public readonly CancelReportRunAction $cancelRunAction;
    public readonly CreateReportExportAction $createExportAction;
    public readonly GetReportExportAction $getExportAction;
    public readonly RetryReportExportAction $retryExportAction;
    public readonly CancelReportExportAction $cancelExportAction;
    public readonly CreateReportDownloadLinkAction $downloadLinkAction;

    public array $calls = [
        'catalog' => [],
        'createRun' => [],
        'getRun' => [],
        'rows' => [],
        'drillDown' => [],
        'retryRun' => [],
        'cancelRun' => [],
        'createExport' => [],
        'getExport' => [],
        'retryExport' => [],
        'cancelExport' => [],
        'downloadLink' => [],
    ];

    private array $exceptions = [];

    public function __construct(private array $returns)
    {
        $this->catalogAction = new class($this) implements GetReportCatalogAction {
            public function __construct(private readonly FakeReportingActions $fake) {}
            public function handle(ReportExecutionContext $context): ReportCatalogView { return $this->fake->catalog($context); }
        };
        $this->createRunAction = new class($this) implements CreateReportRunAction {
            public function __construct(private readonly FakeReportingActions $fake) {}
            public function handle(ReportExecutionContext $context, CreateReportRunData $data, IdempotencyKey $key): ReportRun { return $this->fake->createRun($context, $data, $key); }
        };
        $this->getRunAction = new class($this) implements GetReportRunAction {
            public function __construct(private readonly FakeReportingActions $fake) {}
            public function handle(ReportExecutionContext $context, string $runId): ReportRun { return $this->fake->getRun($context, $runId); }
        };
        $this->rowsAction = new class($this) implements GetReportRowsAction {
            public function __construct(private readonly FakeReportingActions $fake) {}
            public function handle(ReportExecutionContext $context, string $runId, ReportRowsWindow $window): ReportPage { return $this->fake->rows($context, $runId, $window); }
        };
        $this->drillDownAction = new class($this) implements GetReportDrillDownAction {
            public function __construct(private readonly FakeReportingActions $fake) {}
            public function handle(ReportExecutionContext $context, string $runId, ReportDrillDownRequest $request): ReportDrillDownResult { return $this->fake->drillDown($context, $runId, $request); }
        };
        $this->retryRunAction = new class($this) implements RetryReportRunAction {
            public function __construct(private readonly FakeReportingActions $fake) {}
            public function handle(ReportExecutionContext $context, string $runId, IdempotencyKey $key): ReportRun { return $this->fake->retryRun($context, $runId, $key); }
        };
        $this->cancelRunAction = new class($this) implements CancelReportRunAction {
            public function __construct(private readonly FakeReportingActions $fake) {}
            public function handle(ReportExecutionContext $context, string $runId): ReportRun { return $this->fake->cancelRun($context, $runId); }
        };
        $this->createExportAction = new class($this) implements CreateReportExportAction {
            public function __construct(private readonly FakeReportingActions $fake) {}
            public function handle(ReportExecutionContext $context, string $runId, CreateReportExportData $data, IdempotencyKey $key): ReportExport { return $this->fake->createExport($context, $runId, $data, $key); }
        };
        $this->getExportAction = new class($this) implements GetReportExportAction {
            public function __construct(private readonly FakeReportingActions $fake) {}
            public function handle(ReportExecutionContext $context, string $exportId): ReportExport { return $this->fake->getExport($context, $exportId); }
        };
        $this->retryExportAction = new class($this) implements RetryReportExportAction {
            public function __construct(private readonly FakeReportingActions $fake) {}
            public function handle(ReportExecutionContext $context, string $exportId, IdempotencyKey $key): ReportExport { return $this->fake->retryExport($context, $exportId, $key); }
        };
        $this->cancelExportAction = new class($this) implements CancelReportExportAction {
            public function __construct(private readonly FakeReportingActions $fake) {}
            public function handle(ReportExecutionContext $context, string $exportId): ReportExport { return $this->fake->cancelExport($context, $exportId); }
        };
        $this->downloadLinkAction = new class($this) implements CreateReportDownloadLinkAction {
            public function __construct(private readonly FakeReportingActions $fake) {}
            public function handle(ReportExecutionContext $context, CreateReportDownloadLinkData $data): ReportDownloadLink { return $this->fake->downloadLink($context, $data); }
        };
    }

    public function willThrow(string $method, Throwable $exception): void
    {
        $this->assertMethod($method);
        $this->exceptions[$method] = $exception;
    }

    public function catalog(ReportExecutionContext $context): ReportCatalogView
    {
        $result = $this->record('catalog', [$context]);
        assert($result instanceof ReportCatalogView);

        return $result;
    }

    public function createRun(ReportExecutionContext $context, CreateReportRunData $data, IdempotencyKey $key): ReportRun
    {
        $result = $this->record('createRun', [$context, $data, $key]);
        assert($result instanceof ReportRun);

        return $result;
    }

    public function getRun(ReportExecutionContext $context, string $runId): ReportRun
    {
        $result = $this->record('getRun', [$context, $runId]);
        assert($result instanceof ReportRun);

        return $result;
    }

    public function rows(ReportExecutionContext $context, string $runId, ReportRowsWindow $window): ReportPage
    {
        $result = $this->record('rows', [$context, $runId, $window]);
        assert($result instanceof ReportPage);

        return $result;
    }

    public function drillDown(ReportExecutionContext $context, string $runId, ReportDrillDownRequest $request): ReportDrillDownResult
    {
        $result = $this->record('drillDown', [$context, $runId, $request]);
        assert($result instanceof ReportDrillDownResult);

        return $result;
    }

    public function retryRun(ReportExecutionContext $context, string $runId, IdempotencyKey $key): ReportRun
    {
        $result = $this->record('retryRun', [$context, $runId, $key]);
        assert($result instanceof ReportRun);

        return $result;
    }

    public function cancelRun(ReportExecutionContext $context, string $runId): ReportRun
    {
        $result = $this->record('cancelRun', [$context, $runId]);
        assert($result instanceof ReportRun);

        return $result;
    }

    public function createExport(ReportExecutionContext $context, string $runId, CreateReportExportData $data, IdempotencyKey $key): ReportExport
    {
        $result = $this->record('createExport', [$context, $runId, $data, $key]);
        assert($result instanceof ReportExport);

        return $result;
    }

    public function getExport(ReportExecutionContext $context, string $exportId): ReportExport
    {
        $result = $this->record('getExport', [$context, $exportId]);
        assert($result instanceof ReportExport);

        return $result;
    }

    public function retryExport(ReportExecutionContext $context, string $exportId, IdempotencyKey $key): ReportExport
    {
        $result = $this->record('retryExport', [$context, $exportId, $key]);
        assert($result instanceof ReportExport);

        return $result;
    }

    public function cancelExport(ReportExecutionContext $context, string $exportId): ReportExport
    {
        $result = $this->record('cancelExport', [$context, $exportId]);
        assert($result instanceof ReportExport);

        return $result;
    }

    public function downloadLink(ReportExecutionContext $context, CreateReportDownloadLinkData $data): ReportDownloadLink
    {
        $result = $this->record('downloadLink', [$context, $data]);
        assert($result instanceof ReportDownloadLink);

        return $result;
    }

    private function record(string $method, array $arguments): object
    {
        $this->calls[$method][] = $arguments;

        if (isset($this->exceptions[$method])) {
            throw $this->exceptions[$method];
        }

        $result = $this->returns[$method] ?? null;
        if (!is_object($result)) {
            throw new LogicException('No reporting fake return configured for '.$method.'.');
        }

        return $result;
    }

    private function assertMethod(string $method): void
    {
        if (!array_key_exists($method, $this->calls)) {
            throw new LogicException('Unknown reporting fake method.');
        }
    }
}
