<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestInProgress;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AIAssistantController;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantExceptionBoundary;
use App\Models\User;
use App\Services\Credits\AICreditsNotReadyException;
use DomainException;
use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;
use Throwable;

final class OrdinaryAssistantFailureStatusTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    #[DataProvider('failures')]
    public function test_sanitizing_preserves_the_existing_controller_status(string $kind, int $expectedStatus): void
    {
        $private = 'offline source fragment canary';
        $original = match ($kind) {
            'in-progress' => new AssistantRequestInProgress,
            'credits' => new AICreditsNotReadyException(new RuntimeException($private)),
            'authentication' => new AuthenticationException($private, [$private], $private),
            'access-denied' => new AccessDeniedHttpException($private, new RuntimeException($private)),
            'not-found' => (new ModelNotFoundException)->setModel(User::class, [$private]),
            'runtime' => new RuntimeException($private, 0, new RuntimeException($private)),
            'domain' => new DomainException($private),
            default => new Exception($private, 0, new RuntimeException($private)),
        };
        self::assertSame($expectedStatus, $this->controllerStatus($original));
        $safe = OrdinaryAssistantExceptionBoundary::sanitize($original);
        self::assertSame($expectedStatus, $this->controllerStatus($safe));
        self::assertNull($safe->getPrevious());
        self::assertFalse(str_contains((string) $safe.json_encode(get_object_vars($safe), JSON_THROW_ON_ERROR), $private));
    }

    public static function failures(): array
    {
        return [['in-progress', 409], ['credits', 503], ['authentication', 401], ['access-denied', 403],
            ['not-found', 404], ['runtime', 422], ['domain', 422], ['sdk-error', 500]];
    }

    private function controllerStatus(Throwable $failure): int
    {
        app()->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new \Illuminate\Routing\ResponseFactory(
            $this->createMock(\Illuminate\Contracts\View\Factory::class), $this->createMock(\Illuminate\Routing\Redirector::class)));
        $controller = (new ReflectionClass(AIAssistantController::class))->newInstanceWithoutConstructor();
        $response = (new ReflectionMethod(AIAssistantController::class, 'respond'))->invoke($controller,
            Request::create('/api/v1/admin/ai-assistant/chat', 'POST'), static fn () => throw $failure);

        return $response->getStatusCode();
    }
}
