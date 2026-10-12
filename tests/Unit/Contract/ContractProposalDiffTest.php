<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Services\Contract\ContractProposalDiff;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class ContractProposalDiffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $app->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).'/lang'), 'ru'));
        Facade::setFacadeApplication($app);
    }

    public function test_rendered_diff_accounts_for_changed_values_and_added_deleted_nested_clauses(): void
    {
        $field = '11111111-1111-4111-8111-111111111111';
        $table = '22222222-2222-4222-8222-222222222222';
        $clause = static fn (string $id, array $content): array => ['type' => 'clause', 'attrs' => ['id' => $id], 'content' => $content];
        $paragraph = static fn (array $content): array => ['type' => 'paragraph', 'content' => $content];
        $before = ['document' => ['type' => 'doc', 'content' => [
            $clause('price', [$paragraph([['type' => 'text', 'text' => 'Цена: '], ['type' => 'variable', 'attrs' => ['variableId' => $field]]])]),
            $clause('removed', [$paragraph([['type' => 'text', 'text' => 'Старое условие']])]),
        ]], 'definitions' => [$field => ['id' => $field, 'version' => 1, 'title' => 'Цена', 'definition' => ['type' => 'money']],
            $table => ['id' => $table, 'version' => 1, 'title' => 'Спецификация', 'definition' => ['type' => 'table', 'columns' => [['id' => 'name', 'label' => 'Работа', 'definition' => ['type' => 'text']]]]]],
            'values' => [$field => ['amount' => '100', 'currency' => 'RUB'], $table => [['id' => 'row', 'values' => ['name' => 'Монтаж']]]]];
        $after = $before;
        $after['document']['content'][1] = $clause('added', [$paragraph([['type' => 'text', 'text' => 'Новый <порядок>']]), $clause('nested', [$paragraph([['type' => 'text', 'text' => 'Срок: 30 дней']])])]);
        $after['values'][$field]['amount'] = '150';
        $after['values'][$table][0]['values']['name'] = 'Новый монтаж';
        $original = $before;
        $diff = (new ContractProposalDiff)->compare($before, $after);
        $clauses = array_column($diff['clauses'], null, 'id');
        self::assertStringContainsString('100,00', $diff['text']['before']);
        self::assertStringContainsString('150,00', $diff['text']['after']);
        self::assertStringContainsString('Новый <порядок>', $diff['text']['after']);
        self::assertTrue($clauses['price']['changed']);
        self::assertNull($clauses['removed']['after']);
        self::assertNull($clauses['added']['before']);
        self::assertStringContainsString('30 дней', $clauses['nested']['after']);
        self::assertCount(2, $diff['values']);
        self::assertSame('1. Работа: Монтаж', $diff['values'][1]['before_display']);
        self::assertSame('1. Работа: Новый монтаж', $diff['values'][1]['after_display']);
        self::assertSame($original, $before);
    }

    public function test_review_shows_changed_bank_signer_authority_and_attachments_without_document_variables(): void
    {
        $before = ['document' => ['type' => 'doc', 'content' => []], 'definitions' => [], 'values' => [],
            'parties' => [['party_key' => 'first', 'name' => 'Иванов', 'settlement_account' => '00001', 'representative_name' => 'Иванов', 'authority_kind' => 'law']],
            'attachments' => [['id' => 1, 'name' => 'Прежняя спецификация.pdf', 'size' => 10, 'sha256' => str_repeat('a', 64)]]];
        $after = $before;
        $after['parties'][0] = [...$before['parties'][0], 'settlement_account' => '00002', 'representative_name' => 'Петров', 'authority_kind' => 'power_of_attorney'];
        $after['attachments'][0] = ['id' => 2, 'name' => 'Новая спецификация.pdf', 'size' => 20, 'sha256' => str_repeat('b', 64)];
        $diff = (new ContractProposalDiff)->compare($before, $after);
        self::assertSame('', $diff['text']['before']);
        self::assertSame('', $diff['text']['after']);
        self::assertCount(4, $diff['related']);
        self::assertSame('00001', $diff['related'][0]['before_display']);
        self::assertSame('00002', $diff['related'][0]['after_display']);
        self::assertSame('Доверенность', $diff['related'][2]['after_display']);
        self::assertStringContainsString('Новая спецификация.pdf', $diff['related'][3]['after_display']);
        self::assertStringContainsString(str_repeat('b', 64), $diff['related'][3]['after_display']);
        self::assertSame('00001', $before['parties'][0]['settlement_account']);
    }

    public function test_formatting_only_changes_are_visible_and_mark_the_clause_as_changed(): void
    {
        $before = ['document' => ['type' => 'doc', 'content' => [['type' => 'clause', 'attrs' => ['id' => 'terms'], 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Одинаковый текст']]],
        ]]]], 'definitions' => [], 'values' => []];
        $after = $before;
        $after['document']['attrs'] = ['locale' => 'en-US', 'layout' => \App\Services\Contract\ContractPageDecorations::defaultLayout(),
            'pageDecorations' => ['header' => ['text' => 'Новый колонтитул', 'align' => 'left']]];
        $after['document']['content'][0]['content'][0]['attrs']['format'] = ['fontFamily' => 'DejaVu Serif', 'fontSize' => 18];
        $after['document']['content'][0]['content'][0]['content'][0]['marks'] = [['type' => 'bold'], ['type' => 'italic']];
        $original = $before;
        $diff = (new ContractProposalDiff)->compare($before, $after);
        self::assertSame($diff['text']['before'], $diff['text']['after']);
        self::assertTrue($diff['clauses'][0]['changed']);
        self::assertCount(5, $diff['formatting']);
        $display = implode(' ', array_column($diff['formatting'], 'after_display'));
        self::assertStringContainsString('DejaVu Serif', $display);
        self::assertStringContainsString('Размер, пт: 18', $display);
        self::assertStringContainsString('Полужирный', $display);
        self::assertStringContainsString('Новый колонтитул', $display);
        self::assertSame($original, $before);
        $same = $after;
        $same['document']['content'][0]['content'][0]['content'][0]['marks'] = [['type' => 'italic'], ['type' => 'bold']];
        self::assertSame([], (new ContractProposalDiff)->compare($after, $same)['formatting']);
        $implicitLocale = $before;
        $implicitLocale['document']['attrs']['locale'] = 'ru-RU';
        self::assertSame([], (new ContractProposalDiff)->compare($before, $implicitLocale)['formatting']);
    }

    public function test_images_are_compared_without_losing_clauses_or_exposing_embedded_bytes(): void
    {
        $before = ['document' => ['type' => 'doc', 'attrs' => [
            'layout' => \App\Services\Contract\ContractPageDecorations::defaultLayout(),
            'pageDecorations' => ['header' => ['text' => 'Колонтитул', 'align' => 'left']],
        ], 'content' => [['type' => 'clause', 'attrs' => ['id' => 'logo'], 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Основное условие']]],
            ['type' => 'image', 'attrs' => ['src' => 'data:image/png;base64,old-image', 'alt' => 'Логотип', 'width' => 60, 'height' => 15]],
        ]]]], 'definitions' => [], 'values' => []];
        $after = $before;
        $after['document']['content'][0]['content'][1]['attrs']['src'] = 'data:image/png;base64,new-image';
        $diff = (new ContractProposalDiff)->compare($before, $after);
        self::assertStringContainsString('Основное условие', $diff['text']['before']);
        self::assertStringContainsString('Логотип', $diff['text']['after']);
        self::assertStringContainsString('60 × 15 мм', $diff['text']['after']);
        self::assertStringContainsString('Изображение до замены', $diff['text']['before']);
        self::assertStringContainsString('Изображение после замены', $diff['text']['after']);
        self::assertStringNotContainsString('data:image', $diff['text']['after']);
        self::assertSame('logo', $diff['clauses'][0]['id']);
        self::assertTrue($diff['clauses'][0]['changed']);
        self::assertFalse((new ContractProposalDiff)->compare($before, $before)['clauses'][0]['changed']);
    }
}
