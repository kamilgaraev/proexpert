<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Requests\Api\V1\Admin\Contract\SaveContractLibraryItemRequest;
use App\Http\Requests\Api\V1\Admin\Contract\ChangeContractLibraryStateRequest;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class ContractLibraryRouteBindingTest extends TestCase
{
    public function test_library_routes_do_not_resolve_estimate_items(): void
    {
        $container = new Container();
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        $previous = Facade::getFacadeApplication();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        try {
            require dirname(__DIR__, 2).'/routes/api/v1/admin/contracts.php';
            $router->bind('item', static function (): never {
                throw new \LogicException('Estimate item binding intercepted a library request');
            });
            $router->bind('version', static function (): never {
                throw new \LogicException('Estimate version binding intercepted a library request');
            });
            $id = '3a25b8ad-e676-48b9-8050-bf22e7cdfa12';
            $routes = [
                ['GET', "{$id}/versions/1", 'show', 'view'],
                ['GET', "{$id}/versions/1/resolved", 'resolved', 'view'],
                ['POST', "{$id}/versions/1/calculate", 'calculate', 'view'],
                ['POST', "{$id}/versions", 'revise', 'create'],
                ['POST', "{$id}/versions/1/publish", 'publish', 'publish'],
                ['PATCH', "{$id}/archive", 'archive', 'archive'],
            ];
            foreach ($routes as [$method, $path, $action, $permission]) {
                $request = Request::create('/contract-library/'.$path, $method);
                $route = $router->getRoutes()->match($request);
                $request->setRouteResolver(static fn () => $route);
                self::assertSame('contracts.library.'.$action, $route->getName());
                self::assertContains('authorize:contracts.library.'.$permission, $route->middleware());
                (new SubstituteBindings($router))->handle($request, static function (Request $bound) use ($id): void {
                    self::assertSame($id, $bound->route('libraryItem'));
                    if (str_contains($bound->path(), '/versions/1')) {
                        self::assertSame('1', $bound->route('libraryVersion'));
                    }
                });
                if ($action === 'revise') {
                    $form = SaveContractLibraryItemRequest::createFrom($request);
                    self::assertSame('required', $form->rules()['expected_version'][0]);
                    self::assertSame('prohibited', $form->rules()['kind'][0]);
                }
                if (in_array($action, ['publish', 'archive'], true)) {
                    $form = ChangeContractLibraryStateRequest::createFrom($request);
                    self::assertSame($action === 'archive' ? 'required' : 'prohibited', $form->rules()['archived'][0]);
                }
            }
            $create = Request::create('/contract-library', 'POST');
            $route = $router->getRoutes()->match($create);
            $create->setRouteResolver(static fn () => $route);
            $form = SaveContractLibraryItemRequest::createFrom($create);
            self::assertSame('prohibited', $form->rules()['expected_version'][0]);
            self::assertSame('required', $form->rules()['kind'][0]);
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previous);
        }
    }
}
