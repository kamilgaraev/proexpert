<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Exceptions\ContractBuilderException;
use App\Services\Contract\ContractDocumentRenderer;
use App\Services\Contract\ContractMoneySpelling;
use App\Services\Contract\ContractVariableDefinitionValidator;
use App\Services\Contract\ContractVariableValueValidator;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class ContractVariablePresentationTest extends TestCase
{
    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $app->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).'/lang'), 'ru'));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_organization_expansion_and_wrapping_preserve_source_and_brand_text(): void
    {
        $renderer = new ContractDocumentRenderer;
        $definition = ['type' => 'text', 'display' => ['presentation' => ['format' => 'organization_full', 'prefix' => 'Сторона: ', 'suffix' => '.', 'letter_case' => 'preserve']]];
        (new ContractVariableDefinitionValidator)->validate($definition);
        $value = 'ООО «Иван ОООСервис»';
        self::assertSame('Сторона: Общество с ограниченной ответственностью «Иван ОООСервис».', $renderer->formatValue($definition, $value));
        self::assertSame('ООО «Иван ОООСервис»', $value);
        self::assertSame('Сторона: ОООСервис.', $renderer->formatValue($definition, 'ОООСервис'));
        self::assertSame('Сторона: Уже полное наименование.', $renderer->formatValue($definition, 'Уже полное наименование'));
        $definition['display']['presentation']['letter_case'] = 'upper';
        self::assertSame('Сторона: ИНДИВИДУАЛЬНЫЙ ПРЕДПРИНИМАТЕЛЬ ИВАН.', $renderer->formatValue($definition, 'ИП Иван'));
    }

    public function test_money_words_cover_inflection_negative_values_and_configurable_minor_units(): void
    {
        self::assertSame('Ноль рублей 00 копеек', ContractMoneySpelling::amount('0', 'RUB'));
        self::assertSame('Один рубль 01 копейка', ContractMoneySpelling::amount('1.01', 'RUB'));
        self::assertSame('Две тысячи два рубля две копейки', ContractMoneySpelling::amount('2002.02', 'RUB', 'words'));
        self::assertSame('Двенадцать рублей 12 копеек', ContractMoneySpelling::amount('12.12', 'RUB'));
        self::assertSame('Минус двадцать один доллар США 21 цент', ContractMoneySpelling::amount('-21.21', 'USD'));
        self::assertSame('Сто евро 00 евроцентов', ContractMoneySpelling::amount('100.0000', 'EUR'));
        $huge = ContractMoneySpelling::amount('123456789012345678901234.99', 'RUB');
        self::assertStringStartsWith('Сто двадцать три секстиллиона', $huge);
        self::assertStringEndsWith('двести тридцать четыре рубля 99 копеек', $huge);
    }

    public function test_presentation_never_changes_calculated_money_and_is_escaped_in_document(): void
    {
        $renderer = new ContractDocumentRenderer;
        $definition = ['type' => 'money', 'display' => ['presentation' => ['format' => 'money_number_words', 'prefix' => '<script>', 'suffix' => '</script>']]];
        $value = ['amount' => '12345.67', 'currency' => 'RUB'];
        self::assertSame($value, (new ContractVariableValueValidator)->validate($definition, $value));
        $id = '11111111-1111-4111-8111-111111111111';
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $id]]]]]];
        $html = $renderer->render($document, [$id => ['id' => $id, 'version' => 1, 'definition' => $definition]], [$id => $value]);
        self::assertStringContainsString('12'."\u{00A0}".'345,67', $html);
        self::assertStringContainsString('(Двенадцать тысяч триста сорок пять рублей 67 копеек)', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function test_precision_is_rejected_before_persisting_without_silent_rounding(): void
    {
        $this->expectException(ContractBuilderException::class);
        (new ContractVariableValueValidator)->validate(['type' => 'money', 'display' => ['presentation' => ['format' => 'money_words']]], ['amount' => '12.345', 'currency' => 'RUB']);
    }

    public function test_text_field_cannot_use_money_presentation(): void
    {
        $this->expectException(ContractBuilderException::class);
        (new ContractVariableDefinitionValidator)->validate(['type' => 'text', 'display' => ['presentation' => ['format' => 'money_words']]]);
    }
}
