<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\CommercialAccountStatus;
use App\Enums\Billing\CommercialOfferType;
use App\Enums\Billing\PackageAccessSource;
use App\Enums\Billing\PackageSubscriptionStatus;
use App\Exceptions\BusinessLogicException;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationPackageTrialUsage;
use App\Modules\Core\AccessController;
use App\Services\Modules\PackageCatalogService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

use function trans_message;

class PackageTrialService
{
    public function __construct(
        private readonly PackageCatalogService $packageCatalog,
        private readonly AccessController $accessController,
    ) {}

    public function start(int $organizationId, string $packageSlug): OrganizationPackageSubscription
    {
        if ($this->packageCatalog->package($packageSlug) === null) {
            throw new BusinessLogicException(trans_message('landing.packages.trial_package_not_found'), 404);
        }

        try {
            $subscription = DB::transaction(function () use ($organizationId, $packageSlug): OrganizationPackageSubscription {
                $organization = Organization::query()
                    ->whereKey($organizationId)
                    ->lockForUpdate()
                    ->first();

                if ($organization === null) {
                    throw new BusinessLogicException(trans_message('landing.organization_not_found'), 404);
                }

                if ($this->trialWasUsed($organizationId, $packageSlug)) {
                    throw new BusinessLogicException(trans_message('landing.packages.trial_already_used'), 409);
                }

                $account = OrganizationCommercialAccount::query()->firstOrCreate(
                    ['organization_id' => $organizationId],
                    [
                        'status' => CommercialAccountStatus::Free,
                        'offer_type' => CommercialOfferType::Packages,
                        'quote_version' => (int) config('commercial_offers.quote_version', 1),
                        'auto_renew_enabled' => false,
                    ],
                );

                $startedAt = CarbonImmutable::now();
                $endsAt = $startedAt->addHours($this->trialHours());

                OrganizationPackageTrialUsage::query()->create([
                    'organization_id' => $organizationId,
                    'package_slug' => $packageSlug,
                    'started_at' => $startedAt,
                    'ends_at' => $endsAt,
                ]);

                return OrganizationPackageSubscription::query()->create([
                    'organization_id' => $organizationId,
                    'commercial_account_id' => $account->id,
                    'package_slug' => $packageSlug,
                    'status' => PackageSubscriptionStatus::Trialing,
                    'access_source' => PackageAccessSource::Trial,
                    'price_paid' => 0,
                    'current_period_start_at' => null,
                    'current_period_end_at' => null,
                    'trial_started_at' => $startedAt,
                    'trial_ends_at' => $endsAt,
                    'cancel_at' => null,
                    'canceled_at' => null,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                throw new BusinessLogicException(
                    trans_message('landing.packages.trial_already_used'),
                    409,
                    $exception,
                );
            }

            throw $exception;
        }

        $this->accessController->clearAccessCache($organizationId);

        return $subscription;
    }

    private function trialWasUsed(int $organizationId, string $packageSlug): bool
    {
        return OrganizationPackageTrialUsage::query()
            ->where('organization_id', $organizationId)
            ->where('package_slug', $packageSlug)
            ->exists()
            || OrganizationPackageSubscription::query()
                ->where('organization_id', $organizationId)
                ->where('package_slug', $packageSlug)
                ->exists();
    }

    private function trialHours(): int
    {
        return (int) config('commercial_offers.trial_hours', 72);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true);
    }
}
