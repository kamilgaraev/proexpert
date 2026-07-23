<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\LegalArchiveDictionary;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class LegalArchiveDictionaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $loader = new FileLoader(new Filesystem, dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'lang');
        $translator = new Translator($loader, 'ru');

        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $container->instance('translator', $translator);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_legal_archive_dictionary_returns_user_labels(): void
    {
        $this->assertSame('Договор', LegalArchiveDictionary::label('types', 'contract'));
        $this->assertSame('Действует', LegalArchiveDictionary::label('statuses', 'active'));
        $this->assertSame('Проект', LegalArchiveDictionary::label('link_types', 'project'));
        $this->assertSame('Правовой статус не подтвержден', LegalArchiveDictionary::label('legal_significance_statuses', 'not_confirmed'));
    }

    public function test_legal_archive_dictionary_exposes_required_optional_link_types(): void
    {
        $this->assertSame([
            'project',
            'contract',
            'payment',
            'procurement',
            'act',
            'commercial_proposal',
            'mdm',
            'claim',
            'edo',
            'one_c',
            'other',
        ], LegalArchiveDictionary::values('link_types'));
    }
}
