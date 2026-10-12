<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Exceptions\ContractBuilderException;
use App\Services\Contract\ContractDocumentPrintLayout;
use App\Services\Contract\ContractDocumentRenderer;
use App\Services\Contract\ContractDocumentValidator;
use App\Services\Contract\ContractRevisionDocumentRenderer;
use App\Services\Contract\ContractTypography;
use App\Services\Contract\ContractVariableValueValidator;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class ContractTypographyTest extends TestCase
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
    }

    public function test_inline_typography_and_paragraph_format_are_valid_and_escaped(): void
    {
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['format' => ['align' => 'justify', 'lineHeight' => 1.5]], 'content' => [
            ['type' => 'text', 'text' => 'Цена', 'marks' => [['type' => 'textStyle', 'attrs' => ['fontFamily' => 'DejaVu Serif', 'fontSize' => 18, 'color' => '#ab1234']], ['type' => 'superscript']]],
            ['type' => 'text', 'text' => ' договора'],
        ]]]];
        (new ContractDocumentValidator)->validate(['document' => $document, 'variables' => []]);
        $html = (new ContractDocumentRenderer)->render($document, [], []);
        self::assertStringContainsString('font-size:18pt;', $html);
        self::assertStringContainsString('color:#ab1234;', $html);
        self::assertStringContainsString('<sup>', $html);
        self::assertStringContainsString('text-align:justify;line-height:1.5;', $html);
        $dom = new \DOMDocument;
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        self::assertStringContainsString('font-size:18pt;', $dom->getElementsByTagName('span')->item(0)->getAttribute('style'));
    }

    public function test_unsafe_typography_is_rejected(): void
    {
        foreach ([['fontFamily' => 'url(file:///secret)'], ['fontSize' => 73], ['color' => 'red;background:url(https://example.org)'], ['lineHeight' => 0]] as $format) {
            try {
                ContractTypography::validate($format, true);
                self::fail('Unsafe typography accepted');
            } catch (ContractBuilderException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
    }

    public function test_all_contract_profiles_are_supported_and_preserved(): void
    {
        $profiles = \App\Services\Contract\ContractDocumentProfiles::all();
        self::assertCount(14, $profiles);
        foreach (array_keys($profiles) as $code) {
            $content = ['contract_profile_code' => $code, 'document' => ['type' => 'doc', 'content' => []], 'variables' => []];
            (new ContractDocumentValidator)->validate($content);
            self::assertSame($code, \App\Services\Contract\ContractDocumentProfiles::fromContent($content));
        }
        self::assertSame('contract.subcontract', \App\Services\Contract\ContractDocumentProfiles::fromContent([], [['role' => 'subcontractor']]));
    }

    public function test_document_errors_identify_the_node_and_explain_missing_fields(): void
    {
        $responses = $this->createMock(\Illuminate\Contracts\Routing\ResponseFactory::class);
        $responses->method('json')->willReturnCallback(fn ($data, $status = 200) => new \Illuminate\Http\JsonResponse($data, $status));
        app()->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, $responses);
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => 'missing']]]]]];
        try {
            (new ContractDocumentValidator)->validate(['document' => $document, 'variables' => []]);
            self::fail('Missing dependency accepted');
        } catch (ContractBuilderException $error) {
            self::assertStringContainsString('Блок 1.1', $error->getMessage());
            self::assertStringContainsString('Поле не закреплено', $error->getMessage());
            self::assertArrayHasKey('document.content.0.content.0', $error->render(null)->getData(true)['errors']);
        }
    }

    public function test_text_identifiers_remain_text_and_errors_explain_the_constraint(): void
    {
        $validator = new ContractVariableValueValidator;
        self::assertSame('06658522310', $validator->validate(['type' => 'text'], '06658522310'));
        try {
            $validator->validate(['type' => 'number', 'constraints' => ['min' => 0]], '-1');
            self::fail('Negative value accepted');
        } catch (ContractBuilderException $error) {
            self::assertStringContainsString('минимум 0', $error->getMessage());
            self::assertStringNotContainsString('value', $error->getMessage());
        }
    }

    public function test_manifest_is_part_of_canonical_positioned_document(): void
    {
        $document = ['type' => 'doc', 'attrs' => ['layout' => ['version' => 1, 'page' => ['format' => 'A4', 'orientation' => 'portrait', 'margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]], 'grid' => ['size' => 5, 'snap' => true, 'visible' => false]]], 'content' => [
            ['type' => 'paragraph', 'attrs' => ['layout' => ['id' => 'text', 'x' => 0, 'y' => 0, 'width' => 170, 'minHeight' => 20]], 'content' => [['type' => 'text', 'text' => 'Договор']]],
        ]];
        $revision = ['document' => $document, 'definitions' => [], 'values' => [], 'entity_snapshots' => [], 'parties' => [], 'attachments' => [], 'revision_number' => 1, 'content_hash' => str_repeat('a', 64)];
        $html = (new ContractRevisionDocumentRenderer)->renderData($revision);
        $plan = (new ContractDocumentPrintLayout)->decode($html);
        self::assertCount(2, $plan['pages']);
        $text = implode(' ', array_column($plan['pages'][1], 'text'));
        self::assertStringContainsString($revision['content_hash'], $text);
        self::assertSame($html, (new ContractRevisionDocumentRenderer)->render((object) [...array_map(static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR), array_intersect_key($revision, array_flip(['document', 'definitions', 'values', 'entity_snapshots', 'parties', 'attachments']))), 'revision_number' => 1, 'content_hash' => $revision['content_hash']]));
    }
}
