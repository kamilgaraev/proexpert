<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class AssistantLegacyFactPrecisionTest extends TestCase
{
    private mixed $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('app', new class {
            public function getLocale(): string { return 'ru'; }
        });
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $container->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).'/lang'), 'ru'));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApplication);
        parent::tearDown();
    }

    public function test_legacy_money_quantity_and_date_fields_preserve_exact_raw_values(): void
    {
        $model = new class extends Model {};
        $model->mergeCasts(['budget_amount' => 'decimal:2', 'planned_advance_amount' => 'decimal:2',
            'actual_advance_amount' => 'decimal:2', 'completed_quantity' => 'decimal:4']);
        $model->setRawAttributes(['id' => 1, 'status' => 'active', 'budget_amount' => '1234567890123.17',
            'planned_advance_amount' => '1000000000000.09', 'actual_advance_amount' => '0.00',
            'completed_quantity' => '1234.5678', 'needed_by' => '2026-12-01', 'order_date' => '2026-09-01',
            'delivery_date' => '2026-12-01', 'budget_currency' => 'RUB'], true);
        $fields = array_keys($model->getAttributes());
        $reference = ['entity_type' => 'purchase_request', 'entity_id' => 1, 'content_scope' => 'structured',
            'checked_fields' => $fields, 'fetched_at' => '2026-09-29T12:00:00Z', 'source_version' => 'v1'];
        $row = AssistantStructuredFactFormatter::row($model, 'purchase_request', $fields, $reference);
        $payload = AssistantStructuredFactFormatter::payload([$row], $reference['fetched_at']);
        $this->assertSame('1234567890123.17', $row['fields']['budget_amount']);
        $this->assertSame('1000000000000.09', $row['fields']['planned_advance_amount']);
        $this->assertSame('0.00', $row['fields']['actual_advance_amount']);
        $this->assertSame('1234.5678', $row['fields']['completed_quantity']);
        $guard = (new AssistantStructuredFactVerifier)->guard('Какой статус, срок, бюджет и количество?', 'Сумма 1', [$payload]);
        $this->assertFalse($guard['needs_clarification']);
        $this->assertStringContainsString('Бюджет: 1234567890123.17', $guard['text']);
        $this->assertStringContainsString('Фактический аванс: 0.00', $guard['text']);
        $this->assertSame('2026-12-01', $row['fields']['delivery_date']);
        $this->assertStringContainsString('Дата доставки:', $guard['text']);
        $this->assertStringNotContainsString('ai_assistant_facts.fields.', $guard['text']);
    }

    public function test_binary_float_legacy_fields_cannot_be_promoted_to_exact_fact_evidence(): void
    {
        $model = new class extends Model {};
        $model->setRawAttributes(['id' => 1, 'budget_amount' => 12.17, 'planned_advance_amount' => 0.0,
            'actual_advance_amount' => 1.0, 'completed_quantity' => 1.25], true);
        $fields = ['budget_amount', 'planned_advance_amount', 'actual_advance_amount', 'completed_quantity'];
        $this->assertNull(AssistantStructuredFactFormatter::row($model, 'project', $fields, []));
    }
}
