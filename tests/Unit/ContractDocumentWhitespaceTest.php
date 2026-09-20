<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\TrimStringsPreservingDocumentText;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class ContractDocumentWhitespaceTest extends TestCase
{
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
