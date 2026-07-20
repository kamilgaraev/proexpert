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
                    'legal_archive.files.upload',
                    'legal_archive.versions.create',
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
                ],
            ],
        ]);

        $flattenedValues = json_encode($this->valuesOnly($translated), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame('Юридический архив', $translated['module_groups']['legal-archive']);
        $this->assertSame('Просмотр юридического архива', $translated['module_permissions']['legal-archive']['legal_archive.view']);
        $this->assertSame('Создание версий документов юридического архива', $translated['module_permissions']['legal-archive']['legal_archive.versions.create']);
        $this->assertSame('Управление запретом удаления документов юридического архива', $translated['module_permissions']['legal-archive']['legal_archive.legal_hold.manage']);
        $this->assertSame('Согласование юридических документов', $translated['module_permissions']['legal-archive']['legal_archive.workflow.approve']);
        $this->assertSame('Отклонение юридических документов', $translated['module_permissions']['legal-archive']['legal_archive.workflow.reject']);
        $this->assertSame('Возврат юридических документов на доработку', $translated['module_permissions']['legal-archive']['legal_archive.workflow.return']);
        $this->assertSame('Настройка маршрутов согласования юридических документов', $translated['module_permissions']['legal-archive']['legal_archive.workflow_templates.manage']);
        $this->assertStringNotContainsString('legal_archive.view', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.versions.create', $flattenedValues);
        $this->assertStringNotContainsString('legal_archive.workflow.', $flattenedValues);
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
