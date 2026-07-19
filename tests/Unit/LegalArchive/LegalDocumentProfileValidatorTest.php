<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Profiles\LegalDocumentProfile;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileValidator;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LegalDocumentProfileValidatorTest extends TestCase
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
        $container->instance('validator', new Factory($translator, $container));

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

    public function test_values_are_normalized_according_to_declarative_schema(): void
    {
        $validator = new LegalDocumentProfileValidator;
        $profile = $this->profile();

        $result = $validator->validate($profile, [
            'subject' => '  Поставка арматуры  ',
            'price' => '1250.50',
            'urgent' => 'true',
            'signed_on' => '2026-07-19',
            'tags' => [' основной ', 'срочный'],
        ]);

        self::assertSame('Поставка арматуры', $result['subject']);
        self::assertSame(1250.5, $result['price']);
        self::assertTrue($result['urgent']);
        self::assertSame('2026-07-19', $result['signed_on']);
        self::assertSame(['основной', 'срочный'], $result['tags']);
    }

    public function test_missing_required_field_returns_human_readable_translated_error(): void
    {
        $validator = new LegalDocumentProfileValidator;

        try {
            $validator->validate($this->profile(), ['price' => 500]);
            self::fail('Ожидалась ошибка обязательного поля');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['Укажите значение поля «Предмет договора»'],
                $exception->errors()['subject'],
            );
        }
    }

    public function test_unknown_field_is_rejected(): void
    {
        $validator = new LegalDocumentProfileValidator;

        try {
            $validator->validate($this->profile(), [
                'subject' => 'Поставка',
                'unexpected' => 'value',
            ]);
            self::fail('Ожидалась ошибка неизвестного поля');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['Поле «unexpected» не предусмотрено профилем документа'],
                $exception->errors()['unexpected'],
            );
        }
    }

    public function test_executable_or_unsupported_schema_rule_is_rejected(): void
    {
        $validator = new LegalDocumentProfileValidator;
        $profile = new LegalDocumentProfile(
            code: 'custom.unsafe',
            baseCode: 'other.custom',
            label: 'Небезопасный профиль',
            category: 'other',
            schema: [
                'command' => [
                    'type' => 'callback',
                    'label' => 'Команда',
                    'handler' => static fn (): bool => true,
                ],
            ],
            requiredFileRoles: [],
            requiredFields: [],
            requiresSignature: false,
            workflowTemplateId: null,
            retentionPolicy: null,
            confidentialityLevel: 'internal',
            isActive: true,
            lockVersion: 0,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Профиль документа содержит неподдерживаемое описание поля');

        $validator->validate($profile, ['command' => 'run']);
    }

    private function profile(): LegalDocumentProfile
    {
        return new LegalDocumentProfile(
            code: 'contract.test',
            baseCode: 'contract.test',
            label: 'Тестовый договор',
            category: 'contract',
            schema: [
                'subject' => ['type' => 'string', 'label' => 'Предмет договора'],
                'price' => ['type' => 'number', 'label' => 'Цена'],
                'urgent' => ['type' => 'boolean', 'label' => 'Срочный'],
                'signed_on' => ['type' => 'date', 'label' => 'Дата подписания'],
                'tags' => ['type' => 'array', 'label' => 'Метки', 'items' => 'string'],
            ],
            requiredFileRoles: ['primary'],
            requiredFields: ['subject'],
            requiresSignature: true,
            workflowTemplateId: null,
            retentionPolicy: 'five_years',
            confidentialityLevel: 'internal',
            isActive: true,
            lockVersion: 0,
        );
    }
}
