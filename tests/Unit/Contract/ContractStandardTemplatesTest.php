<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Services\Contract\ContractDocumentRenderer;
use App\Services\Contract\ContractDocumentValidator;
use App\Services\Contract\ContractStandardTemplates;
use App\Services\Contract\ContractVariableDefinitionValidator;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class ContractStandardTemplatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_catalogue_has_five_complete_profiles_with_resolved_requisites_and_appendices(): void
    {
        $templates = new ContractStandardTemplates;
        self::assertCount(5, $templates->catalogue());
        foreach ([...ContractStandardTemplates::CODES, 'work'] as $code) {
            $ids = $definitions = $values = [];
            foreach ($templates->fields($code) as $key => $definition) {
                (new ContractVariableDefinitionValidator)->validate($definition);
                $id = (string) Str::uuid();
                $ids[$key] = $id;
                $definitions[$id] = ['id' => $id, 'version' => 2, 'title' => trans_message('contract_templates.labels.'.$key), 'definition' => $definition];
                self::assertStringNotContainsString('contract_templates.', $definitions[$id]['title']);
                $values[$id] = match ($definition['type']) {
                    'date' => '2026-10-11', 'money' => ['amount' => '100', 'currency' => 'RUB'], 'choice' => 'included',
                    'table' => [['id' => 'row-1', 'values' => ['name' => 'Позиция спецификации', 'unit' => 'шт.', 'quantity' => '1', 'price' => ['amount' => '100', 'currency' => 'RUB']]]],
                    default => 'Значение '.$key,
                };
            }
            $content = $templates->content($code, $ids);
            (new ContractDocumentValidator)->validate($content, array_map(static fn (array $field): array => $field['definition'], $definitions));
            $html = (new ContractDocumentRenderer)->render($content['document'], $definitions, $values);
            self::assertGreaterThan(11000, mb_strlen(strip_tags($html)));
            self::assertStringContainsString('Приложение № 1', $html);
            self::assertStringContainsString('Приложение № 2', $html);
            self::assertStringContainsString('Позиция спецификации', $html);
            self::assertStringContainsString('Значение payer_bank_account', $html);
            self::assertStringNotContainsString('{subject}', $html);
            self::assertSame('contract.'.$code, $content['contract_profile_code']);
            self::assertFalse($templates->fields($code)['project']['required']);
            if ($code === 'subcontract') self::assertStringContainsString('не является дополнительным условием оплаты', $html);
            if ($code === 'household-repair') self::assertStringContainsString('Фиксированный штраф', $html);
            if ($code === 'design-survey') self::assertStringContainsString('статье 761', $html);
        }
    }
}
