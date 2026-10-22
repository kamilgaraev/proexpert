<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\Models\Project;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantProjectlessPaymentReportTest extends TestCase
{
    private const QUERY = 'Сделай CSV со сводкой платёжных документов по всей организации: для каждого типа документа, статуса и валюты — количество документов и общая сумма. Черновики тоже посчитай. Валюты не смешивай. Учти документы без проекта и не ограничивайся выбранным проектом.';

    protected function tearDown(): void
    {
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        parent::tearDown();
    }

    public function test_exact_payment_summary_includes_projectless_documents_despite_ui_context(): void
    {
        Queue::fake();
        config(['cache.default' => 'array', 'ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true,
            'ai-assistant-credits.profiles.normal.max_calls' => 5]);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $actor = $fixture->owner;
        $organization = $fixture->organization;
        $uiProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]));
        $otherProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]));
        $ids = [];
        foreach ([[$uiProject->id, '10.25', 'RUB', PaymentDocumentStatus::APPROVED],
            [$otherProject->id, '20.50', 'RUB', PaymentDocumentStatus::APPROVED],
            [null, '7.25', 'USD', PaymentDocumentStatus::DRAFT]] as [$projectId, $amount, $currency, $status]) {
            $ids[] = PaymentDocument::withoutEvents(static fn () => PaymentDocument::query()->create([
                'organization_id' => $organization->id, 'project_id' => $projectId,
                'payer_organization_id' => $organization->id, 'payee_organization_id' => $organization->id,
                'document_type' => PaymentDocumentType::INVOICE, 'invoice_type' => InvoiceType::ACT,
                'invoiceable_type' => null, 'invoiceable_id' => null, 'document_number' => 'SCOPE-'.Str::uuid(),
                'document_date' => '2026-10-15', 'due_date' => '2026-10-20', 'direction' => InvoiceDirection::OUTGOING,
                'status' => $status, 'amount' => $amount, 'paid_amount' => '0.00', 'remaining_amount' => $amount,
                'currency' => $currency, 'created_by_user_id' => $actor->id,
            ]))->id;
        }
        $this->actingAs($actor);
        app(AICreditService::class)->grant($organization, 100000, 'purchase', null, 'projectless-finance-'.Str::uuid());
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
        $bytes = null;
        $files = $this->createMock(FileService::class);
        $files->expects(self::once())->method('putPrivate')->willReturnCallback(static function (string $path, $stream, string $mime, string $hash) use (&$bytes): CurrentStoredFile {
            $bytes = stream_get_contents($stream);
            self::assertSame(hash('sha256', $bytes), $hash);

            return new CurrentStoredFile($path, 'test-etag', strlen($bytes), $hash, $mime);
        });
        $this->app->instance(FileService::class, $files);
        $report = ['title' => 'Сводка платёжных документов', 'format' => 'csv', 'sections' => [[
            'title' => 'Документы', 'scope' => 'organization', 'domain' => 'finance', 'entity_type' => 'payment_document',
            'project_id' => (int) $uiProject->id, 'columns' => ['document_type', 'status', 'currency'],
            'group_by' => ['document_type', 'status', 'currency'], 'filters' => [],
            'metrics' => [['field' => null, 'operation' => 'count', 'label' => 'Количество документов'],
                ['field' => 'amount', 'operation' => 'sum', 'label' => 'Общая сумма']],
            'order_by' => [], 'row_limit' => 1000,
        ]]];
        $calls = [['describe_custom_report_source', ['domain' => 'finance', 'entity_type' => 'payment_document', 'project_id' => null]],
            ['generate_custom_report', $report]];
        $provider = $this->createMock(ProjectlessPaymentResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->method('responses')->willReturnCallback(static function (array $input, array $options) use (&$calls): array {
            if ($calls === []) {
                return ['content' => 'Отчёт сформирован.', 'output' => [], 'function_calls' => [], 'input_tokens' => 100,
                    'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
            }
            [$name, $arguments] = array_shift($calls);
            self::assertContains($name, array_column($options['tools'] ?? [], 'name'));
            $id = 'projectless-payment-'.count($calls);
            $call = ['type' => 'function_call', 'id' => $id, 'status' => 'completed', 'call_id' => $id, 'name' => $name,
                'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];

            return ['content' => '', 'output' => [$call], 'function_calls' => [$call], 'input_tokens' => 100,
                'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $payload = ['request_id' => (string) Str::uuid(), 'message' => self::QUERY, 'profile' => 'normal', 'allow_actions' => false,
            'context' => ['entity_refs' => [['type' => 'project', 'id' => (int) $uiProject->id]]]];
        $payload['quote_id'] = app(AICreditService::class)->quote($organization, $actor, $payload)['quote_id'];
        $service = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null]);
        $response = $service->ask(self::QUERY, (int) $organization->id, $actor, null, $payload);
        $metadata = $response['message']['metadata'];
        self::assertFalse($metadata['needs_clarification']);
        self::assertSame('verified', $metadata['validation_status']);
        self::assertFalse($metadata['degraded_mode']);
        self::assertCount(1, $metadata['artifacts']);
        self::assertStringEndsWith('.csv', $metadata['artifacts'][0]['filename']);
        self::assertNotNull($bytes);
        $refs = array_values(array_filter($metadata['source_refs'], static fn (array $ref): bool => ($ref['_report_scope']['entity_type'] ?? null) === 'payment_document'));
        self::assertCount(1, $refs);
        self::assertNull($refs[0]['project_id']);
        self::assertNull($refs[0]['_report_scope']['project_id']);
        $actualIds = array_map('intval', $refs[0]['_report_ids']);
        sort($actualIds);
        sort($ids);
        self::assertSame($ids, $actualIds);
        $stream = fopen('php://temp', 'w+b');
        self::assertNotFalse($stream);
        try {
            fwrite($stream, $bytes);
            rewind($stream);
            self::assertSame("\xEF\xBB\xBF", fread($stream, 3));
            self::assertSame(['Тип документа', 'Статус', 'Валюта', 'Количество документов', 'Общая сумма'], fgetcsv($stream, null, ';', '"', ''));
            $rows = [];
            while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
                $rows[$row[2]] = $row;
            }
            ksort($rows);
            self::assertSame([
                'RUB' => ['Счет на оплату', 'Утвержден', 'RUB', '2', '30.75'],
                'USD' => ['Счет на оплату', 'Черновик', 'USD', '1', '7.25'],
            ], $rows);
        } finally {
            fclose($stream);
        }
    }
}

interface ProjectlessPaymentResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
