<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AccessRecertification;

use App\Enums\BillingModel;
use App\Enums\ModuleType;
use App\Modules\Contracts\ModuleInterface;
use RuntimeException;

final class AccessRecertificationModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Пересмотр доступов';
    }

    public function getSlug(): string
    {
        return 'access_recertification';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Кампании регулярного пересмотра пользовательских ролей и evidence для аудита';
    }

    public function getType(): ModuleType
    {
        return ModuleType::CORE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::FREE;
    }

    public function getManifest(): array
    {
        $manifestPath = config_path('ModuleList/core/access-recertification.json');

        if (!file_exists($manifestPath)) {
            return [];
        }

        return json_decode(file_get_contents($manifestPath), true) ?? [];
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
        throw new RuntimeException('Системный модуль пересмотра доступов нельзя удалить');
    }

    public function upgrade(string $fromVersion): void
    {
    }

    public function canActivate(int $organizationId): bool
    {
        return true;
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'access_recertification.campaigns.view' => 'Просмотр кампаний пересмотра доступов',
            'access_recertification.campaigns.manage' => 'Управление кампаниями пересмотра доступов',
            'access_recertification.campaigns.launch' => 'Запуск кампаний пересмотра доступов',
            'access_recertification.campaigns.complete' => 'Завершение кампаний пересмотра доступов',
            'access_recertification.reviews.view' => 'Просмотр проверок доступов',
            'access_recertification.reviews.decide' => 'Принятие решений по пересмотру доступов',
            'access_recertification.revocations.execute' => 'Исполнение отзыва доступов',
            'access_recertification.exceptions.approve' => 'Согласование исключений по доступам',
            'access_recertification.reports.view' => 'Просмотр отчетов по пересмотру доступов',
            'access_recertification.reports.export' => 'Экспорт evidence по пересмотру доступов',
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Кампании пересмотра доступов по ролям и пользователям',
            'Назначение ответственных проверяющих',
            'Решения: подтвердить, отозвать или оформить исключение',
            'Задачи на отзыв доступа',
            'Evidence export для аудита',
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_campaign_items' => 50000,
            'max_export_rows' => 50000,
        ];
    }
}
