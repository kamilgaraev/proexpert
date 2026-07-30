<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\CurrentReportAbacEvaluator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCatalog;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorResponseFactory;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelCurrentReportAbacEvaluator;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportScopedResourceAuthorizerRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\ProductionReportScopedResourceAuthorizers;
use App\BusinessModules\Core\Reporting\Infrastructure\Audit\OutboxReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Infrastructure\Clock\SystemReportExecutionClock;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportCursorCodec;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelCurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportAuditIntentStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Registry\ProductionCandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Registry\ProductionReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Infrastructure\Registry\ProductionReportDefinitionRegistry;
use Illuminate\Support\ServiceProvider;

final class ReportingContractsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportErrorCatalog::class);
        $this->app->singleton(ReportErrorResponseFactory::class);
        $this->app->singleton(ReportAccessService::class);
        $this->app->singleton(ReportExecutionContextFactory::class);
        $this->app->singleton(CandidateReportDefinitionRegistry::class, ProductionCandidateReportDefinitionRegistry::class);
        $this->app->singleton(ReportDefinitionBindingAssembler::class, ProductionReportDefinitionBindingAssembler::class);
        $this->app->singleton(ReportDefinitionRegistry::class, ProductionReportDefinitionRegistry::class);
        $this->app->singleton(ReportRunStore::class, EloquentReportRunStore::class);
        $this->app->singleton(ReportExportStore::class, EloquentReportExportStore::class);
        $this->app->singleton(ReportTransitionAudit::class, OutboxReportTransitionAudit::class);
        $this->app->singleton(ReportAuditIntentStore::class, EloquentReportAuditIntentStore::class);
        $this->app->singleton(ReportDispatchIntentStore::class, EloquentReportDispatchIntentStore::class);
        $this->app->singleton(CurrentReportAbacEvaluator::class, LaravelCurrentReportAbacEvaluator::class);
        $this->app->singleton(
            LaravelReportScopedResourceAuthorizerRegistry::class,
            fn (): LaravelReportScopedResourceAuthorizerRegistry => new LaravelReportScopedResourceAuthorizerRegistry(
                $this->app->make(ProductionReportScopedResourceAuthorizers::class)->handlers(),
            ),
        );
        $this->app->singleton(CurrentReportScopeAuthorizer::class, LaravelCurrentReportScopeAuthorizer::class);
        $this->app->singleton(ReportExecutionClock::class, SystemReportExecutionClock::class);
        $this->app->singleton(
            ReportAuthorizationSubjectReader::class,
            EloquentReportAuthorizationSubjectReader::class,
        );
        $this->app->singleton(SignedReportCursorCodec::class, function (): SignedReportCursorCodec {
            $key = (string) config('app.key');

            return new SignedReportCursorCodec(
                ['application-v1' => $key],
                'application-v1',
                $this->app->make(ReportExecutionClock::class),
            );
        });
        $this->app->singleton(CsvReportExportRenderer::class, static fn (): CsvReportExportRenderer => new CsvReportExportRenderer());
        $this->app->singleton(XlsxReportExportRenderer::class, static fn (): XlsxReportExportRenderer => new XlsxReportExportRenderer());
        $this->app->when(EloquentReportRunStore::class)
            ->needs('$runTtlSeconds')
            ->give(86400);
        $this->app->when(EloquentReportRunStore::class)
            ->needs('$pollAfterMs')
            ->give(1000);
        $this->app->when(EloquentReportExportStore::class)
            ->needs('$exportTtlSeconds')
            ->give(86400);
        $this->app->when(EloquentReportExportStore::class)
            ->needs('$pollAfterMs')
            ->give(1000);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
