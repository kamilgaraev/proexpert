<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCollectorQueryContext;
use App\Services\Logging\EnsureLogFailuresAreNonFatal;
use App\Services\Monitoring\SlowQueryContext;
use App\Services\Monitoring\TracingService;
use Fiber;
use Generator;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\LazyCollection;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class RagCollectorQueryContextTest extends TestCase
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

    public function test_lazy_pages_keep_keys_and_do_not_attribute_consumer_queries_or_a_paused_generator(): void
    {
        $reads = [];
        $rows = new LazyCollection(function () use (&$reads): Generator {
            foreach ([11 => 'first', 22 => 'second'] as $key => $value) {
                $reads[] = $this->query();
                yield $key => $value;
            }
        });
        $iterator = (new RagCollectorQueryContext('core_business', 'core_knowledge_category'))->iterate($rows);
        self::assertSame([], $reads);
        $result = [];
        foreach ($iterator as $key => $value) {
            $result[$key] = $value;
            self::assertNull($this->query()['rag_collector']);
        }
        self::assertSame([11 => 'first', 22 => 'second'], $result);
        self::assertCount(2, $reads);
        foreach ($reads as $read) {
            self::assertSame($this->core(), $read['rag_collector']);
            self::assertSame('tests/Unit/Monitoring/RagCollectorQueryContextTest.php', $read['source']['file']);
        }
        self::assertNull(RagCollectorQueryContext::current());
    }

    public function test_interleaved_and_nested_iterators_choose_only_the_nearest_active_collector(): void
    {
        $reads = [];
        $make = function (string $source, string $entity) use (&$reads): Generator {
            $rows = new LazyCollection(function () use (&$reads): Generator {
                for ($page = 0; $page < 2; $page++) {
                    $reads[] = $this->query()['rag_collector'];
                    yield $page;
                }
            });

            return (new RagCollectorQueryContext($source, $entity))->iterate($rows);
        };
        $a = $make('core_business', 'core_knowledge_category');
        $b = $make('sales_supplier_catalog', 'supplier');
        $a->rewind();
        self::assertNull(RagCollectorQueryContext::current());
        $b->rewind();
        $a->next();
        $b->next();
        self::assertSame([$this->core(), $this->sales(), $this->core(), $this->sales()], $reads);

        $reads = [];
        $inner = $make('sales_supplier_catalog', 'supplier');
        $outer = (new RagCollectorQueryContext('core_business', 'core_knowledge_category'))->iterate($inner);
        self::assertSame([0, 1], iterator_to_array($outer));
        self::assertSame([$this->sales(), $this->sales()], $reads);
        self::assertNull(RagCollectorQueryContext::current());
    }

    public function test_fiber_suspension_and_iterator_exceptions_do_not_leak_context(): void
    {
        $failure = new RuntimeException('collector-step-failure');
        $fiber = new Fiber(function () use ($failure): void {
            $rows = new LazyCollection(function () use ($failure): Generator {
                Fiber::suspend(RagCollectorQueryContext::current());
                self::assertSame($this->core(), RagCollectorQueryContext::current());
                yield throw $failure;
            });
            foreach ((new RagCollectorQueryContext('core_business', 'core_knowledge_category'))->iterate($rows) as $row) {
                self::fail('No row should be produced');
            }
        });
        self::assertSame($this->core(), $fiber->start());
        self::assertNull($this->query()['rag_collector']);
        $seen = null;
        $other = new LazyCollection(function () use (&$seen): Generator {
            $seen = $this->query()['rag_collector'];
            yield 1;
        });
        self::assertSame([1], iterator_to_array((new RagCollectorQueryContext('sales_supplier_catalog', 'supplier'))->iterate($other)));
        self::assertSame($this->sales(), $seen);
        try {
            $fiber->resume();
            self::fail('Original iterator exception should propagate');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertNull($this->query()['rag_collector']);
    }

    public function test_unsupported_pairs_are_omitted_without_changing_iteration_or_exposing_values(): void
    {
        foreach ([['private-source', 'supplier'], ['core_business', 'private-entity'], ['core_business', 'supplier'],
            ['sales_supplier_catalog', 'core_knowledge_category'], ['core_business', 'core_knowledge_article_feedback']] as [$source, $entity]) {
            $context = null;
            $rows = new LazyCollection(function () use (&$context): Generator {
                $context = $this->query();
                yield 'private-row';
            });
            self::assertSame(['private-row'], iterator_to_array((new RagCollectorQueryContext($source, $entity))->iterate($rows)));
            self::assertNull($context['rag_collector']);
            self::assertStringNotContainsString('private-', json_encode($context, JSON_THROW_ON_ERROR));
        }
    }

    public function test_early_break_and_consumer_exception_keep_later_pages_lazy(): void
    {
        $reads = 0;
        $rows = new LazyCollection(function () use (&$reads): Generator {
            for ($page = 0; $page < 3; $page++) {
                $reads++;
                self::assertSame($this->core(), $this->query()['rag_collector']);
                yield $page;
            }
        });
        $iterator = (new RagCollectorQueryContext('core_business', 'core_knowledge_category'))->iterate($rows);
        foreach ($iterator as $row) {
            break;
        }
        self::assertSame(1, $reads);
        self::assertNull($this->query()['rag_collector']);
        $failure = new RuntimeException('consumer-failure');
        try {
            foreach ($iterator as $row) {
                throw $failure;
            }
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(1, $reads);
        unset($iterator);
        self::assertNull($this->query()['rag_collector']);
    }

    public function test_http_and_background_logging_keep_only_fixed_catalogue_identity(): void
    {
        $read = function (): array {
            $contexts = [];
            $rows = new LazyCollection(function () use (&$contexts): Generator {
                $contexts[] = $this->query();
                yield 'private-payload';
            });
            foreach ((new RagCollectorQueryContext('sales_supplier_catalog', 'supplier'))->iterate($rows) as $row) {
                self::assertNull($this->query()['rag_collector']);
            }

            return $contexts[0];
        };
        $background = $read();
        $http = null;
        $this->tracing->correlateHttp(Request::create('/collector-test'), function () use ($read, &$http): Response {
            $http = $read();

            return new Response;
        });
        self::assertSame($this->sales(), $background['rag_collector']);
        self::assertSame($background['rag_collector'], $http['rag_collector']);
        self::assertSame('http', $http['execution_context']['kind']);
        $handler = new TestHandler;
        $handler->setFormatter(new JsonFormatter);
        $logger = new IlluminateLogger(new Logger('slow_queries', [$handler]));
        (new EnsureLogFailuresAreNonFatal)($logger);
        $logger->warning('slow_database_query', $http);
        $serialized = $handler->getRecords()[0]->formatted;
        $logged = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR)['context'];
        self::assertSame($this->sales(), $logged['rag_collector']);
        self::assertStringNotContainsString('private-payload', $serialized);
        self::assertStringNotContainsString('RagCollectorQueryContext', json_encode($logged['rag_collector'], JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('object', $logged['rag_collector']);
        self::assertArrayNotHasKey('args', $logged['rag_collector']);
    }

    private function query(): array
    {
        return $this->factory->forQuery(new QueryExecuted('select 1', [], 600, new Connection(null)));
    }

    private function core(): array
    {
        return ['source_type' => 'core_business', 'entity_type' => 'core_knowledge_category', 'phase' => 'collection_page'];
    }

    private function sales(): array
    {
        return ['source_type' => 'sales_supplier_catalog', 'entity_type' => 'supplier', 'phase' => 'collection_page'];
    }
}
