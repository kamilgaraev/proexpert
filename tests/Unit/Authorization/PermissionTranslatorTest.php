<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Helpers\PermissionTranslator;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

class PermissionTranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $loader = new FileLoader(new Filesystem(), dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lang');
        $translator = new Translator($loader, 'ru');
        $container->instance('translator', $translator);

        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_custom_role_permissions_are_translated_for_lk_form(): void
    {
        $translated = PermissionTranslator::translatePermissionsData([
            'system_permissions' => [
                'profile.view' => 'Просмотр профиля',
                'organization.view' => 'Просмотр организации',
                'users.view' => 'Просмотр пользователей',
                'roles.view_custom' => 'Просмотр пользовательских ролей',
            ],
            'module_permissions' => [
                'basic_warehouse' => [
                    'warehouse.view',
                    'warehouse.manage_stock',
                    'warehouse.advanced.auto_reorder',
                    'warehouse.advanced.forecasts',
                ],
                'procurement' => [
                    'procurement.purchase_orders.mark_delivery',
                ],
            ],
            'interface_access' => [
                'lk' => 'Личный кабинет',
            ],
        ]);

        $this->assertSame('Просмотр профиля', $translated['system_permissions']['profile.view']);
        $this->assertSame('Просмотр пользователей', $translated['system_permissions']['users.view']);
        $this->assertSame('Склад', $translated['module_groups']['basic_warehouse']);
        $this->assertSame('Просмотр склада', $translated['module_permissions']['basic_warehouse']['warehouse.view']);
        $this->assertSame('Управление остатками склада', $translated['module_permissions']['basic_warehouse']['warehouse.manage_stock']);
        $this->assertSame('Автопополнение склада', $translated['module_permissions']['basic_warehouse']['warehouse.advanced.auto_reorder']);
        $this->assertSame('Заказы поставщикам: отметка доставки', $translated['module_permissions']['procurement']['procurement.purchase_orders.mark_delivery']);
        $this->assertSame('Личный кабинет', $translated['interface_access']['lk']);
    }

    public function test_missing_translation_keys_are_not_exposed_to_frontend(): void
    {
        $translated = PermissionTranslator::translatePermissionsData([
            'system_permissions' => [
                'profile.view' => 'permissions.system.profile.view',
            ],
            'module_permissions' => [
                'basic_warehouse' => ['warehouse.view'],
                'unknown_module' => ['unknown.permission'],
            ],
        ]);

        $flattenedValues = json_encode($this->valuesOnly($translated), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('permissions.system.profile.view', $flattenedValues);
        $this->assertStringNotContainsString('permissions.groups.basic_warehouse', $flattenedValues);
        $this->assertStringNotContainsString('warehouse.view', $flattenedValues);
        $this->assertStringNotContainsString('unknown.permission', $flattenedValues);
    }

    public function test_budgeting_permissions_are_translated_for_frontend(): void
    {
        $translated = PermissionTranslator::translatePermissionsData([
            'module_permissions' => [
                'budgeting' => [
                    'budgeting.budgets.view',
                    'budgeting.budgets.submit',
                    'budgeting.budgets.edit_approved',
                    'budgeting.articles.import',
                    'budgeting.articles.map_1c',
                    'budgeting.limits.override',
                    'budgeting.cash_gap.view',
                    'budgeting.plan_fact.view',
                    'budgeting.plan_fact.export',
                    'budgeting.import.preview',
                    'budgeting.import.commit',
                    'budgeting.audit.view',
                    'budgeting.sync.export',
                ],
            ],
        ]);

        $flattenedValues = json_encode($this->valuesOnly($translated), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame('Бюджетирование', $translated['module_groups']['budgeting']);
        $this->assertSame('Просмотр бюджетов', $translated['module_permissions']['budgeting']['budgeting.budgets.view']);
        $this->assertSame('Отправка бюджетов на согласование', $translated['module_permissions']['budgeting']['budgeting.budgets.submit']);
        $this->assertSame('Корректировка согласованных бюджетов', $translated['module_permissions']['budgeting']['budgeting.budgets.edit_approved']);
        $this->assertSame('Импорт бюджетных статей', $translated['module_permissions']['budgeting']['budgeting.articles.import']);
        $this->assertSame('Сопоставление бюджетных статей с 1С', $translated['module_permissions']['budgeting']['budgeting.articles.map_1c']);
        $this->assertSame('Превышение бюджетных лимитов', $translated['module_permissions']['budgeting']['budgeting.limits.override']);
        $this->assertSame('Просмотр прогноза кассового разрыва', $translated['module_permissions']['budgeting']['budgeting.cash_gap.view']);
        $this->assertSame('Просмотр план-факт анализа бюджета', $translated['module_permissions']['budgeting']['budgeting.plan_fact.view']);
        $this->assertSame('Экспорт план-факт анализа бюджета', $translated['module_permissions']['budgeting']['budgeting.plan_fact.export']);
        $this->assertSame('Предпросмотр импорта бюджета', $translated['module_permissions']['budgeting']['budgeting.import.preview']);
        $this->assertSame('Применение импорта бюджета', $translated['module_permissions']['budgeting']['budgeting.import.commit']);
        $this->assertSame('Просмотр истории изменений бюджета', $translated['module_permissions']['budgeting']['budgeting.audit.view']);
        $this->assertSame('Экспорт данных синхронизации бюджета', $translated['module_permissions']['budgeting']['budgeting.sync.export']);
        $this->assertStringNotContainsString('budgeting.budgets.view', $flattenedValues);
        $this->assertStringNotContainsString('budgeting.limits.override', $flattenedValues);
        $this->assertStringNotContainsString('budgeting.cash_gap.view', $flattenedValues);
        $this->assertStringNotContainsString('budgeting.plan_fact.view', $flattenedValues);
        $this->assertStringNotContainsString('budgeting.import.commit', $flattenedValues);
        $this->assertStringNotContainsString('budgeting.sync.export', $flattenedValues);
    }

    public function test_design_management_permissions_are_translated_for_frontend(): void
    {
        $translated = PermissionTranslator::translatePermissionsData([
            'module_permissions' => [
                'design_management' => [
                    'design-management.view',
                    'design-management.models.upload',
                    'design-management.models.prepare_viewer',
                    'design-management.settings.manage',
                ],
            ],
        ]);

        $flattenedValues = json_encode($this->valuesOnly($translated), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame('ПИР и BIM-модели', $translated['module_groups']['design_management']);
        $this->assertSame('Просмотр ПИР: просмотр', $translated['module_permissions']['design_management']['design-management.view']);
        $this->assertSame('Загрузка IFC-моделей: загрузка', $translated['module_permissions']['design_management']['design-management.models.upload']);
        $this->assertStringNotContainsString('design-management.models.prepare_viewer', $flattenedValues);
        $this->assertStringNotContainsString('design-management.settings.manage', $flattenedValues);
    }

    public function test_warehouse_custody_permissions_are_translated_for_frontend(): void
    {
        $translated = PermissionTranslator::translatePermissionsData([
            'module_permissions' => [
                'basic_warehouse' => [
                    'warehouse.issue_to_responsible',
                    'warehouse.return_from_responsible',
                    'warehouse.view_custody',
                ],
            ],
        ]);

        $this->assertSame('Выдача материалов ответственным', $translated['module_permissions']['basic_warehouse']['warehouse.issue_to_responsible']);
        $this->assertSame('Возврат материалов от ответственных', $translated['module_permissions']['basic_warehouse']['warehouse.return_from_responsible']);
        $this->assertSame('Просмотр остатков у ответственных', $translated['module_permissions']['basic_warehouse']['warehouse.view_custody']);
    }

    public function test_one_c_exchange_retry_permissions_are_translated_for_frontend(): void
    {
        $translated = PermissionTranslator::translatePermissionsData([
            'module_permissions' => [
                'one-c-basic-exchange' => [
                    'one_c_exchange.view',
                    'one_c_exchange.history.view',
                    'one_c_exchange.retry',
                    'one_c_exchange.dead_letter.manage',
                    'one_c_exchange.conflicts.view',
                    'one_c_exchange.conflicts.resolve',
                    'one_c_exchange.profiles.test_connection',
                ],
            ],
        ]);

        $flattenedValues = json_encode($this->valuesOnly($translated), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame('1C: базовый обмен', $translated['module_groups']['one-c-basic-exchange']);
        $this->assertSame('Просмотр обмена с 1C', $translated['module_permissions']['one-c-basic-exchange']['one_c_exchange.view']);
        $this->assertSame('Просмотр журнала обмена с 1C', $translated['module_permissions']['one-c-basic-exchange']['one_c_exchange.history.view']);
        $this->assertSame('Повторная доставка обмена с 1C', $translated['module_permissions']['one-c-basic-exchange']['one_c_exchange.retry']);
        $this->assertSame('Управление ручной проверкой обмена с 1C', $translated['module_permissions']['one-c-basic-exchange']['one_c_exchange.dead_letter.manage']);
        $this->assertSame('Просмотр конфликтов обмена с 1C', $translated['module_permissions']['one-c-basic-exchange']['one_c_exchange.conflicts.view']);
        $this->assertSame('Разрешение конфликтов обмена с 1C', $translated['module_permissions']['one-c-basic-exchange']['one_c_exchange.conflicts.resolve']);
        $this->assertSame('Проверка подключения профилей 1C', $translated['module_permissions']['one-c-basic-exchange']['one_c_exchange.profiles.test_connection']);
        $this->assertStringNotContainsString('one_c_exchange.retry', $flattenedValues);
        $this->assertStringNotContainsString('one_c_exchange.dead_letter.manage', $flattenedValues);
        $this->assertStringNotContainsString('one_c_exchange.conflicts.view', $flattenedValues);
        $this->assertStringNotContainsString('one_c_exchange.conflicts.resolve', $flattenedValues);
        $this->assertStringNotContainsString('one_c_exchange.profiles.test_connection', $flattenedValues);
    }

    private function valuesOnly(array $value): array
    {
        $values = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $values[] = $this->valuesOnly($item);
                continue;
            }

            $values[] = $item;
        }

        return $values;
    }
}
