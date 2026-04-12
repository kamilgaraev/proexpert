<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades;

use App\Enums\BillingModel;
use App\Enums\ModuleType;
use App\Models\OrganizationModuleActivation;
use App\Modules\Contracts\ConfigurableInterface;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\Core\AccessController;

class BrigadesModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Бригады';
    }

    public function getSlug(): string
    {
        return 'brigades';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Контур подбора, найма и управления строительными бригадами для подрядчиков и проектов';
    }

    public function getType(): ModuleType
    {
        return ModuleType::FEATURE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::FREE;
    }

    public function getManifest(): array
    {
        return json_decode((string) file_get_contents(config_path('ModuleList/features/brigades.json')), true) ?? [];
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function upgrade(string $fromVersion): void
    {
    }

    public function canActivate(int $organizationId): bool
    {
        /** @var AccessController $accessController */
        $accessController = app(AccessController::class);

        return $accessController->hasModuleAccess($organizationId, 'organizations')
            && $accessController->hasModuleAccess($organizationId, 'users')
            && $accessController->hasModuleAccess($organizationId, 'project-management');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users', 'project-management'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'brigades.view',
            'brigades.catalog.view',
            'brigades.catalog.moderate',
            'brigades.documents.verify',
            'brigades.requests.view',
            'brigades.requests.create',
            'brigades.requests.close',
            'brigades.responses.view',
            'brigades.responses.approve',
            'brigades.invitations.view',
            'brigades.invitations.create',
            'brigades.invitations.cancel',
            'brigades.assignments.view',
            'brigades.assignments.edit',
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Каталог проверенных бригад для подрядчиков',
            'Саморегистрация и кабинет бригады',
            'Модерация профиля и документов',
            'Запросы подрядчиков на привлечение бригад',
            'Приглашения бригад на проекты и объекты',
            'Отклики бригад на запросы подрядчиков',
            'Назначение бригад на проекты',
            'Отображение активных бригад в проектном контуре',
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_brigades_per_organization' => null,
            'max_members_per_brigade' => 100,
            'max_documents_per_brigade' => 50,
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'catalog' => [
                'self_registration_enabled' => true,
                'moderation_required' => true,
                'show_only_approved' => true,
            ],
            'requests' => [
                'allow_brigade_responses' => true,
                'allow_direct_invitations' => true,
            ],
            'documents' => [
                'verification_required' => true,
                'max_documents_per_brigade' => 50,
            ],
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (
            isset($settings['documents']['max_documents_per_brigade'])
            && (!is_int($settings['documents']['max_documents_per_brigade']) || $settings['documents']['max_documents_per_brigade'] < 1)
        ) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля бригад.');
        }

        $activation = OrganizationModuleActivation::query()
            ->where('organization_id', $organizationId)
            ->whereHas('module', static function ($query): void {
                $query->where('slug', 'brigades');
            })
            ->first();

        if (!$activation) {
            return;
        }

        $activation->update([
            'module_settings' => array_merge($activation->module_settings ?? [], $settings),
        ]);
    }

    public function getSettings(int $organizationId): array
    {
        $activation = OrganizationModuleActivation::query()
            ->where('organization_id', $organizationId)
            ->whereHas('module', static function ($query): void {
                $query->where('slug', 'brigades');
            })
            ->first();

        if (!$activation) {
            return $this->getDefaultSettings();
        }

        return array_merge($this->getDefaultSettings(), $activation->module_settings ?? []);
    }
}
