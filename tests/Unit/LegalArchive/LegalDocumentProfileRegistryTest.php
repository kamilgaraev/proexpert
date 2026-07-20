<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Profiles\LegalDocumentProfileRegistry;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LegalDocumentProfileRegistryTest extends TestCase
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

    public function test_standard_profile_is_loaded_from_catalog(): void
    {
        $registry = new LegalDocumentProfileRegistry(
            static fn (int $organizationId, string $code): ?array => null,
            require dirname(__DIR__, 3).'/config/legal-document-profiles.php',
        );

        $profile = $registry->find(15, 'contract.supply');

        self::assertSame('contract.supply', $profile->code);
        self::assertSame('contract.supply', $profile->baseCode);
        self::assertSame('Договор поставки', $profile->label);
        self::assertTrue($profile->requiresSignature);
        self::assertContains('delivery_terms', $profile->requiredFields);
    }

    public function test_organization_profile_inherits_standard_profile_and_adds_fields(): void
    {
        $registry = new LegalDocumentProfileRegistry(
            static fn (int $organizationId, string $code): ?array => [
                'organization_id' => 15,
                'code' => 'customer.supply-contract',
                'base_code' => 'contract.supply',
                'name' => 'Договор поставки организации',
                'schema' => [
                    'project_code' => [
                        'type' => 'string',
                        'label' => 'Шифр проекта',
                    ],
                ],
                'required_fields' => ['project_code'],
                'required_file_roles' => ['appendix'],
                'requires_signature' => null,
                'workflow_template_id' => null,
                'retention_policy' => 'ten_years',
                'confidentiality_level' => 'restricted',
                'is_active' => true,
                'lock_version' => 3,
            ],
            require dirname(__DIR__, 3).'/config/legal-document-profiles.php',
        );

        $profile = $registry->find(15, 'customer.supply-contract');

        self::assertSame('contract.supply', $profile->baseCode);
        self::assertSame('Договор поставки организации', $profile->label);
        self::assertTrue($profile->requiresSignature);
        self::assertSame(['primary', 'appendix'], $profile->requiredFileRoles);
        self::assertContains('subject', $profile->requiredFields);
        self::assertContains('project_code', $profile->requiredFields);
        self::assertArrayHasKey('price', $profile->schema);
        self::assertArrayHasKey('project_code', $profile->schema);
        self::assertSame(3, $profile->lockVersion);
    }

    public function test_foreign_organization_profile_is_not_available(): void
    {
        $registry = new LegalDocumentProfileRegistry(
            static fn (int $organizationId, string $code): ?array => [
                'organization_id' => 16,
                'code' => $code,
                'base_code' => 'contract.supply',
                'name' => 'Чужой профиль',
                'schema' => [],
                'required_file_roles' => [],
                'is_active' => true,
            ],
            require dirname(__DIR__, 3).'/config/legal-document-profiles.php',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Профиль документа не найден или недоступен');

        $registry->find(15, 'customer.foreign');
    }

    public function test_inactive_organization_profile_is_not_available(): void
    {
        $registry = new LegalDocumentProfileRegistry(
            static fn (int $organizationId, string $code): ?array => [
                'organization_id' => $organizationId,
                'code' => $code,
                'base_code' => 'contract.supply',
                'name' => 'Отключенный профиль',
                'schema' => [],
                'required_file_roles' => [],
                'is_active' => false,
            ],
            require dirname(__DIR__, 3).'/config/legal-document-profiles.php',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Профиль документа не найден или недоступен');

        $registry->find(15, 'customer.inactive');
    }

    public function test_organization_profile_cannot_override_base_field_definition(): void
    {
        $registry = new LegalDocumentProfileRegistry(
            static fn (int $organizationId, string $code): ?array => [
                'organization_id' => $organizationId,
                'code' => $code,
                'base_code' => 'contract.supply',
                'name' => 'Профиль с подменой цены',
                'schema' => [
                    'price' => ['type' => 'string', 'label' => 'Цена текстом'],
                ],
                'required_fields' => [],
                'required_file_roles' => [],
                'is_active' => true,
            ],
            require dirname(__DIR__, 3).'/config/legal-document-profiles.php',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Пользовательский профиль не может изменять поля базового профиля');

        $registry->find(15, 'customer.override-price');
    }

    public function test_organization_profile_with_unknown_base_is_rejected(): void
    {
        $registry = new LegalDocumentProfileRegistry(
            static fn (int $organizationId, string $code): ?array => [
                'organization_id' => $organizationId,
                'code' => $code,
                'base_code' => 'contract.unknown',
                'name' => 'Профиль без основы',
                'schema' => [],
                'required_fields' => [],
                'required_file_roles' => [],
                'is_active' => true,
            ],
            require dirname(__DIR__, 3).'/config/legal-document-profiles.php',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Базовый профиль документа не найден');

        $registry->find(15, 'customer.unknown-base');
    }

    public function test_organization_profile_can_explicitly_disable_inherited_signature_requirement(): void
    {
        $registry = new LegalDocumentProfileRegistry(
            static fn (int $organizationId, string $code): ?array => [
                'organization_id' => $organizationId,
                'code' => $code,
                'base_code' => 'contract.supply',
                'name' => 'Профиль без обязательной подписи',
                'schema' => [],
                'required_fields' => [],
                'required_file_roles' => [],
                'requires_signature' => false,
                'is_active' => true,
            ],
            require dirname(__DIR__, 3).'/config/legal-document-profiles.php',
        );

        self::assertFalse($registry->find(15, 'customer.no-signature')->requiresSignature);
    }

    public function test_null_signature_settings_inherit_channels_and_crypto_formats(): void
    {
        $registry = new LegalDocumentProfileRegistry(
            static fn (int $organizationId, string $code): ?array => [
                'organization_id' => $organizationId,
                'code' => $code,
                'base_code' => 'contract.supply',
                'name' => 'Наследуемый профиль',
                'schema' => [],
                'required_fields' => [],
                'required_file_roles' => [],
                'allowed_signature_kinds' => null,
                'required_signature_kinds' => null,
                'allowed_signature_formats' => null,
                'is_active' => true,
            ],
            require dirname(__DIR__, 3).'/config/legal-document-profiles.php',
        );

        $profile = $registry->find(15, 'customer.inherited-signatures');
        self::assertSame(['paper_original', 'external_electronic', 'provider_electronic'], $profile->allowedSignatureKinds);
        self::assertSame([], $profile->requiredSignatureKinds);
        self::assertSame(['detached_cades', 'embedded_cades', 'xml_dsig'], $profile->allowedSignatureFormats);
    }
}
