<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\Helpers\PermissionTranslator;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class CrmPermissionContractTest extends TestCase
{
    private const CRM_PERMISSIONS = [
        'crm.view',
        'crm.analytics.view',
        'crm.amounts.view',
        'crm.companies.view',
        'crm.companies.create',
        'crm.companies.update',
        'crm.companies.archive',
        'crm.companies.restore',
        'crm.contacts.view',
        'crm.contacts.create',
        'crm.contacts.update',
        'crm.contacts.archive',
        'crm.contacts.restore',
        'crm.leads.view',
        'crm.leads.create',
        'crm.leads.update',
        'crm.leads.convert',
        'crm.leads.archive',
        'crm.leads.restore',
        'crm.deals.view',
        'crm.deals.create',
        'crm.deals.update',
        'crm.deals.stage',
        'crm.deals.link',
        'crm.deals.archive',
        'crm.deals.restore',
        'crm.activities.view',
        'crm.activities.create',
        'crm.activities.update',
        'crm.activities.complete',
        'crm.activities.archive',
        'crm.activities.restore',
        'crm.import.preview',
        'crm.import.confirm',
        'crm.merge.view',
        'crm.merge.execute',
        'crm.timeline.view',
    ];

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

    public function test_crm_permissions_are_translated_without_exposing_slugs(): void
    {
        $translated = PermissionTranslator::translatePermissionsData([
            'module_permissions' => [
                'crm' => self::CRM_PERMISSIONS,
            ],
        ]);

        $flattenedValues = json_encode($this->valuesOnly($translated), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame('CRM', $translated['module_groups']['crm']);
        $this->assertSame('Просмотр CRM', $translated['module_permissions']['crm']['crm.view']);
        $this->assertSame('Просмотр сумм в CRM', $translated['module_permissions']['crm']['crm.amounts.view']);
        $this->assertSame('Конвертация лидов CRM', $translated['module_permissions']['crm']['crm.leads.convert']);
        $this->assertSame('Объединение дублей CRM', $translated['module_permissions']['crm']['crm.merge.execute']);

        foreach (self::CRM_PERMISSIONS as $permission) {
            $this->assertStringNotContainsString($permission, $flattenedValues);
        }
    }

    public function test_crm_permissions_are_available_in_expected_system_roles(): void
    {
        $roleRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'RoleDefinitions';
        $webAdmin = $this->readRole($roleRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'web_admin.json');
        $viewer = $this->readRole($roleRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'admin_viewer.json');
        $owner = $this->readRole($roleRoot . DIRECTORY_SEPARATOR . 'lk' . DIRECTORY_SEPARATOR . 'organization_owner.json');
        $organizationAdmin = $this->readRole($roleRoot . DIRECTORY_SEPARATOR . 'lk' . DIRECTORY_SEPARATOR . 'organization_admin.json');

        $this->assertContains('crm.leads.convert', $webAdmin['module_permissions']['crm']);
        $this->assertContains('crm.import.confirm', $organizationAdmin['module_permissions']['crm']);
        $this->assertContains('*', $owner['module_permissions']['crm']);
        $this->assertContains('crm.companies.view', $viewer['module_permissions']['crm']);
        $this->assertNotContains('crm.amounts.view', $viewer['module_permissions']['crm']);
        $this->assertNotContains('crm.merge.execute', $viewer['module_permissions']['crm']);
    }

    private function readRole(string $path): array
    {
        $content = file_get_contents($path);

        $this->assertIsString($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function valuesOnly(array $value): array
    {
        $values = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $values = array_merge($values, $this->valuesOnly($item));
            } else {
                $values[] = $item;
            }
        }

        return $values;
    }
}
