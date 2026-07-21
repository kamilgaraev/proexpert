<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentLink;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveDocumentResource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class LegalArchiveDocumentResourceTest extends TestCase
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
        $container->instance('config', new Repository([
            'app' => ['fallback_locale' => 'ru'],
            'legal-document-editor' => ['enabled' => false],
        ]));
        $container->instance('translator', $translator);
        $container->instance('request', Request::create('/'));

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

    public function test_document_resource_contains_card_contract_without_file_path(): void
    {
        $document = new LegalArchiveDocument([
            'organization_id' => 15,
            'title' => 'Договор подряда',
            'document_number' => 'PH-42',
            'document_type' => 'contract',
            'status' => 'active',
            'direction' => 'incoming',
            'source_system' => 'most',
            'counterparty_name' => 'ООО Строй',
            'legal_significance_status' => 'not_confirmed',
            'retention_policy' => 'default_5y',
            'legal_hold' => true,
        ]);
        $document->forceFill(['retention_policy' => 'default_5y', 'legal_hold' => true]);
        $document->id = 42;

        $version = new LegalArchiveDocumentVersion([
            'document_id' => 42,
            'organization_id' => 15,
            'version_number' => '1.0',
            'is_current' => true,
            'status' => 'uploaded',
            'processing_status' => 'quarantine',
            'file_path' => 'org-15/legal-archive/documents/42/versions/file.pdf',
            'original_filename' => 'contract.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'content_hash' => str_repeat('a', 64),
        ]);
        $version->id = 7;

        $link = new LegalArchiveDocumentLink([
            'document_id' => 42,
            'organization_id' => 15,
            'link_type' => 'project',
            'linked_id' => '100',
            'display_name' => 'ЖК Север',
        ]);
        $link->id = 3;

        $document->setRelation('currentVersion', $version);
        $document->setRelation('versions', new Collection([$version]));
        $document->setRelation('links', new Collection([$link]));

        $resolved = (new LegalArchiveDocumentResource($document))->resolve(Request::create('/'));
        $payload = json_decode(json_encode($resolved, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Договор подряда', $payload['title']);
        $this->assertSame('Договор', $payload['document_type_label']);
        $this->assertSame('Действует', $payload['status_label']);
        $this->assertSame('Входящий', $payload['direction_label']);
        $this->assertSame('Правовой статус не подтвержден', $payload['legal_significance_status_label']);
        $this->assertTrue($payload['retention']['legal_hold']);
        $this->assertFalse($payload['editor']['enabled']);
        $this->assertSame('1.0', $payload['current_version']['version_number']);
        $this->assertSame('На проверке безопасности', $payload['current_version']['processing_status_label']);
        $this->assertSame('Проект', $payload['links'][0]['link_type_label']);
        $this->assertArrayNotHasKey('file_path', $payload['current_version']);
    }

    public function test_document_resource_exposes_enabled_editor_only_for_supported_driver(): void
    {
        app('config')->set('legal-document-editor.enabled', true);
        app('config')->set('legal-document-editor.driver', 'onlyoffice');

        $document = new LegalArchiveDocument(['document_type' => 'contract']);
        $document->id = 42;

        $enabled = (new LegalArchiveDocumentResource($document))->resolve(Request::create('/'));
        $this->assertTrue($enabled['editor']['enabled']);

        app('config')->set('legal-document-editor.driver', 'unsupported');
        $unsupported = (new LegalArchiveDocumentResource($document))->resolve(Request::create('/'));
        $this->assertFalse($unsupported['editor']['enabled']);
    }
}
