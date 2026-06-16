<?php

declare(strict_types=1);

namespace Tests\Unit\Tenders;

use App\Helpers\PermissionTranslator;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class TenderPermissionContractTest extends TestCase
{
    private const TENDER_PERMISSIONS = [
        'tenders.view',
        'tenders.create',
        'tenders.update',
        'tenders.archive',
        'tenders.workflow.analyze',
        'tenders.go_no_go.decide',
        'tenders.workflow.submit',
        'tenders.workflow.result',
        'tenders.workflow.cancel',
        'tenders.amounts.view',
        'tenders.files.upload',
        'tenders.files.delete',
        'tenders.deadlines.manage',
        'tenders.risks.manage',
        'tenders.competitors.manage',
        'tenders.convert.deal',
        'tenders.convert.commercial_proposal',
        'tenders.convert.project',
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

    public function test_tender_permissions_are_translated_without_exposing_slugs(): void
    {
        $translated = PermissionTranslator::translatePermissionsData([
            'module_permissions' => [
                'tenders' => self::TENDER_PERMISSIONS,
            ],
        ]);

        $flattenedValues = json_encode($this->valuesOnly($translated), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame('Тендеры', $translated['module_groups']['tenders']);
        $this->assertSame('Просмотр тендеров', $translated['module_permissions']['tenders']['tenders.view']);
        $this->assertSame('Принятие решения об участии', $translated['module_permissions']['tenders']['tenders.go_no_go.decide']);
        $this->assertSame('Просмотр сумм в тендерах', $translated['module_permissions']['tenders']['tenders.amounts.view']);

        foreach (self::TENDER_PERMISSIONS as $permission) {
            $this->assertStringNotContainsString($permission, $flattenedValues);
        }
    }

    public function test_tender_permissions_are_available_in_expected_roles(): void
    {
        $roleRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'RoleDefinitions';
        $webAdmin = $this->readRole($roleRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'web_admin.json');
        $adminViewer = $this->readRole($roleRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'admin_viewer.json');
        $organizationOwner = $this->readRole($roleRoot . DIRECTORY_SEPARATOR . 'lk' . DIRECTORY_SEPARATOR . 'organization_owner.json');
        $organizationAdmin = $this->readRole($roleRoot . DIRECTORY_SEPARATOR . 'lk' . DIRECTORY_SEPARATOR . 'organization_admin.json');
        $viewer = $this->readRole($roleRoot . DIRECTORY_SEPARATOR . 'lk' . DIRECTORY_SEPARATOR . 'viewer.json');

        $this->assertContains('tenders.workflow.submit', $webAdmin['module_permissions']['tenders']);
        $this->assertContains('tenders.deadlines.manage', $organizationAdmin['module_permissions']['tenders']);
        $this->assertContains('*', $organizationOwner['module_permissions']['tenders']);
        $this->assertSame(['tenders.view'], $adminViewer['module_permissions']['tenders']);
        $this->assertSame(['tenders.view'], $viewer['module_permissions']['tenders']);
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
