<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceBatchQueryContext;
use App\Services\Monitoring\SlowQueryContext;
use App\Services\Monitoring\TracingService;
use Fiber;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class RagSourceBatchQueryContextTest extends TestCase
{
    private Container $previousContainer;
    private TracingService $tracing;
    private SlowQueryContext $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository);
        $this->tracing = new TracingService(null);
        $this->factory = new SlowQueryContext($this->tracing);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_sync_consumer_has_context_without_request_and_keeps_result_and_source(): void
    {
        self::assertNull(RagSourceBatchQueryContext::current());
        $result = (new RagSourceBatchQueryContext(2, false, ['file_document'], null))->run(function (): array {
            $query = $this->query();
            self::assertSame($this->metadata(2, false, ['file_document']), $query['rag_source_batch']);
            self::assertSame('tests/Unit/Monitoring/RagSourceBatchQueryContextTest.php', $query['source']['file']);

            return ['private-result'];
        });
        self::assertSame(['private-result'], $result);
        self::assertNull($this->query()['rag_source_batch']);
    }

    public function test_nested_context_restores_outer_and_propagates_original_exception(): void
    {
        $failure = new RuntimeException('private-exception');
        (new RagSourceBatchQueryContext(1, false, null, null))->run(function () use ($failure): void {
            try {
                (new RagSourceBatchQueryContext(3, true, ['file_document'], 'assistant_document'))->run(function () use ($failure): never {
                    self::assertSame($this->metadata(3, true, ['file_document'], 'assistant_document'), RagSourceBatchQueryContext::current());
                    throw $failure;
                });
                self::fail('consumer_exception_was_not_propagated');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertSame($this->metadata(1, false, null), RagSourceBatchQueryContext::current());
        });
        self::assertNull(RagSourceBatchQueryContext::current());
    }

    public function test_suspended_fiber_and_deferred_generator_do_not_leak_context(): void
    {
        $fiber = new Fiber(fn () => (new RagSourceBatchQueryContext(1, false, ['file_document'], null))->run(function (): void {
            Fiber::suspend(RagSourceBatchQueryContext::current());
            self::assertSame($this->metadata(1, false, ['file_document']), RagSourceBatchQueryContext::current());
        }));
        self::assertSame($this->metadata(1, false, ['file_document']), $fiber->start());
        self::assertNull($this->query()['rag_source_batch']);
        (new RagSourceBatchQueryContext(4, true, null, null))->run(function (): void {
            self::assertSame($this->metadata(4, true, null), RagSourceBatchQueryContext::current());
        });
        $fiber->resume();
        $deferred = (new RagSourceBatchQueryContext(1, false, null, null))->run(function (): \Generator {
            yield RagSourceBatchQueryContext::current();
        });
        self::assertSame([null], iterator_to_array($deferred));
        self::assertNull(RagSourceBatchQueryContext::current());
    }

    public function test_invalid_or_oversized_metadata_is_omitted_without_exposing_values_or_outer_context(): void
    {
        $invalid = [
            [0, ['file_document'], null], [1, ['private-source'], null], [1, ['file_document'], 'private-entity'],
            [1, ['core_business'], 'assistant_document'], [1, array_fill(0, 9, 'file_document'), null],
            [1, [['private-value']], null],
        ];
        (new RagSourceBatchQueryContext(1, false, null, null))->run(function () use ($invalid): void {
            foreach ($invalid as [$ordinal, $sources, $entity]) {
                $result = (new RagSourceBatchQueryContext($ordinal, false, $sources, $entity))->run(fn () => $this->query());
                self::assertNull($result['rag_source_batch']);
                self::assertStringNotContainsString('private-', json_encode($result, JSON_THROW_ON_ERROR));
            }
        });
    }

    public function test_http_context_exports_only_catalogue_values_and_removes_array_keys(): void
    {
        $context = null;
        $this->tracing->correlateHttp(Request::create('/batch-context-test'), function () use (&$context): Response {
            $context = (new RagSourceBatchQueryContext(1, true, ['private-key' => 'file_document'], 'assistant_document'))
                ->run(fn () => $this->query());

            return new Response;
        });
        self::assertSame($this->metadata(1, true, ['file_document'], 'assistant_document'), $context['rag_source_batch']);
        self::assertSame('http', $context['execution_context']['kind']);
        self::assertStringNotContainsString('private-', json_encode($context['rag_source_batch'], JSON_THROW_ON_ERROR));
        self::assertSame(['aggregation_ordinal', 'mode', 'phase', 'source_types', 'entity_type'], array_keys($context['rag_source_batch']));
        self::assertNull($this->query()['rag_source_batch']);
    }

    private function query(): array
    {
        return $this->factory->forQuery(new QueryExecuted('select 1', [], 600, new Connection(null)));
    }

    private function metadata(int $ordinal, bool $expected, ?array $sources, ?string $entity = null): array
    {
        return ['aggregation_ordinal' => $ordinal, 'mode' => $expected ? 'expected' : 'stored', 'phase' => 'source_batch_consume',
            'source_types' => $sources, 'entity_type' => $entity];
    }
}
