<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting;

use App\BusinessModules\Core\Reporting\Application\Catalog\ImmutableReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Application\Catalog\StrictReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\PublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlCandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class ReportingCatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            LoadedReportManifest::class,
            fn (Application $app): LoadedReportManifest => $app
                ->make(YamlReportManifestLoader::class)
                ->loadManagement(
                    __DIR__.'/resources/management-catalog.v1.yaml',
                    __DIR__.'/resources/management-catalog.v1.schema.json',
                ),
        );
        $this->app->singleton(
            ReportDefinitionRegistry::class,
            PublishedReportDefinitionRegistry::class,
        );
        $this->app->singleton(
            CandidateReportDefinitionRegistry::class,
            YamlCandidateReportDefinitionRegistry::class,
        );
        $this->app->singleton(
            ReportDefinitionBindingAssembler::class,
            ImmutableReportDefinitionBindingAssembler::class,
        );
        $this->app->singleton(
            ReportDefinitionCandidateValidator::class,
            StrictReportDefinitionCandidateValidator::class,
        );
        $this->app->singleton(
            ReportDefinitionBindingMap::class,
            fn (Application $app): ReportDefinitionBindingMap => $app
                ->make(ReportDefinitionBindingAssembler::class)
                ->assemble($app->make(ReportDefinitionRegistry::class)),
        );
    }
}
