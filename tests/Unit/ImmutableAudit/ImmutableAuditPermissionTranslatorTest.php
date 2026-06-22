<?php

declare(strict_types=1);

namespace Tests\Unit\ImmutableAudit;

use App\Helpers\PermissionTranslator;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class ImmutableAuditPermissionTranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $loader = new FileLoader(new Filesystem(), dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'lang');
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

    public function test_immutable_audit_permissions_are_translated_for_frontend(): void
    {
        $translated = PermissionTranslator::translatePermissionsData([
            'module_permissions' => [
                'immutable_audit' => [
                    'immutable_audit.events.view',
                    'immutable_audit.events.export',
                    'immutable_audit.events.view_sensitive',
                    'immutable_audit.integrity.verify',
                    'immutable_audit.retention.manage',
                ],
            ],
        ]);

        $flattenedValues = json_encode($this->valuesOnly($translated), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame('Неизменяемый аудит', $translated['module_groups']['immutable_audit']);
        $this->assertSame('Просмотр защищенного журнала', $translated['module_permissions']['immutable_audit']['immutable_audit.events.view']);
        $this->assertSame('Проверка целостности защищенного журнала', $translated['module_permissions']['immutable_audit']['immutable_audit.integrity.verify']);
        $this->assertStringNotContainsString('immutable_audit.events.view', $flattenedValues);
        $this->assertStringNotContainsString('immutable_audit.integrity.verify', $flattenedValues);
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
