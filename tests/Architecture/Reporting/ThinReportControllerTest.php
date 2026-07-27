<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportFormRequest;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;

final class ThinReportControllerTest extends TestCase
{
    #[DataProvider('actionSignatureProvider')]
    public function test_action_ports_expose_only_the_frozen_handle_signature(
        string $interface,
        array $parameters,
        string $returnType,
    ): void {
        $reflection = new ReflectionClass($interface);

        self::assertTrue($reflection->isInterface());
        self::assertSame(['handle'], array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(),
        ));

        $method = $reflection->getMethod('handle');
        self::assertSame($parameters, array_map(
            static fn ($parameter): string => (string) $parameter->getType(),
            $method->getParameters(),
        ));
        self::assertSame($returnType, (string) $method->getReturnType());
    }

    #[DataProvider('controllerMethodProvider')]
    public function test_controller_endpoints_accept_one_reporting_request_and_return_json(
        string $controller,
        string $method,
        string $request,
    ): void {
        $reflection = new ReflectionMethod($controller, $method);
        $parameters = $reflection->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame($request, (string) $parameters[0]->getType());
        self::assertTrue(is_subclass_of($request, ReportFormRequest::class));
        self::assertSame(JsonResponse::class, (string) $reflection->getReturnType());

        $constructor = (new ReflectionClass($controller))->getConstructor();
        self::assertNotNull($constructor);
        $dependencies = $constructor->getParameters();
        self::assertCount(match (true) {
            str_ends_with($controller, 'ReportRunController') => 5,
            str_ends_with($controller, 'ReportExportController') => 6,
            default => 2,
        }, $dependencies);
        self::assertSame(ReportExecutionContextFactory::class, (string) $dependencies[0]->getType());
        foreach (array_slice($dependencies, 1) as $dependency) {
            $type = (string) $dependency->getType();
            self::assertTrue(interface_exists($type), $type);
            self::assertStringStartsWith(
                'App\\BusinessModules\\Core\\Reporting\\Application\\Contracts\\',
                $type,
            );
        }
    }

    public function test_controllers_expose_only_the_frozen_endpoint_methods(): void
    {
        $namespace = 'App\\BusinessModules\\Core\\Reporting\\Http\\Admin\\Controllers\\';
        $expected = [
            $namespace.'ReportCatalogController' => ['__invoke'],
            $namespace.'ReportRunController' => ['cancel', 'retry', 'show', 'store'],
            $namespace.'ReportRowsController' => ['__invoke'],
            $namespace.'ReportDrillDownController' => ['__invoke'],
            $namespace.'ReportExportController' => ['cancel', 'downloadLink', 'retry', 'show', 'store'],
        ];

        foreach ($expected as $controller => $methods) {
            $actual = array_map(
                static fn (ReflectionMethod $method): string => $method->getName(),
                array_filter(
                    (new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC),
                    static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $controller
                        && $method->getName() !== '__construct',
                ),
            );
            sort($actual);
            self::assertSame($methods, $actual, $controller);
        }
    }

    public function test_every_controller_endpoint_has_exact_thin_topology_and_no_forbidden_nodes(): void
    {
        $files = glob(dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/Http/Admin/Controllers/*.php') ?: [];
        self::assertCount(5, $files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            self::assertSame([], ThinControllerContractAnalyzer::violations($source), $file);
        }
    }

    #[DataProvider('forbiddenMutationProvider')]
    public function test_architecture_gate_rejects_each_forbidden_mutation(
        string $category,
        string $imports,
        string $body,
    ): void {
        $source = "<?php\n".$imports."\nfinal class Mutant { public function endpoint(): void { ".$body." } }";

        self::assertContains($category, ThinControllerContractAnalyzer::violations($source));
    }

    public static function forbiddenMutationProvider(): array
    {
        return [
            'aliased DB' => ['data_access', 'use Illuminate\\Support\\Facades\\DB as Sql;', 'Sql::table("projects");'],
            'FQCN Eloquent' => ['data_access', '', '\\App\\Models\\Project::query();'],
            'query builder' => ['data_access', 'use Illuminate\\Database\\Query\\Builder as Query;', 'Query::from("projects");'],
            'transaction' => ['transaction', 'use Illuminate\\Support\\Facades\\DB;', 'DB::transaction(fn () => null);'],
            'file service' => ['file_service', 'use App\\Services\\Storage\\FileService as Files;', 'Files::put("x");'],
            'static dispatch' => ['dispatch', 'use Illuminate\\Support\\Facades\\Bus;', 'Bus::dispatch(new \\stdClass());'],
            'dispatch function' => ['dispatch', '', 'dispatch(new \\stdClass());'],
            'container app' => ['container', '', 'app(\\stdClass::class);'],
            'container resolve' => ['container', '', 'resolve(\\stdClass::class);'],
            'container static' => ['container', 'use Illuminate\\Container\\Container as IoC;', 'IoC::getInstance()->make(\\stdClass::class);'],
            'direct json' => ['direct_json', '', 'response()->json(["ok" => true]);'],
            'six-key payload' => ['oversized_array', '', '$payload = ["a"=>1,"b"=>2,"c"=>3,"d"=>4,"e"=>5,"f"=>6];'],
            'second action' => ['action_calls', '', '$this->first->handle(); $this->second->handle();'],
            'second context' => ['context_calls', '', '$this->contexts->fromHttp($request); $this->contexts->fromHttp($request);'],
            'second resource' => [
                'resource_constructions',
                '',
                'new \\App\\BusinessModules\\Core\\Reporting\\Http\\Admin\\Resources\\ReportRunResource($run); new \\App\\BusinessModules\\Core\\Reporting\\Http\\Admin\\Resources\\ReportRunResource($run);',
            ],
            'second response' => [
                'admin_response_calls',
                '',
                '\\App\\Http\\Responses\\AdminResponse::success($run); \\App\\Http\\Responses\\AdminResponse::success($run);',
            ],
        ];
    }

    public static function actionSignatureProvider(): array
    {
        $root = 'App\\BusinessModules\\Core\\Reporting\\';
        $context = $root.'Domain\\DTO\\ReportExecutionContext';

        return [
            'catalog' => [$root.'Application\\Contracts\\GetReportCatalogAction', [$context], $root.'Domain\\DTO\\ReportCatalogView'],
            'create run' => [$root.'Application\\Contracts\\CreateReportRunAction', [$context, $root.'Application\\Input\\CreateReportRunData', $root.'Domain\\ValueObjects\\IdempotencyKey'], $root.'Domain\\DTO\\ReportRun'],
            'get run' => [$root.'Application\\Contracts\\GetReportRunAction', [$context, 'string'], $root.'Domain\\DTO\\ReportRun'],
            'rows' => [$root.'Application\\Contracts\\GetReportRowsAction', [$context, 'string', $root.'Domain\\DTO\\ReportRowsWindow'], $root.'Domain\\DTO\\ReportPage'],
            'drill-down' => [$root.'Application\\Contracts\\GetReportDrillDownAction', [$context, 'string', $root.'Domain\\DTO\\ReportDrillDownRequest'], $root.'Domain\\DTO\\ReportDrillDownResult'],
            'retry run' => [$root.'Application\\Contracts\\RetryReportRunAction', [$context, 'string'], $root.'Domain\\DTO\\ReportRun'],
            'cancel run' => [$root.'Application\\Contracts\\CancelReportRunAction', [$context, 'string'], $root.'Domain\\DTO\\ReportRun'],
            'create export' => [$root.'Application\\Contracts\\CreateReportExportAction', [$context, 'string', $root.'Application\\Input\\CreateReportExportData', $root.'Domain\\ValueObjects\\IdempotencyKey'], $root.'Domain\\DTO\\ReportExport'],
            'get export' => [$root.'Application\\Contracts\\GetReportExportAction', [$context, 'string'], $root.'Domain\\DTO\\ReportExport'],
            'retry export' => [$root.'Application\\Contracts\\RetryReportExportAction', [$context, 'string'], $root.'Domain\\DTO\\ReportExport'],
            'cancel export' => [$root.'Application\\Contracts\\CancelReportExportAction', [$context, 'string'], $root.'Domain\\DTO\\ReportExport'],
            'download' => [$root.'Application\\Contracts\\CreateReportDownloadLinkAction', [$context, $root.'Application\\Input\\CreateReportDownloadLinkData'], $root.'Domain\\DTO\\ReportDownloadLink'],
        ];
    }

    public static function controllerMethodProvider(): array
    {
        $controllers = 'App\\BusinessModules\\Core\\Reporting\\Http\\Admin\\Controllers\\';
        $requests = 'App\\BusinessModules\\Core\\Reporting\\Http\\Admin\\Requests\\';

        return [
            [$controllers.'ReportCatalogController', '__invoke', $requests.'GetReportCatalogRequest'],
            [$controllers.'ReportRunController', 'store', $requests.'CreateReportRunRequest'],
            [$controllers.'ReportRunController', 'show', $requests.'ReportRunRouteRequest'],
            [$controllers.'ReportRunController', 'retry', $requests.'ReportRunRouteRequest'],
            [$controllers.'ReportRunController', 'cancel', $requests.'ReportRunRouteRequest'],
            [$controllers.'ReportRowsController', '__invoke', $requests.'GetReportRowsRequest'],
            [$controllers.'ReportDrillDownController', '__invoke', $requests.'CreateReportDrillDownRequest'],
            [$controllers.'ReportExportController', 'store', $requests.'CreateReportExportRequest'],
            [$controllers.'ReportExportController', 'show', $requests.'ReportExportRouteRequest'],
            [$controllers.'ReportExportController', 'retry', $requests.'ReportExportRouteRequest'],
            [$controllers.'ReportExportController', 'cancel', $requests.'ReportExportRouteRequest'],
            [$controllers.'ReportExportController', 'downloadLink', $requests.'CreateReportDownloadLinkRequest'],
        ];
    }
}

final class ThinControllerContractAnalyzer
{
    public static function violations(string $source): array
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        if ($statements === null) {
            return ['parse'];
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $statements = $traverser->traverse($statements);
        $finder = new NodeFinder();
        $violations = [];

        foreach ($finder->findInstanceOf($statements, Node::class) as $node) {
            if ($node instanceof Array_ && count($node->items) > 5) {
                $violations[] = 'oversized_array';
            }

            if ($node instanceof FuncCall && $node->name instanceof Name) {
                $function = strtolower($node->name->toString());
                if (in_array($function, ['app', 'resolve'], true)) {
                    $violations[] = 'container';
                }
                if ($function === 'dispatch') {
                    $violations[] = 'dispatch';
                }
                if ($function === 'response') {
                    $violations[] = 'direct_json';
                }
            }

            if ($node instanceof MethodCall) {
                $method = strtolower(self::identifier($node->name));
                if ($method === 'transaction') {
                    $violations[] = 'transaction';
                }
                if ($method === 'dispatch') {
                    $violations[] = 'dispatch';
                }
                if (in_array($method, ['query', 'table', 'from'], true)) {
                    $violations[] = 'data_access';
                }
                if (in_array($method, ['make', 'get'], true)
                    && str_contains(strtolower(self::expressionName($node->var)), 'container')) {
                    $violations[] = 'container';
                }
            }

            if ($node instanceof StaticCall || $node instanceof New_) {
                $class = self::className($node->class);
                $lower = strtolower($class);
                $method = $node instanceof StaticCall ? strtolower(self::identifier($node->name)) : '';

                if (str_contains($lower, '\\database\\')
                    || str_contains($lower, '\\models\\')
                    || str_ends_with($lower, '\\db')
                    || in_array($method, ['query', 'table', 'from'], true)) {
                    $violations[] = 'data_access';
                }
                if ($method === 'transaction') {
                    $violations[] = 'transaction';
                }
                if (str_ends_with($lower, '\\fileservice')) {
                    $violations[] = 'file_service';
                }
                if ($method === 'dispatch') {
                    $violations[] = 'dispatch';
                }
                if (str_contains($lower, '\\container') && in_array($method, ['get', 'make', 'getinstance'], true)) {
                    $violations[] = 'container';
                }
            }
        }

        foreach ($finder->findInstanceOf($statements, ClassMethod::class) as $method) {
            if (!$method->isPublic() || $method->name->toString() === '__construct') {
                continue;
            }

            $nodes = $method->stmts ?? [];
            $contextCalls = count($finder->find(
                $nodes,
                static fn (Node $node): bool => $node instanceof MethodCall
                    && self::identifier($node->name) === 'fromHttp',
            ));
            $actionCalls = count($finder->find(
                $nodes,
                static fn (Node $node): bool => $node instanceof MethodCall
                    && self::identifier($node->name) === 'handle',
            ));
            $resources = count($finder->find(
                $nodes,
                static fn (Node $node): bool => $node instanceof New_
                    && str_contains(self::className($node->class), '\\Reporting\\Http\\Admin\\Resources\\'),
            ));
            $responses = count($finder->find(
                $nodes,
                static fn (Node $node): bool => $node instanceof StaticCall
                    && str_ends_with(self::className($node->class), '\\AdminResponse')
                    && self::identifier($node->name) === 'success',
            ));

            if ($contextCalls !== 1) {
                $violations[] = 'context_calls';
            }
            if ($actionCalls !== 1) {
                $violations[] = 'action_calls';
            }
            if ($resources !== 1) {
                $violations[] = 'resource_constructions';
            }
            if ($responses !== 1) {
                $violations[] = 'admin_response_calls';
            }
        }

        return array_values(array_unique($violations));
    }

    private static function className(Node|string $class): string
    {
        if (!$class instanceof Name) {
            return is_string($class) ? $class : '';
        }

        $resolved = $class->getAttribute('resolvedName');

        return $resolved instanceof Name ? '\\'.$resolved->toString() : '\\'.$class->toString();
    }

    private static function identifier(Node|string $name): string
    {
        return $name instanceof Node\Identifier ? $name->toString() : (is_string($name) ? $name : '');
    }

    private static function expressionName(Node $node): string
    {
        if ($node instanceof StaticCall) {
            return self::className($node->class);
        }

        return $node::class;
    }
}
