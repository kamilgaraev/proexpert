<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Actions\Domains\AssistantDomainTool;
use App\BusinessModules\Features\AIAssistant\Actions\Domains\DiscoverAssistantDomainCapabilitiesTool;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\Models\User;
use App\Support\AI\TokenCounter;
use App\Support\AI\TokenBudgetService;
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use InvalidArgumentException;

final class AssistantToolSchemaBudgetTest extends TestCase
{
    private ?Container $previousApplication = null;
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Facade::getFacadeApplication();
        $this->previousContainer = Container::getInstance();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $app->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).'/lang'), 'ru'));
        $app->instance('validator', new Factory($app->make('translator'), $app));
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_registered_and_pending_finite_catalogues_have_bounded_tool_schemas(): void
    {
        $defaults = AssistantDomainCatalog::defaults();
        $merged = $defaults;
        foreach (glob(dirname(__DIR__, 3).'/app/BusinessModules/Features/AIAssistant/Services/DomainMetadata/*.php') ?: [] as $path) {
            $class = 'App\\BusinessModules\\Features\\AIAssistant\\Services\\DomainMetadata\\'.basename($path, '.php');
            if (method_exists($class, 'domainDefinitions')) {
                foreach ($class::domainDefinitions() as $definition) {
                    $merged[$definition->domain] = $definition;
                }
            }
        }
        foreach ($defaults as $definition) {
            $merged[$definition->domain] ??= $definition;
        }
        $counter = new TokenCounter;
        $measurements = [];
        foreach (['default' => $defaults, 'merged' => array_values($merged)] as $name => $definitions) {
            $registry = $this->registry(new AssistantDomainCatalog($definitions));
            $tools = $registry->getToolsDefinitions();
            $original = array_map(static fn (AIToolInterface $tool): array => ['type' => 'function', 'function' => [
                'name' => $tool->getName(), 'description' => $tool->getDescription(), 'parameters' => $tool->getParametersSchema(),
                'strict' => str_starts_with($tool->getName(), 'assistant_domain_'),
            ]], array_values($registry->getTools()));
            $types = array_unique(array_merge(...array_map(static fn ($definition): array => $definition->entityTypes, $definitions)));
            $measurements[$name] = ['types' => count($types), 'tools' => count($tools), 'original_schema_tokens' => $counter->tools($original), 'serialized_schema_tokens' => $counter->tools($tools), 'relevance' => []];
            $this->assertLessThan($counter->tools($original), $counter->tools($tools));
            foreach (['summary', 'find', 'reports', 'estimates', 'payments'] as $intent) {
                $service = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
                $reflection = new ReflectionClass(AIAssistantService::class);
                $reflection->getProperty('toolRegistry')->setValue($service, $registry);
                $reflection->getProperty('toolEligibilityPolicy')->setValue($service, new \App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy);
                $reflection->getProperty('logging')->setValue($service, $this->createMock(\App\Services\Logging\LoggingService::class));
                $queryText = match ($intent) {
                    'find' => 'Найди проекты и договоры', 'reports' => 'Подготовь отчёт по проекту',
                    'estimates' => 'Покажи данные сметы', 'payments' => 'Покажи платежи проекта', default => 'Проверь текущие данные проекта',
                };
                $understanding = (new \App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver)->resolve($queryText, []);
                $plan = ['task_type' => $intent === 'find' ? 'find' : 'summary', 'capability' => ['id' => $intent],
                    'request' => ['message' => $queryText, 'allow_actions' => false], 'request_understanding' => $understanding->toArray()];
                $names = $reflection->getMethod('resolveRelevantToolNames')->invoke($service, $plan);
                $relevant = $reflection->getMethod('resolveToolDefinitions')->invoke($service, $plan);
                $this->assertLessThan(6500, $counter->tools($relevant), $name.':'.$intent);
                $before = array_values(array_filter($original, static fn (array $tool): bool => in_array($tool['function']['name'], $names, true)));
                $query = mb_substr(str_repeat('Проверь актуальные итоги проекта, сравни договорные суммы и сроки выполнения работ, укажи документы и точные источники. ', 50), 0, 4000);
                $this->assertSame(4000, mb_strlen($query));
                $messages = [['role' => 'system', 'content' => str_repeat('Сохраняй полный вопрос и соблюдай права организации. ', 40)], ['role' => 'user', 'content' => $query]];
                foreach (['short', 'normal', 'detailed'] as $profile) {
                    $prepared = (new TokenBudgetService($counter))->prepare($messages, $relevant, $profile);
                    $this->assertSame($messages, $prepared['messages']);
                    $this->assertLessThanOrEqual(TokenBudgetService::limits($profile)['input'], $prepared['input_tokens']);
                    $measurements[$name]['relevance'][$intent]['profiles'][$profile] = $prepared['input_tokens'];
                }
                $measurements[$name]['relevance'][$intent] += ['before' => $counter->tools($before), 'after' => $counter->tools($relevant), 'tools' => count($relevant), 'preserved_message_tokens' => $counter->messages($messages)];
            }
            foreach ($registry->getTools() as $tool) {
                if (! $tool instanceof AssistantDomainTool) { continue; }
                $source = $tool->getParametersSchema();
                $wire = $registry->getToolsDefinitions([$tool->getName()])[0]['function']['parameters'];
                $this->assertCount(count($types), $source['properties']['entity_type']['enum']);
                $this->assertArrayNotHasKey('enum', $wire['properties']['entity_type']);
                $this->assertSame($source['required'], $wire['required']);
                $this->assertFalse($wire['additionalProperties']);
                $this->assertNotEmpty($source['properties']['domain']['enum']);
                $this->assertArrayNotHasKey('enum', $wire['properties']['domain']);
                $this->assertSame('string', $wire['properties']['domain']['type']);
            }
        }
        fwrite(STDOUT, "\n".json_encode($measurements, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        $this->assertGreaterThan(16384, $measurements['merged']['original_schema_tokens']);
    }

    public function test_discovery_filters_every_permission_and_pages_registered_types_and_fields(): void
    {
        $tool = $this->discovery(new AssistantDomainCatalog(AssistantDomainCatalog::defaults()));
        $page = $tool->describe(['domain' => 'crm', 'field_limit' => 2], static fn (string $domain): bool => $domain === 'crm', static fn (string $permission): bool => ! in_array($permission, ['crm.leads.view', 'crm.contacts.view'], true), static fn (string $type): bool => $type !== 'crm_company');
        $this->assertSame(['crm_deal', 'crm_activity', 'customer_issue'], array_column($page['capabilities'], 'entity_type'));
        $this->assertCount(2, $page['capabilities'][0]['fields']);
        $this->assertSame(2, $page['capabilities'][0]['next_field_offset']);
        $this->assertNull($page['next_offset']);
        $denied = $tool->describe(['domain' => 'crm'], static fn (): bool => false, static fn (): bool => true, static fn (): bool => true);
        $this->assertSame([], $denied['capabilities']);
        $unknown = $tool->describe(['entity_type' => 'arbitrary_sql_table'], static fn (): bool => true, static fn (): bool => true, static fn (): bool => true);
        $this->assertSame([], $unknown['capabilities']);
        $finance = $tool->describe(['domain' => 'contracts', 'field_limit' => 16], static fn (): bool => true, static fn (string $permission): bool => $permission !== 'finance.view', static fn (): bool => true);
        $this->assertNotContains('total_amount', $finance['capabilities'][0]['fields']);
        $this->assertSame('checked_on_read', $finance['record_access']);
    }

    public function test_discovery_bounds_subject_scope_checks_and_rejects_unbounded_pages(): void
    {
        $tool = $this->discovery(new AssistantDomainCatalog(AssistantDomainCatalog::defaults()));
        $calls = 0;
        $page = $tool->describe([], static fn (): bool => true, static fn (): bool => true, static function () use (&$calls): bool { $calls++; return false; });
        $this->assertSame(12, $calls);
        $this->assertSame(12, $page['next_offset']);
        $this->assertSame([], $page['capabilities']);
        $this->expectException(InvalidArgumentException::class);
        $tool->describe(['limit' => 500], static fn (): bool => true, static fn (): bool => true, static fn (): bool => true);
    }

    public function test_server_rejects_unregistered_entity_before_any_acl_or_database_scope(): void
    {
        $class = new ReflectionClass(AssistantDomainReadService::class);
        $reader = $class->newInstanceWithoutConstructor();
        $class->getProperty('catalog')->setValue($reader, new AssistantDomainCatalog(AssistantDomainCatalog::defaults()));
        try {
            $reader->execute('read', ['domain' => 'projects', 'entity_type' => 'arbitrary_sql_table', 'id' => 1], new User, 1);
            $this->fail('Unregistered entity reached the uninitialized ACL dependency.');
        } catch (ValidationException $exception) {
            $this->assertSame(['entity_type' => ['unsupported_entity']], $exception->errors());
        }
    }

    public function test_server_rejects_unregistered_domain_before_any_acl_or_database_scope(): void
    {
        $class = new ReflectionClass(AssistantDomainReadService::class);
        $reader = $class->newInstanceWithoutConstructor();
        $class->getProperty('catalog')->setValue($reader, new AssistantDomainCatalog(AssistantDomainCatalog::defaults()));
        try {
            $reader->execute('read', ['domain' => 'arbitrary_sql_table', 'entity_type' => 'project', 'id' => 1], new User, 1);
            $this->fail('Unregistered domain reached the uninitialized ACL dependency.');
        } catch (ValidationException $exception) {
            $this->assertSame(['domain' => ['unsupported_domain']], $exception->errors());
        }
    }

    private function discovery(AssistantDomainCatalog $catalog): DiscoverAssistantDomainCapabilitiesTool
    {
        $class = new ReflectionClass(DiscoverAssistantDomainCapabilitiesTool::class);
        $tool = $class->newInstanceWithoutConstructor();
        $class->getProperty('catalog')->setValue($tool, $catalog);
        return $tool;
    }

    private function registry(AssistantDomainCatalog $catalog): AIToolRegistry
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Features/AIAssistant/AIAssistantServiceProvider.php');
        $this->assertIsString($source);
        preg_match_all('/use ([^;]+);/', $source, $imports);
        $aliases = [];
        foreach ($imports[1] as $class) {
            $aliases[substr($class, (int) strrpos($class, '\\') + 1)] = $class;
        }
        preg_match_all('/registerFactory\(\'[^\']+\', fn \(\) => \$app->make\((\w+)::class\)\)/', $source, $registered);
        preg_match_all('/\\\\(App\\\\BusinessModules\\\\Features\\\\AIAssistant\\\\[^,]+)::class,/', $source, $qualified);
        $classes = array_merge(array_map(static fn (string $alias): string => $aliases[$alias], $registered[1]), $qualified[1]);
        $registry = new AIToolRegistry;
        foreach (array_unique($classes) as $class) {
            if (! is_subclass_of($class, AIToolInterface::class)) {
                continue;
            }
            $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            if ($tool instanceof AssistantDomainTool) {
                (new ReflectionClass(AssistantDomainTool::class))->getProperty('catalog')->setValue($tool, $catalog);
            }
            $registry->registerTool($tool);
        }
        return $registry;
    }
}
