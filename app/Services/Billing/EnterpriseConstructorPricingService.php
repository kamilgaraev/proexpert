<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\EnterpriseConstructorSelection;

final class EnterpriseConstructorPricingService
{
    public function __construct(
        private readonly ?array $constructorConfig = null,
    ) {
    }

    public function preview(EnterpriseConstructorSelection $selection): array
    {
        $config = $this->constructorConfig ?? config('billing.enterprise_constructor');
        $base = $config['base'];
        $extensionsConfig = $config['extensions'];
        $price = (int) $base['price'];
        $extensions = [];
        $includedUsers = (int) $base['users'];
        $calculatedUsers = max($selection->users, $includedUsers);
        $requiresImplementationProject = $this->requiresImplementationProject($selection);

        if ($calculatedUsers > $includedUsers) {
            $price += (int) $extensionsConfig['users_to_250']['price'];
            $extensions[] = $this->extension('users_to_250', $extensionsConfig['users_to_250']);
        }

        if ($calculatedUsers > 250) {
            $extraChunks = (int) ceil(($calculatedUsers - 250) / 100);
            $extraUsersPrice = $extraChunks * (int) $extensionsConfig['next_100_users']['price'];
            $price += $extraUsersPrice;
            $extensions[] = [
                'key' => 'next_100_users',
                'name' => $extensionsConfig['next_100_users']['label'],
                'label' => $extensionsConfig['next_100_users']['label'],
                'quantity' => $extraChunks,
                'price' => $extraUsersPrice,
            ];
        }

        if ($selection->additionalOrganizations > 0) {
            $additionalOrganizationPrice = $selection->additionalOrganizations
                * (int) $extensionsConfig['additional_organization']['price'];
            $price += $additionalOrganizationPrice;
            $extensions[] = [
                'key' => 'additional_organization',
                'name' => $extensionsConfig['additional_organization']['label'],
                'label' => $extensionsConfig['additional_organization']['label'],
                'quantity' => $selection->additionalOrganizations,
                'price' => $additionalOrganizationPrice,
            ];
        }

        if ($selection->extendedAi) {
            $price += (int) $extensionsConfig['extended_ai']['price'];
            $extensions[] = $this->extension('extended_ai', $extensionsConfig['extended_ai']);
        }

        if ($selection->extraStorageUnits > 0) {
            $storagePrice = $selection->extraStorageUnits * (int) $extensionsConfig['extra_storage_100gb']['price'];
            $price += $storagePrice;
            $extensions[] = [
                'key' => 'extra_storage_100gb',
                'name' => $extensionsConfig['extra_storage_100gb']['label'],
                'label' => $extensionsConfig['extra_storage_100gb']['label'],
                'quantity' => $selection->extraStorageUnits,
                'price' => $storagePrice,
            ];
        }

        if ($selection->prioritySupport) {
            $price += (int) $extensionsConfig['priority_support']['price'];
            $extensions[] = $this->extension('priority_support', $extensionsConfig['priority_support']);
        }

        return [
            'plan_name' => $config['name'],
            'price' => [
                'total' => $price,
                'label' => $this->formatPrice($price),
                'currency' => 'RUB',
                'period' => 'month',
                'period_label' => 'в месяц',
            ],
            'price_label' => $this->formatPrice($price),
            'limits' => [
                'users' => $calculatedUsers,
                'projects' => (int) $base['projects'],
                'organizations' => (int) ($base['organizations'] ?? 1) + $selection->additionalOrganizations,
                'storage_gb' => (int) $base['storage_gb'] + ($selection->extraStorageUnits * 100),
                'ai_requests' => (int) $base['ai_requests']
                    + ($selection->extendedAi ? (int) $extensionsConfig['extended_ai']['ai_requests'] : 0),
                'contractor_invitations' => (int) ($base['contractor_invitations'] ?? 500),
            ],
            'selected_extensions' => $extensions,
            'can_checkout' => ! $requiresImplementationProject,
            'requires_implementation_project' => $requiresImplementationProject,
            'primary_cta' => $requiresImplementationProject
                ? $config['cta']['implementation_project']
                : $config['cta']['standard'],
            'message' => $requiresImplementationProject
                ? trans_message('billing.enterprise_constructor.requires_project')
                : trans_message('billing.enterprise_constructor.standard_preview'),
        ];
    }

    private function requiresImplementationProject(EnterpriseConstructorSelection $selection): bool
    {
        return $selection->users > 250
            || $selection->needsIntegrations
            || $selection->needsMigration
            || $selection->needsSla
            || $selection->moreThan250Users;
    }

    private function extension(string $key, array $extension): array
    {
        return [
            'key' => $key,
            'name' => $extension['label'],
            'label' => $extension['label'],
            'quantity' => 1,
            'price' => (int) $extension['price'],
        ];
    }

    private function formatPrice(int $price): string
    {
        return number_format($price, 0, ',', ' ') . ' ₽ в месяц';
    }
}
