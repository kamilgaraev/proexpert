<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use App\BusinessModules\Features\Procurement\Services\IncomingUpdXmlValidator;
use PHPUnit\Framework\TestCase;

final class IncomingUpdXmlValidatorTest extends TestCase
{
    private const FILE_ID = 'ON_NSCHFDOPPR_2BM-7712345678-771201001-20260904-1_2BM-1654321098-165401001-20260904-1_20260904_1';

    public function test_validates_and_normalizes_incoming_upd_5_03(): void
    {
        $result = (new IncomingUpdXmlValidator)->validate(
            $this->validUpdXml(),
            self::FILE_ID.'.xml',
        );

        self::assertTrue($result->isValid());
        self::assertSame([], $result->errors);
        self::assertSame('5.03', $result->formatVersion);
        self::assertSame('ДОП', $result->function);
        self::assertSame('УПД-2026-1', $result->number);
        self::assertSame('04.09.2026', $result->date);
        self::assertSame('7712345678', $result->seller['inn']);
        self::assertSame('1654321098', $result->buyer['inn']);
        self::assertSame('643', $result->currencyCode);
        self::assertSame('Цемент М500, мешок 50 кг', $result->items[0]['name']);
        self::assertSame('100', $result->items[0]['quantity']);
        self::assertSame('1800.00', $result->totals['with_vat']);
    }

    public function test_rejects_document_type_declaration_before_parsing(): void
    {
        $xml = str_replace(
            '<Файл ',
            '<!DOCTYPE Файл [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><Файл ',
            $this->validUpdXml(),
        );

        $result = (new IncomingUpdXmlValidator)->validate($xml, self::FILE_ID.'.xml');

        self::assertFalse($result->isValid());
        self::assertSame(['unsafe_xml'], array_column($result->errors, 'code'));
    }

    public function test_rejects_filename_that_differs_from_file_identifier(): void
    {
        $result = (new IncomingUpdXmlValidator)->validate($this->validUpdXml(), 'another-file.xml');

        self::assertFalse($result->isValid());
        self::assertContains('filename_mismatch', array_column($result->errors, 'code'));
    }

    public function test_rejects_xml_that_does_not_match_official_schema(): void
    {
        $xml = str_replace(' ВерсФорм="5.03"', '', $this->validUpdXml());

        $result = (new IncomingUpdXmlValidator)->validate($xml, self::FILE_ID.'.xml');

        self::assertFalse($result->isValid());
        self::assertContains('schema_invalid', array_column($result->errors, 'code'));
    }

    public function test_rejects_line_amount_that_does_not_match_quantity_and_price(): void
    {
        $xml = str_replace(
            'СтТовБезНДС="1500.00"',
            'СтТовБезНДС="1499.00"',
            $this->validUpdXml(),
        );

        $result = (new IncomingUpdXmlValidator)->validate($xml, self::FILE_ID.'.xml');

        self::assertFalse($result->isValid());
        self::assertContains('line_amount_mismatch', array_column($result->errors, 'code'));
    }

    public function test_rejects_document_totals_that_do_not_equal_line_totals(): void
    {
        $xml = str_replace(
            'СтТовУчНалВсего="1800.00"',
            'СтТовУчНалВсего="1801.00"',
            $this->validUpdXml(),
        );

        $result = (new IncomingUpdXmlValidator)->validate($xml, self::FILE_ID.'.xml');

        self::assertFalse($result->isValid());
        self::assertContains('document_totals_mismatch', array_column($result->errors, 'code'));
    }

    public function test_official_schema_resource_has_expected_checksum(): void
    {
        $path = dirname(__DIR__, 3)
            .'/resources/schemas/fns/upd-5.03/ON_NSCHFDOPPR_1_997_01_05_03_05.xsd';

        self::assertSame(
            '9f8db6bea215e03f393ef55b83ef742fd0fcf33a99f666383c7dfb91c233acf2',
            hash_file('sha256', $path),
        );
    }

    private function validUpdXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Файл ИдФайл="ON_NSCHFDOPPR_2BM-7712345678-771201001-20260904-1_2BM-1654321098-165401001-20260904-1_20260904_1" ВерсФорм="5.03" ВерсПрог="МОСТ">
  <Документ КНД="1115131" Функция="ДОП" НаимДокОпр="Универсальный передаточный документ" ДатаИнфПр="04.09.2026" ВремИнфПр="12.00.00">
    <СвСчФакт НомерДок="УПД-2026-1" ДатаДок="04.09.2026">
      <СвПрод>
        <ИдСв><СвЮЛУч НаимОрг="ООО Поставщик" ИННЮЛ="7712345678" КПП="771201001"/></ИдСв>
      </СвПрод>
      <СвПокуп>
        <ИдСв><СвЮЛУч НаимОрг="ООО МОСТ" ИННЮЛ="1654321098" КПП="165401001"/></ИдСв>
      </СвПокуп>
      <ДенИзм КодОКВ="643" НаимОКВ="Российский рубль"/>
    </СвСчФакт>
    <ТаблСчФакт>
      <СведТов НомСтр="1" НаимТов="Цемент М500, мешок 50 кг" ОКЕИ_Тов="166" НаимЕдИзм="кг" КолТов="100" ЦенаТов="15.00" СтТовБезНДС="1500.00" НалСт="20%" СтТовУчНал="1800.00">
        <Акциз><БезАкциз>без акциза</БезАкциз></Акциз>
        <СумНал><СумНал>300.00</СумНал></СумНал>
      </СведТов>
      <ВсегоОпл СтТовБезНДСВсего="1500.00" СтТовУчНалВсего="1800.00">
        <СумНалВсего><СумНал>300.00</СумНал></СумНалВсего>
      </ВсегоОпл>
    </ТаблСчФакт>
    <СвПродПер>
      <СвПер СодОпер="Товары переданы" ДатаПер="04.09.2026"><БезДокОснПер>1</БезДокОснПер></СвПер>
    </СвПродПер>
    <Подписант СпосПодтПолном="1"><ФИО Фамилия="Иванов" Имя="Иван" Отчество="Иванович"/></Подписант>
  </Документ>
</Файл>
XML;
    }
}
