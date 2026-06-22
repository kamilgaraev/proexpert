<?php

declare(strict_types=1);

namespace Tests\Unit\AccessRecertification;

use App\Helpers\PermissionTranslator;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class AccessRecertificationPermissionTranslatorTest extends TestCase
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

    public function test_access_recertification_permissions_are_translated_for_role_editor(): void
    {
        $translated = PermissionTranslator::translatePermissionsData([
            'module_permissions' => [
                'access-recertification' => [
                    'access_recertification.campaigns.view',
                    'access_recertification.campaigns.manage',
                    'access_recertification.campaigns.launch',
                    'access_recertification.campaigns.complete',
                    'access_recertification.reviews.view',
                    'access_recertification.reviews.decide',
                    'access_recertification.revocations.execute',
                    'access_recertification.exceptions.approve',
                    'access_recertification.reports.view',
                    'access_recertification.reports.export',
                ],
            ],
        ]);

        $flattenedValues = json_encode($this->valuesOnly($translated), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame('Пересмотр доступов', $translated['module_groups']['access-recertification']);
        $this->assertSame(
            'Просмотр кампаний пересмотра доступов',
            $translated['module_permissions']['access-recertification']['access_recertification.campaigns.view']
        );
        $this->assertSame(
            'Принятие решений по пересмотру доступов',
            $translated['module_permissions']['access-recertification']['access_recertification.reviews.decide']
        );
        $this->assertStringNotContainsString('access_recertification.campaigns.view', $flattenedValues);
        $this->assertStringNotContainsString('access_recertification.reports.export', $flattenedValues);
    }

    private function valuesOnly(mixed $value): array|string
    {
        if (!is_array($value)) {
            return is_string($value) ? $value : '';
        }

        return array_map(fn (mixed $item): array|string => $this->valuesOnly($item), array_values($value));
    }
}
