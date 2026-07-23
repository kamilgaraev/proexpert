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

    public function test_draft_fields_can_be_saved_before_all_contract_requisites_are_ready(): void
    {
        $result = (new LegalDocumentProfileValidator)->validate(
            $this->profile(),
            ['price' => '1250.50'],
            enforceRequired: false,
        );

        self::assertSame(['price' => 1250.5], $result);
    }

    public function test_required_string_containing_only_whitespace_is_missing(): void
    {
        $validator = new LegalDocumentProfileValidator;

        try {
            $validator->validate($this->profile(), ['subject' => " \t\n"]);
            self::fail('Ожидалась ошибка обязательного поля');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['Укажите значение поля «Предмет договора»'],
                $exception->errors()['subject'],
            );
        }
    }

    public function test_optional_nullable_values_are_normalized_without_boolean_coercion(): void
    {
        $validator = new LegalDocumentProfileValidator;

        $result = $validator->validate($this->profile(), [
            'subject' => 'Поставка',
            'comment' => '',
            'signed_on' => null,
        ]);

        self::assertNull($result['comment']);
        self::assertNull($result['signed_on']);
    }

    public function test_false_and_zero_are_present_values(): void
    {
        $validator = new LegalDocumentProfileValidator;

        $result = $validator->validate($this->profile(), [
            'subject' => 'Поставка',
            'price' => 0,
            'urgent' => false,
        ]);

        self::assertSame(0.0, $result['price']);
        self::assertFalse($result['urgent']);
    }

    public function test_optional_empty_array_remains_an_empty_array(): void
    {
        $result = (new LegalDocumentProfileValidator)->validate($this->profile(), [
            'subject' => 'Поставка',
            'tags' => [],
        ]);

        self::assertSame([], $result['tags']);
    }

    public function test_obligation_definitions_are_normalized_without_becoming_arbitrary_profile_fields(): void
    {
        $result = (new LegalDocumentProfileValidator)->validate($this->profile(), [
            'subject' => 'Поставка',
            'obligations' => [[
                'title' => '  Передать материалы  ',
                'due_at' => '2026-08-01',
                'amount' => '1250.50',
                'volume' => '10',
                'unit' => ' шт. ',
                'responsible_party' => 'supplier',
                'status' => 'open',
            ]],
        ]);

        self::assertSame([
            'title' => 'Передать материалы',
            'due_at' => '2026-08-01',
            'amount' => 1250.5,
            'volume' => 10.0,
            'unit' => 'шт.',
            'responsible_party' => 'supplier',
            'status' => 'open',
        ], $result['obligations'][0]);
    }

    public function test_obligation_definition_rejects_unknown_or_invalid_values(): void
    {
        $validator = new LegalDocumentProfileValidator;

        try {
            $validator->validate($this->profile(), [
                'subject' => 'Поставка',
                'obligations' => [[
                    'title' => 'Передать материалы',
                    'status' => 'completed',
                    'unexpected' => 'value',
                ]],
            ]);
            self::fail('Ожидалась ошибка некорректного обязательства');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('obligations', $exception->errors());
        }
    }

    public function test_boolean_is_rejected_for_integer_field(): void
    {
        $validator = new LegalDocumentProfileValidator;

        try {
            $validator->validate($this->profile(), [
                'subject' => 'Поставка',
                'installments' => true,
            ]);
            self::fail('Ожидалась ошибка целочисленного поля');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['Проверьте значение поля «Количество этапов»'],
                $exception->errors()['installments'],
            );
        }
    }

    public function test_boolean_string_requires_explicit_schema_representation(): void
    {
        $validator = new LegalDocumentProfileValidator;

        try {
            $validator->validate($this->profile(), [
                'subject' => 'Поставка',
                'strict_boolean' => 'true',
            ]);
            self::fail('Ожидалась ошибка строгого логического поля');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('strict_boolean', $exception->errors());
        }

        $result = $validator->validate($this->profile(), [
            'subject' => 'Поставка',
            'urgent' => 'yes',
        ]);

        self::assertTrue($result['urgent']);
    }

    public function test_enum_accepts_only_declared_value(): void
    {
        $validator = new LegalDocumentProfileValidator;

        self::assertSame(
            'rub',
            $validator->validate($this->profile(), ['subject' => 'Поставка', 'currency' => 'rub'])['currency'],
        );

        try {
            $validator->validate($this->profile(), ['subject' => 'Поставка', 'currency' => 'usd']);
            self::fail('Ожидалась ошибка значения перечисления');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('currency', $exception->errors());
        }
    }

    public function test_nested_value_is_rejected_for_scalar_array_items(): void
    {
        $validator = new LegalDocumentProfileValidator;

        try {
            $validator->validate($this->profile(), [
                'subject' => 'Поставка',
                'tags' => [['nested' => true]],
            ]);
            self::fail('Ожидалась ошибка вложенного значения');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('tags', $exception->errors());
        }
    }

    public function test_array_schema_without_supported_scalar_item_type_is_rejected(): void
    {
        $validator = new LegalDocumentProfileValidator;
        $profile = $this->profileWithSchema([
            'nested' => ['type' => 'array', 'label' => 'Вложенные данные', 'items' => 'array'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Профиль документа содержит неподдерживаемое описание поля');

        $validator->validate($profile, ['nested' => []]);
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
                'urgent' => [
                    'type' => 'boolean',
                    'label' => 'Срочный',
                    'boolean_representations' => ['true' => true, 'yes' => true, 'false' => false, 'no' => false],
                ],
                'strict_boolean' => ['type' => 'boolean', 'label' => 'Строгое значение'],
                'installments' => ['type' => 'integer', 'label' => 'Количество этапов'],
                'signed_on' => ['type' => 'date', 'label' => 'Дата подписания', 'nullable' => true],
                'comment' => ['type' => 'string', 'label' => 'Комментарий', 'nullable' => true],
                'currency' => ['type' => 'enum', 'label' => 'Валюта', 'options' => ['rub', 'eur']],
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

    /** @param array<string, array<string, mixed>> $schema */
    private function profileWithSchema(array $schema): LegalDocumentProfile
    {
        return new LegalDocumentProfile(
            code: 'custom.schema',
            baseCode: 'other.custom',
            label: 'Проверка схемы',
            category: 'other',
            schema: $schema,
            requiredFileRoles: [],
            requiredFields: [],
            requiresSignature: false,
            workflowTemplateId: null,
            retentionPolicy: null,
            confidentialityLevel: 'internal',
            isActive: true,
            lockVersion: 0,
        );
    }
}
