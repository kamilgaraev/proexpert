<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace;

use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferAccepted;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferCancelled;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferDeclined;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferReviewed;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferSent;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferViewed;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceProfilePaused;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceProfilePublished;
use App\BusinessModules\ContractorMarketplace\Domain\Listeners\RecordMarketplaceActivity;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceProfileService;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceHiringOfferService;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceNetworkService;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceRatingService;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceSearchService;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceWorkCategoryService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ContractorMarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContractorMarketplaceModule::class);
        $this->app->singleton(MarketplaceWorkCategoryService::class);
        $this->app->singleton(MarketplaceProfileService::class);
        $this->app->singleton(MarketplaceHiringOfferService::class);
        $this->app->singleton(MarketplaceNetworkService::class);
        $this->app->singleton(MarketplaceRatingService::class);
        $this->app->singleton(MarketplaceSearchService::class);
    }

    public function boot(): void
    {
        Event::listen(MarketplaceProfilePublished::class, [RecordMarketplaceActivity::class, 'handleProfilePublished']);
        Event::listen(MarketplaceProfilePaused::class, [RecordMarketplaceActivity::class, 'handleProfilePaused']);
        Event::listen(MarketplaceHiringOfferSent::class, [RecordMarketplaceActivity::class, 'handleOfferSent']);
        Event::listen(MarketplaceHiringOfferViewed::class, [RecordMarketplaceActivity::class, 'handleOfferViewed']);
        Event::listen(MarketplaceHiringOfferAccepted::class, [RecordMarketplaceActivity::class, 'handleOfferAccepted']);
        Event::listen(MarketplaceHiringOfferDeclined::class, [RecordMarketplaceActivity::class, 'handleOfferDeclined']);
        Event::listen(MarketplaceHiringOfferCancelled::class, [RecordMarketplaceActivity::class, 'handleOfferCancelled']);
        Event::listen(MarketplaceHiringOfferReviewed::class, [RecordMarketplaceActivity::class, 'handleOfferReviewed']);
    }
}
