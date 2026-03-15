<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\VideoMonitoring;

use App\Enums\BillingModel;
use App\Enums\ModuleType;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Modules\Contracts\BillableInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\Core\BillingEngine;

class VideoMonitoringModule implements ModuleInterface, BillableInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Видеонаблюдение';
    }

    public function getSlug(): string
    {
        return 'video-monitoring';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Подключение камер на объектах, live-статусы и просмотр потоков внутри карточки проекта.';
    }

    public function getType(): ModuleType
    {
        return ModuleType::FEATURE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::SUBSCRIPTION;
    }

    public function getManifest(): array
    {
        return json_decode(
            file_get_contents(config_path('ModuleList/features/video-monitoring.json')),
            true
        );
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
        $accessController = app(\App\Modules\Core\AccessController::class);

        return $accessController->hasModuleAccess($organizationId, 'project-management');
    }

    public function getDependencies(): array
    {
        return [
            'organizations',
            'users',
            'project-management',
        ];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'video_monitoring.view',
            'video_monitoring.connect',
            'video_monitoring.edit',
            'video_monitoring.delete',
            'video_monitoring.watch_live',
            'video_monitoring.events.view',
            'video_monitoring.settings.manage',
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Подключение IP-камер и RTSP-источников к проекту',
            'Отображение списка камер и статусов прямо в карточке проекта',
            'Проверка доступности камеры перед сохранением',
            'Live-ссылки и карточка просмотра внутри админки',
            'Журнал событий по состояниям камер',
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_cameras' => 1,
            'max_live_viewers' => 1,
            'allow_archive' => false,
            'allow_webrtc' => false,
            'allow_multiview' => false,
        ];
    }

    public function getPrice(): float
    {
        return 0.0;
    }

    public function getCurrency(): string
    {
        return 'RUB';
    }

    public function getDurationDays(): int
    {
        return 30;
    }

    public function getPricingConfig(): array
    {
        return [
            'base_price' => 0,
            'currency' => 'RUB',
            'included_in_plans' => ['starter', 'business', 'profi', 'enterprise'],
            'duration_days' => 30,
            'trial_days' => 7,
        ];
    }

    public function calculateCost(int $organizationId): float
    {
        return $this->getPrice();
    }

    public function canAfford(int $organizationId): bool
    {
        $organization = Organization::find($organizationId);

        if (!$organization) {
            return false;
        }

        $billingEngine = app(BillingEngine::class);
        $module = Module::where('slug', $this->getSlug())->first();

        return $module ? $billingEngine->canAfford($organization, $module) : false;
    }

    public function getDefaultSettings(): array
    {
        return [
            'max_cameras' => 1,
            'max_live_viewers' => 1,
            'default_transport' => 'tcp',
            'allow_custom_playback_url' => true,
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['max_cameras']) && (!is_int($settings['max_cameras']) || $settings['max_cameras'] < 1)) {
            return false;
        }

        if (isset($settings['max_live_viewers']) && (!is_int($settings['max_live_viewers']) || $settings['max_live_viewers'] < 1)) {
            return false;
        }

        if (isset($settings['default_transport']) && !in_array($settings['default_transport'], ['tcp', 'udp', 'http', 'https'], true)) {
            return false;
        }

        if (isset($settings['allow_custom_playback_url']) && !is_bool($settings['allow_custom_playback_url'])) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля видеонаблюдения');
        }

        $activation = OrganizationModuleActivation::query()
            ->where('organization_id', $organizationId)
            ->whereHas('module', function ($query) {
                $query->where('slug', $this->getSlug());
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
            ->whereHas('module', function ($query) {
                $query->where('slug', $this->getSlug());
            })
            ->first();

        return array_merge(
            $this->getDefaultSettings(),
            $activation?->module_settings ?? []
        );
    }
}
