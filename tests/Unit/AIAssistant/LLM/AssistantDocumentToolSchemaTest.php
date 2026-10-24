<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\LLM;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDocumentsTool;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressCohort;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressEnrollment;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantClarificationContext;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\LLM\OpenAIProvider;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

final class AssistantDocumentToolSchemaTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    #[DataProvider('policies')]
    public function test_actual_sdk_document_schema_uses_scalar_item_enum_without_changing_other_wire_fields(string $policy): void
    {
        $tools = $this->definitions($policy);
        $wire = $this->wire($tools);
        $parameters = $wire['tools'][0]['parameters'];
        self::assertArrayNotHasKey('enum', $parameters['properties']['source_types']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['file_document']],
            'minItems' => 1, 'maxItems' => 1], $parameters['properties']['source_types']);
        self::assertSame(['query', 'project_id', 'source_types', 'limit', 'retrieval_mode'], $parameters['required']);
        self::assertSame($parameters['required'], array_keys($parameters['properties']));
        self::assertFalse($parameters['additionalProperties']);
        self::assertFalse($wire['tools'][0]['strict']);
        self::assertFalse($wire['store']);
        self::assertFalse($wire['stream']);
        self::assertFalse($wire['parallel_tool_calls']);
        self::assertSame(['effort' => 'none'], $wire['reasoning']);
        self::assertSame(['reasoning.encrypted_content'], $wire['include']);
        self::assertSame('openai/gpt-6-luna', $wire['model']);
        self::assertSame('auto', $wire['tool_choice']);
        $legacyWire = $this->wire($this->definitions($policy, guarded: false));
        $tool = (new ReflectionClass(SearchAssistantDocumentsTool::class))->newInstanceWithoutConstructor();
        self::assertSame($tool->getDescription(), $legacyWire['tools'][0]['description']);
        self::assertNotSame($legacyWire['tools'][0]['description'], $wire['tools'][0]['description']);
        self::assertSame($tools[0]['description'], $wire['tools'][0]['description']);
        $genericDescriptionTools = $tools;
        $genericDescriptionTools[0]['description'] = $legacyWire['tools'][0]['description'];
        $genericDescriptionWire = $this->wire($genericDescriptionTools);
        $withoutDescription = $wire;
        unset($genericDescriptionWire['tools'][0]['description'], $withoutDescription['tools'][0]['description']);
        self::assertSame($genericDescriptionWire, $withoutDescription);
        $priorTools = $tools;
        $priorTools[0]['parameters']['properties']['source_types']['enum'] = [['file_document']];
        $priorWire = $this->wire($priorTools);
        unset($priorWire['tools'][0]['parameters']['properties']['source_types']['enum']);
        self::assertSame($priorWire, $wire);
    }

    public static function policies(): array
    {
        return [[OrdinaryAssistantDocumentProjection::VERSION], [OrdinaryAssistantSourceProjection::MIXED_VERSION],
            [OrdinaryAssistantSourceProjection::MIXED_V2_VERSION]];
    }

    #[DataProvider('sourceTypes')]
    public function test_old_and_portable_schema_accept_exactly_the_same_source_types(mixed $sourceTypes, bool $accepted): void
    {
        $schema = $this->wire($this->definitions())['tools'][0]['parameters'];
        $prior = $schema;
        $prior['properties']['source_types']['enum'] = [['file_document']];
        $arguments = (object) ['query' => 'Synthetic', 'project_id' => 52, 'source_types' => $sourceTypes, 'limit' => 3, 'retrieval_mode' => 'lexical'];
        $validator = new Validator;
        foreach ([$prior, $schema] as $candidate) {
            $jsonSchema = json_decode(json_encode($candidate, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            self::assertSame($accepted, $validator->validate($arguments, $jsonSchema)->isValid());
        }
    }

    public static function sourceTypes(): array
    {
        return [
            'single-approved' => [['file_document'], true], 'scalar' => ['file_document', false],
            'empty' => [[], false], 'multiple' => [['file_document', 'file_document'], false],
            'foreign-source' => [['project'], false], 'mixed-source' => [['file_document', 'project'], false],
            'null' => [null, false], 'boolean' => [true, false], 'numeric-item' => [[1], false],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function test_portable_document_schema_keeps_existing_argument_bounds(array $changes): void
    {
        $schema = $this->wire($this->definitions())['tools'][0]['parameters'];
        $schema = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $arguments = (object) array_replace(['query' => 'Synthetic', 'project_id' => 52, 'source_types' => ['file_document'],
            'limit' => 3, 'retrieval_mode' => 'lexical'], $changes);
        self::assertFalse((new Validator)->validate($arguments, $schema)->isValid());
    }

    public static function invalidArguments(): array
    {
        return [
            'foreign-project' => [['project_id' => 53]], 'null-project' => [['project_id' => null]],
            'zero-limit' => [['limit' => 0]], 'expanded-limit' => [['limit' => 4]],
            'hybrid' => [['retrieval_mode' => 'hybrid']], 'extra-cursor' => [['cursor' => 'forged']],
            'empty-query' => [['query' => '']], 'oversize-query' => [['query' => str_repeat('я', 201)]],
        ];
    }

    private function wire(array $tools): array
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new RuntimeException('offline schema capture')]));
        $handler->push(Middleware::history($history));
        $client = \OpenAI::factory()->withApiKey('offline-only')->withBaseUri('https://fixture.invalid/v1')
            ->withHttpClient(new Client(['handler' => $handler]))->make();
        try {
            $client->responses()->create(OpenAIProvider::nativePayload('openai/gpt-6-luna',
                [['role' => 'user', 'content' => 'Synthetic schema capture']], $tools, 1024, 'auto'));
            self::fail('Offline handler must finish capture without a provider call');
        } catch (RuntimeException $failure) {
            self::assertSame('offline schema capture', $failure->getMessage());
        }
        self::assertCount(1, $history);
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame('/v1/responses', $history[0]['request']->getUri()->getPath());

        return json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function definitions(string $policy = OrdinaryAssistantDocumentProjection::VERSION, bool $guarded = true): array
    {
        $sources = [['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'projection_version' => OrdinaryAssistantDocumentProjection::VERSION,
            'arguments' => ['project_id' => 52, 'document_id' => 159, 'chunk_ids' => [10]]]];
        if ($policy !== OrdinaryAssistantDocumentProjection::VERSION) {
            $sources[] = ['tool' => 'get_project_attention', 'projection_version' => OrdinaryAssistantSourceProjection::VERSION,
                'arguments' => ['project_id' => 52, 'focus' => 'schedule', 'limit' => 3]];
        }
        if ($policy === OrdinaryAssistantSourceProjection::MIXED_V2_VERSION) {
            $sources[] = ['tool' => 'get_project_production_schedule_summary', 'projection_version' => OrdinaryAssistantSourceProjection::VERSION,
                'arguments' => ['project_id' => 52, 'period_start' => null, 'period_end' => null]];
        }
        $cohort = ['id' => '11111111-1111-4111-8111-111111111111', 'organization_id' => 38, 'actor_id' => 39, 'project_id' => 52,
            'generation' => '22222222-2222-4222-8222-222222222222', 'policy_version' => $policy,
            'allowed_tools' => json_encode(array_column($sources, 'tool'), JSON_THROW_ON_ERROR), 'status' => 'active', 'expires_at' => '2030-01-01 00:00:00'];
        $manifest = ['id' => '33333333-3333-4333-8333-333333333333', 'cohort_id' => $cohort['id'], 'cohort_generation' => $cohort['generation'],
            'input_hash' => str_repeat('a', 64), 'sources' => json_encode($sources, JSON_THROW_ON_ERROR), 'input_context' => null,
            'status' => 'approved', 'expires_at' => '2030-01-01 00:00:00'];
        $manifest['manifest_hash'] = OrdinaryAssistantClarificationContext::manifestHash(['cohort' => $cohort['id'],
            'cohort_generation' => $cohort['generation'], 'input_hash' => $manifest['input_hash'], 'sources' => $sources], null);
        $connection = new DocumentSchemaOfflineConnection($cohort, $manifest);
        $resolver = new ConnectionResolver(['offline' => $connection]);
        $resolver->setDefaultConnection('offline');
        $previous = Model::getConnectionResolver();
        Model::setConnectionResolver($resolver);
        try {
            $context = (new ReflectionClass(OrdinaryAssistantScopedContext::class))->newInstanceWithoutConstructor();
            $access = (new ReflectionClass(AssistantDataAccessPolicy::class))->newInstanceWithoutConstructor();
            foreach (['batchIdentity' => [39, 38], 'batchDecisions' => ['membership' => true],
                'pendingEntityReads' => ['actor_id' => 39, 'organization_id' => 38, 'entities' => []]] as $key => $value) {
                (new ReflectionProperty(AssistantDataAccessPolicy::class, $key))->setValue($access, $value);
            }
            foreach (['guarded' => $guarded, 'cohort' => (new AssistantEgressCohort)->setRawAttributes($cohort),
                'enrollment' => (new AssistantEgressEnrollment)->setRawAttributes($manifest),
                'actor' => (new User)->forceFill(['id' => 39, 'is_active' => true, 'current_organization_id' => 38]),
                'inputHash' => $manifest['input_hash'], 'access' => $access,
                'enrollments' => (new ReflectionClass(OrdinaryAssistantEnrollmentService::class))->newInstanceWithoutConstructor()] as $key => $value) {
                (new ReflectionProperty(OrdinaryAssistantScopedContext::class, $key))->setValue($context, $value);
            }
            $tool = (new ReflectionClass(SearchAssistantDocumentsTool::class))->newInstanceWithoutConstructor();
            $registry = new AIToolRegistry;
            $registry->registerTool($tool);

            return $context->filterToolDefinitions($registry->getToolsDefinitions([$tool->getName()], true));
        } finally {
            if ($previous === null) { Model::unsetConnectionResolver(); } else { Model::setConnectionResolver($previous); }
        }
    }
}

final class DocumentSchemaOfflineConnection extends Connection
{
    public function __construct(private array $cohort, private array $manifest)
    {
        parent::__construct(null);
        $this->setQueryGrammar(new PostgresGrammar($this));
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if (str_contains($query, '"ai_assistant_egress_cohorts"')) { return [(object) $this->cohort]; }
        if (str_contains($query, '"ai_assistant_egress_enrollments"')) { return [(object) $this->manifest]; }
        if (str_contains($query, '"users"')) { return [(object) ['exists' => true]]; }
        throw new RuntimeException('Unexpected offline schema query');
    }

    public function getPdo()
    {
        throw new RuntimeException('Database forbidden in schema unit test');
    }
}
