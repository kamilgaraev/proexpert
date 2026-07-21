<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Helpers\PermissionTranslator;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class LegalArchivePermissionTranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $loader = new FileLoader(new Filesystem, dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'lang');
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

    public function test_legal_archive_permissions_are_translated_for_frontend(): void
    {
        $translated = PermissionTranslator::translatePermissionsData([
            'module_permissions' => [
                'legal-archive' => [
                    'legal_archive.view',
                    'legal_archive.create',
                    'legal_archive.update',
                    'legal_archive.archive',
                    'legal_archive.audit.view',
                    'legal_archive.files.view',
                    'legal_archive.files.upload',
                    'legal_archive.files.download',
                    'legal_archive.versions.create',
                    'legal_archive.versions.manage',
                    'legal_archive.editor.edit',
                    'legal_archive.external_access.manage',
                    'legal_archive.security_recovery.manage',
                    'legal_archive.signatures.request',
                    'legal_archive.signatures.view',
                    'legal_archive.signatures.sign',
                    'legal_archive.signatures.verify',
                    'legal_archive.retention.manage',
                    'legal_archive.legal_hold.manage',
                    'legal_archive.workflow.view',
                    'legal_archive.workflow.submit',
                    'legal_archive.workflow.approve',
                    'legal_archive.workflow.reject',
                    'legal_archive.workflow.return',
                    'legal_archive.workflow.reassign',
                    'legal_archive.workflow.cancel',
                    'legal_archive.workflow_templates.manage',
                    'legal_archive.settings.manage',
                ],
            ],
        ]);

        $flattenedValues = json_encode($this->valuesOnly($translated), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame(
            'Редактирование юридических документов во встроенном редакторе',
            $translated['module_permissions']['legal-archive']['legal_archive.editor.edit'],
        );

        $this->assertSame('Юридический архив', $translated['module_groups']['legal-archive']);
        $this->assertSame('Просмотр юридического архива', $translated['module_permissions']['legal-archive']['legal_archive.view']);
        $this->assertSame('Создание версий документов юридического архива', $translated['module_permissions']['legal-archive']['legal_archive.versions.create']);
        $this->assertSame('Скачивание файлов юридического архива', $translated['module_permissions']['legal-archive']['legal_archive.files.download']);
        $this->assertSame('Управление внешним доступом к юридическим документам', $translated['module_permissions']['legal-archive']['legal_archive.external_access.manage']);
        $this->assertSame('Аварийное восстановление управления юридическими документами', $translated['module_permissions']['legal-archive']['legal_archive.security_recovery.manage']);
        $this->assertSame('Подписание юридических документов', $translated['module_permissions']['legal-archive']['legal_archive.signatures.sign']);
        $this->assertSame('Управление запретом удаления документов юридического архива', $translated['module_permissions']['legal-archive']['legal_archive.legal_hold.manage']);
        $this->assertSame('Согласование юридических документов', $translated['module_permissions']['legal-archive']['legal_archive.workflow.approve']);
        $this->assertSame('Отклонение юридических документов', $translated['module_permissions']['legal-archive']['legal_archive.workflow.reject']);
        $this->assertSame('Возврат юридических документов на доработку', $translated['module_permissions']['legal-archive']['legal_archive.workflow.return']);
        $this->assertSame('Настройка маршрутов согласования юридических документов', $translated['module_permissions']['legal-archive']['legal_archive.workflow_templates.manage']);
        $this->assertStringNotContainsString('legal_archive.view', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.versions.create', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.editor.edit', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.archive', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.audit.view', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.files.view', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.versions.manage', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.settings.manage', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.external_access.manage', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.security_recovery.manage', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.signatures.request', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.signatures.view', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.signatures.sign', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.signatures.verify', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.workflow.', $flattenedValues);
    }

    public function test_legal_archive_permissions_are_assigned_to_expected_admin_roles(): void
    {
        $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'RoleDefinitions';
        $webAdmin = json_decode((string) file_get_contents($root.DIRECTORY_SEPARATOR.'admin'.DIRECTORY_SEPARATOR.'web_admin.json'), true, flags: JSON_THROW_ON_ERROR);
        $financeAdmin = json_decode((string) file_get_contents($root.DIRECTORY_SEPARATOR.'admin'.DIRECTORY_SEPARATOR.'finance_admin.json'), true, flags: JSON_THROW_ON_ERROR);
        $viewer = json_decode((string) file_get_contents($root.DIRECTORY_SEPARATOR.'admin'.DIRECTORY_SEPARATOR.'admin_viewer.json'), true, flags: JSON_THROW_ON_ERROR);
        $organizationAdmin = json_decode((string) file_get_contents($root.DIRECTORY_SEPARATOR.'lk'.DIRECTORY_SEPARATOR.'organization_admin.json'), true, flags: JSON_THROW_ON_ERROR);
        $organizationOwner = json_decode((string) file_get_contents($root.DIRECTORY_SEPARATOR.'lk'.DIRECTORY_SEPARATOR.'organization_owner.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach (['legal_archive.files.download', 'legal_archive.external_access.manage', 'legal_archive.signatures.request', 'legal_archive.signatures.view', 'legal_archive.signatures.sign', 'legal_archive.signatures.verify'] as $permission) {
            self::assertContains($permission, $webAdmin['system_permissions']);
            self::assertContains($permission, $webAdmin['module_permissions']['legal-archive']);
            self::assertContains($permission, $financeAdmin['system_permissions']);
            self::assertContains($permission, $financeAdmin['module_permissions']['legal-archive']);
        }

        self::assertContains('legal_archive.files.download', $viewer['system_permissions']);
        self::assertContains('legal_archive.files.download', $viewer['module_permissions']['legal-archive']);
        self::assertNotContains('legal_archive.external_access.manage', $viewer['system_permissions']);
        self::assertNotContains('legal_archive.signatures.request', $viewer['system_permissions']);
        self::assertNotContains('legal_archive.signatures.sign', $viewer['system_permissions']);
        self::assertNotContains('legal_archive.signatures.verify', $viewer['system_permissions']);
        self::assertContains('legal_archive.security_recovery.manage', $webAdmin['system_permissions']);
        self::assertContains('legal_archive.security_recovery.manage', $webAdmin['module_permissions']['legal-archive']);
        self::assertNotContains('legal_archive.security_recovery.manage', $financeAdmin['system_permissions']);
        self::assertNotContains('legal_archive.security_recovery.manage', $viewer['system_permissions']);
        foreach (['legal_archive.archive', 'legal_archive.audit.view', 'legal_archive.files.view', 'legal_archive.versions.manage'] as $permission) {
            self::assertContains($permission, $webAdmin['system_permissions']);
            self::assertContains($permission, $webAdmin['module_permissions']['legal-archive']);
            self::assertContains($permission, $financeAdmin['system_permissions']);
            self::assertContains($permission, $financeAdmin['module_permissions']['legal-archive']);
        }
        self::assertContains('legal_archive.settings.manage', $webAdmin['system_permissions']);
        self::assertContains('legal_archive.settings.manage', $webAdmin['module_permissions']['legal-archive']);
        self::assertNotContains('legal_archive.settings.manage', $financeAdmin['system_permissions']);
        self::assertContains('legal_archive.files.view', $viewer['system_permissions']);
        self::assertContains('legal_archive.files.view', $viewer['module_permissions']['legal-archive']);
        self::assertContains('legal_archive.signatures.view', $viewer['system_permissions']);
        self::assertContains('legal_archive.signatures.view', $viewer['module_permissions']['legal-archive']);

        foreach ([$organizationAdmin, $organizationOwner] as $organizationRole) {
            foreach ($webAdmin['module_permissions']['legal-archive'] as $permission) {
                self::assertContains($permission, $organizationRole['system_permissions']);
                self::assertContains($permission, $organizationRole['module_permissions']['legal-archive']);
            }
        }
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
