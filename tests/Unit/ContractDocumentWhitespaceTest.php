<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\TrimStringsPreservingDocumentText;
use App\Http\Middleware\ConvertEmptyStringsToNullPreservingDocumentText as ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class ContractDocumentWhitespaceTest extends TestCase
{
    public function test_empty_text_before_an_inserted_field_remains_a_string(): void
    {
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Учебный документ']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Контроль']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => ''],
                ['type' => 'variable', 'attrs' => ['variableId' => 'd30704c6-c2b0-48ee-aef1-82c10d852d8c']],
            ]],
        ]];
        foreach ([['content' => ['document' => $document]], ['document' => $document]] as $payload) {
            $request = Request::create('/api/v1/admin/contract-library', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload + ['description' => ''], JSON_THROW_ON_ERROR));
            (new TrimStringsPreservingDocumentText())->handle($request, static fn (Request $trimmed) =>
                (new ConvertEmptyStringsToNull())->handle($trimmed, static fn (Request $normalized) => $normalized));
            $path = isset($payload['content']) ? 'content.document' : 'document';
            self::assertSame('', $request->input($path.'.content.2.content.0.text'));
            self::assertSame($document, $request->input($path));
            self::assertNull($request->input('description'));
        }
    }

    public function test_cleared_presentation_text_remains_an_empty_string(): void
    {
        $presentation = ['prefix' => '', 'suffix' => ''];
        $request = Request::create('/api/v1/admin/contract-library', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'content' => ['type' => 'number', 'display' => ['group' => '', 'presentation' => $presentation]],
        ], JSON_THROW_ON_ERROR));
        (new TrimStringsPreservingDocumentText())->handle($request, static fn (Request $trimmed) =>
            (new ConvertEmptyStringsToNull())->handle($trimmed, static fn (Request $normalized) => $normalized));
        self::assertSame($presentation, $request->input('content.display.presentation'));
        self::assertNull($request->input('content.display.group'));
    }

    public function test_variable_presentation_spacing_survives_create_and_revision_normalization(): void
    {
        $definition = ['type' => 'number', 'display' => ['group' => '  Расчёты  ', 'presentation' => ['prefix' => 'Площадь: ', 'suffix' => ' м² ']]];
        $table = ['type' => 'table', 'columns' => [['id' => 'area', 'label' => '  Площадь  ', 'definition' => $definition]]];
        foreach ([$definition, $table, ['type' => 'table', 'columns' => [['id' => 'nested', 'label' => 'Таблица', 'definition' => $table]]]] as $content) {
            foreach (['/api/v1/admin/contract-library', '/api/v1/admin/contract-library/item/versions'] as $url) {
                $request = Request::create($url, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                    'title' => '  Название  ', 'prefix' => '  Обычное поле  ', 'content' => $content,
                ], JSON_THROW_ON_ERROR));
                (new TrimStringsPreservingDocumentText())->handle($request, static fn (Request $trimmed) =>
                    (new ConvertEmptyStringsToNull())->handle($trimmed, static fn (Request $normalized) => $normalized));
                $path = $content['type'] === 'number' ? 'content' : (isset($content['columns'][0]['definition']['columns'])
                    ? 'content.columns.0.definition.columns.0.definition' : 'content.columns.0.definition');
                self::assertSame('Площадь: ', $request->input($path.'.display.presentation.prefix'));
                self::assertSame(' м² ', $request->input($path.'.display.presentation.suffix'));
                self::assertSame('Расчёты', $request->input($path.'.display.group'));
                self::assertSame('Название', $request->input('title'));
                self::assertSame('Обычное поле', $request->input('prefix'));
            }
        }
    }

    public function test_presentation_separators_remain_strings_instead_of_becoming_null(): void
    {
        $presentation = ['prefix' => ' ', 'suffix' => "\u{00A0}м² "];
        $request = Request::create('/api/v1/admin/contract-library', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'content' => ['type' => 'number', 'display' => ['presentation' => $presentation]],
            'description' => ' ',
        ], JSON_THROW_ON_ERROR));
        (new TrimStringsPreservingDocumentText())->handle($request, static fn (Request $trimmed) =>
            (new ConvertEmptyStringsToNull())->handle($trimmed, static fn (Request $normalized) => $normalized));
        self::assertSame($presentation, $request->input('content.display.presentation'));
        self::assertNull($request->input('description'));
    }

    public function test_document_fragments_survive_request_normalization(): void
    {
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Договор № '],
                ['type' => 'variable', 'attrs' => ['variableId' => 'number']],
                ['type' => 'text', 'text' => ' от '],
                ['type' => 'variable', 'attrs' => ['variableId' => 'date']],
                ['type' => 'text', 'text' => ' '],
            ]],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableCell', 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => ' цена ']]],
                    ]],
                ]],
            ]],
        ]];
        foreach ([['content' => ['document' => $document]], ['document' => $document]] as $payload) {
            $payload += ['title' => '  Название  ', 'text' => '  Обычное поле  ', 'description' => ' ', 'password' => ' secret '];
            $request = Request::create('/api/v1/admin/contract-library', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
            (new TrimStringsPreservingDocumentText())->handle($request, static fn (Request $trimmed) =>
                (new ConvertEmptyStringsToNull())->handle($trimmed, static fn (Request $normalized) => $normalized));
            $path = isset($payload['content']) ? 'content.document' : 'document';
            self::assertSame($document, $request->input($path));
            self::assertSame('Название', $request->input('title'));
            self::assertSame('Обычное поле', $request->input('text'));
            self::assertNull($request->input('description'));
            self::assertSame(' secret ', $request->input('password'));
        }
    }
}
