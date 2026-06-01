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
