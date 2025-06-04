<?php

namespace App\Services\Landing;

use App\Repositories\Landing\OrganizationSubscriptionAddonRepository;
use App\Repositories\Landing\OrganizationSubscriptionRepository;
use Illuminate\Support\Facades\Auth;

class OrganizationSubscriptionAddonService
{
    protected $repo;
    protected $subscriptionRepo;

    public function __construct()
    {
        $this->repo = new OrganizationSubscriptionAddonRepository();
        $this->subscriptionRepo = new OrganizationSubscriptionRepository();
    }

    public function getAllAddons()
    {
        return $this->repo->getAllAddons();
    }

    public function getOrganizationAddons($organizationId)
    {
        $subscription = $this->subscriptionRepo->getByOrganizationId($organizationId);
        if (!$subscription) return [];
        return $this->repo->getOrganizationAddons($subscription->id);
    }

    public function attachAddon($organizationId, $addonId)
    {
        $subscription = $this->subscriptionRepo->getByOrganizationId($organizationId);
        if (!$subscription) return null;
        return $this->repo->attachAddon($subscription->id, $addonId);
    }

    public function detachAddon($organizationId, $addonId)
    {
        $subscription = $this->subscriptionRepo->getByOrganizationId($organizationId);
        if (!$subscription) return null;
        return $this->repo->detachAddon($subscription->id, $addonId);
    }
} 