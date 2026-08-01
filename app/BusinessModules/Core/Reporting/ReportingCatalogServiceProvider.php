<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Catalog\GetReportCatalogHandler;
use App\BusinessModules\Core\Reporting\Application\Catalog\ImmutableReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Application\Catalog\StrictReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportCatalogAction;
use App\BusinessModules\Core\Reporting\Application\SavedViews\ReportSavedViewVersionHasher;
use App\BusinessModules\Core\Reporting\Application\SavedViews\StoredReportSavedViewReferenceResolver;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionCoordinator;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionScheduleCalculator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\InAppReportSubscriptionNotifier;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewReferenceResolver;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewVersionStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionCursorCodec;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryDispatcher;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionEventRecorder;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportWorkspacePreferencesStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Infrastructure\Audit\LogReportSubscriptionEventRecorder;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabasePublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabaseReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabaseReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlCandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportSavedViewCursorCodec;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportSubscriptionCursorCodec;
use App\BusinessModules\Core\Reporting\Infrastructure\Notifications\PersistedInAppReportSubscriptionNotifier;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSavedViewVersionStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSubscriptionStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportWorkspacePreferencesStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\EloquentReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\EloquentReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Queue\LaravelReportSubscriptionDeliveryDispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseRequestRegistryFactory;

final class ReportingCatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProjectReportPublicationReleaseRequestRegistryFactory::class);
        $this->app->singleton(\App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationEligibilityService::class, fn (): \App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationEligibilityService => new \App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationEligibilityService(
            new \App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog,
            new \App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionVersionPolicy,
            new \App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationBindingHasher,
            \App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationAdmissionRequirements::requiredChecksByCode(),
            \App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationAdmissionRequirements::deliveryContractsByCode(),
            (new \App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseArtifactVerifierFactory)->create(),
        ));
        $this->app->singleton(\App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationReleaseArtifactVerifier::class, fn (): \App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationReleaseArtifactVerifier => (new \App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseArtifactVerifierFactory)->create());
        $this->app->singleton(
            LoadedReportManifest::class,
            fn (Application $app): LoadedReportManifest => $app
                ->make(YamlReportManifestLoader::class)
                ->loadManagement(
                    __DIR__.'/resources/management-catalog.v1.yaml',
                    __DIR__.'/resources/management-catalog.v1.schema.json',
                ),
        );
        $this->app->singleton(ReportPublicationRegistry::class, fn (Application $app): EloquentReportPublicationRegistry => new EloquentReportPublicationRegistry(
            $app['db']->connection(),
            $app->make(\App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationEligibilityService::class),
            $app->make(\App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory::class),
            (new \App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseArtifactVerifierFactory)->create(),
        ));
        $this->app->singleton(ReportPublicationFeatureStore::class, fn (Application $app): EloquentReportPublicationFeatureStore => new EloquentReportPublicationFeatureStore(
            $app['db']->connection(),
        ));
        $this->app->singleton(\App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseIngestionService::class);
        $this->app->singleton(ReportDefinitionRegistry::class, DatabasePublishedReportDefinitionRegistry::class);
        $this->app->singleton(
            ReportCatalogMetadataRegistry::class,
            DatabaseReportCatalogMetadataRegistry::class,
        );
        $this->app->singleton(
            ReportSchedulingCapabilityRegistry::class,
            DatabaseReportSchedulingCapabilityRegistry::class,
        );
        $this->app->singleton(GetReportCatalogAction::class, GetReportCatalogHandler::class);
        $this->app->singleton(
            ReportWorkspacePreferencesStore::class,
            EloquentReportWorkspacePreferencesStore::class,
        );
        $this->app->singleton(ReportSavedViewStore::class, EloquentReportSavedViewStore::class);
        $this->app->singleton(ReportSavedViewVersionStore::class, EloquentReportSavedViewVersionStore::class);
        $this->app->singleton(ReportSavedViewVersionHasher::class);
        $this->app->singleton(
            ReportSavedViewReferenceResolver::class,
            StoredReportSavedViewReferenceResolver::class,
        );
        $this->app->singleton(ReportSubscriptionStore::class, EloquentReportSubscriptionStore::class);
        $this->app->singleton(ReportSubscriptionDeliveryStore::class, EloquentReportSubscriptionDeliveryStore::class);
        $this->app->singleton(ReportSubscriptionDeliveryDispatcher::class, LaravelReportSubscriptionDeliveryDispatcher::class);
        $this->app->singleton(
            ReportSubscriptionCursorCodec::class,
            fn (Application $app): SignedReportSubscriptionCursorCodec => new SignedReportSubscriptionCursorCodec(
                (string) config('app.key'),
                $app->make(ReportExecutionClock::class),
            ),
        );
        $this->app->singleton(
            ReportSubscriptionCoordinator::class,
            fn (Application $app): ReportSubscriptionCoordinator => new ReportSubscriptionCoordinator(
                $app->make(ReportSubscriptionStore::class),
                $app->make(ReportSubscriptionDeliveryStore::class),
                $app->make(ReportSubscriptionDeliveryDispatcher::class),
                null,
                $app->make(ReportDefinitionRegistry::class),
                $app->make(ReportSchedulingCapabilityRegistry::class),
                $app->make(ReportSavedViewStore::class),
                $app->make(ReportAccessService::class),
                $app->make(ReportSubscriptionScheduleCalculator::class),
                $app->make(ReportExecutionClock::class),
            ),
        );
        $this->app->singleton(InAppReportSubscriptionNotifier::class, PersistedInAppReportSubscriptionNotifier::class);
        $this->app->singleton(ReportSubscriptionEventRecorder::class, LogReportSubscriptionEventRecorder::class);
        $this->app->singleton(SignedReportSavedViewCursorCodec::class, fn (): SignedReportSavedViewCursorCodec => new SignedReportSavedViewCursorCodec((string) config('app.key')));
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
